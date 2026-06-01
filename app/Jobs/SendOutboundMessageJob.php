<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\MessageAckUpdated;
use App\Models\Message;
use App\Services\WhatsappService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

final class SendOutboundMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 15, 30, 60, 120];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $messageId,
        public readonly string $payloadType,
        public readonly array $payload,
    ) {}

    public function viaQueue(): string
    {
        return 'whatsapp';
    }

    public function handle(WhatsappService $whatsapp): void
    {
        $message = Message::query()
            ->whereKey($this->messageId)
            ->with(['chat', 'whatsappSession', 'media'])
            ->first();

        if ($message === null || $message->direction !== 'outbound') {
            return;
        }

        if ($message->whatsapp_message_id !== null && $message->ack !== 'pending' && $message->ack !== 'failed') {
            return;
        }

        $session = $message->whatsappSession;
        $chat = $message->chat;
        if ($session === null || $chat === null || blank($chat->whatsapp_chat_id)) {
            $this->markFailed($message, 'Нет сессии или WhatsApp-идентификатора чата.');

            return;
        }

        $sessionName = (string) $session->session_name;
        $to = (string) $chat->whatsapp_chat_id;

        $result = match ($this->payloadType) {
            'forward' => $this->sendForward($whatsapp, $sessionName, $to),
            'text' => $this->sendText($whatsapp, $sessionName, $to),
            'media' => $this->sendMedia($whatsapp, $message, $sessionName, $to),
            'poll' => $this->sendPoll($whatsapp, $sessionName, $to),
            'contact' => $this->sendContact($whatsapp, $sessionName, $to),
            default => ['success' => false, 'error' => 'Неизвестный тип исходящего сообщения.'],
        };

        if (($result['success'] ?? false) !== true) {
            $reason = (string) ($result['error'] ?? 'Ошибка отправки в WhatsApp.');
            if ($this->isRetryableTransportError($reason) && $this->attempts() < $this->tries) {
                try {
                    // Best-effort warm-up when puppeteer frame was detached or client is reconnecting.
                    $whatsapp->initializeSession($sessionName, (int) $session->company_id);
                } catch (\Throwable) {
                    // Ignore: retry path below is the actual recovery mechanism.
                }
                throw new RuntimeException($reason);
            }
            $this->markFailed($message, $reason);

            return;
        }

        $waId = (string) ($result['messageId'] ?? '');
        if ($waId === '') {
            if ($this->payloadType === 'forward') {
                // wwebjs message.forward() может вернуть boolean без id, но пересылка фактически доставляется.
                $message->forceFill([
                    'ack' => 'sent',
                ])->save();
            } else {
                $this->markFailed($message, 'WhatsApp вернул пустой идентификатор сообщения.');

                return;
            }
        } else {
            $message->forceFill([
                'whatsapp_message_id' => $waId,
                'ack' => 'sent',
            ])->save();
        }

        broadcast(new MessageAckUpdated($message->chat_id, $message->id, 'sent'));

        // If operator reacted before WA id was known, sync that reaction now.
        $pendingReaction = $message->reactions()
            ->whereNotNull('user_id')
            ->where('pending_whatsapp_sync', true)
            ->orderByDesc('updated_at')
            ->first();
        if ($pendingReaction) {
            SyncMessageReactionToWhatsappJob::dispatch($pendingReaction->id);
        }
    }

    /** @return array<string, mixed> */
    private function sendText(WhatsappService $whatsapp, string $sessionName, string $to): array
    {
        $body = trim((string) ($this->payload['body'] ?? ''));
        if ($body === '') {
            return ['success' => false, 'error' => 'Пустой текст — WhatsApp не примет сообщение.'];
        }
        $quotedRaw = $this->payload['quoted_message_id'] ?? null;
        $quotedId = is_string($quotedRaw) && $quotedRaw !== '' ? $quotedRaw : null;
        $mentionsRaw = $this->payload['mentions'] ?? [];
        $mentions = is_array($mentionsRaw)
            ? array_values(array_filter(array_map(
                static fn ($m) => is_string($m) ? $m : null,
                $mentionsRaw,
            )))
            : [];

        try {
            Log::info('wa.sendText mentions debug', [
                'message_id' => $this->messageId,
                'to' => $to,
                'mentions_n' => count($mentions),
                'mentions_first' => array_slice($mentions, 0, 3),
            ]);
        } catch (\Throwable $e) {
            // ignore logging failures
        }

        return $whatsapp->sendMessage($sessionName, $to, $body, $quotedId, $mentions);
    }

    /** @return array<string, mixed> */
    private function sendForward(WhatsappService $whatsapp, string $sessionName, string $to): array
    {
        $sourceId = trim((string) ($this->payload['source_whatsapp_message_id'] ?? ''));
        if ($sourceId === '') {
            return ['success' => false, 'error' => 'Не указан source_whatsapp_message_id для пересылки.'];
        }

        return $whatsapp->forwardMessage($sessionName, $to, $sourceId);
    }

    /** @return array<string, mixed> */
    private function sendMedia(WhatsappService $whatsapp, Message $message, string $sessionName, string $to): array
    {
        $disk = (string) ($this->payload['disk'] ?? 'local');
        $relativePath = (string) ($this->payload['path'] ?? '');
        $mimetype = (string) ($this->payload['mimetype'] ?? 'application/octet-stream');
        $filename = isset($this->payload['filename']) ? (string) $this->payload['filename'] : null;
        $filenameLower = $filename !== null ? strtolower($filename) : '';
        $caption = isset($this->payload['caption']) ? (string) $this->payload['caption'] : null;
        $caption = $caption !== '' ? $caption : null;
        // Подпись оператора как caption у голосовых ломает медиа на стороне WhatsApp («аудио недоступно»).
        if ($message->type === 'voice') {
            $caption = null;
        }

        if ($relativePath === '') {
            return ['success' => false, 'error' => 'Не указан путь к файлу медиа.'];
        }

        $absolutePath = Storage::disk($disk)->path($relativePath);
        if (! is_file($absolutePath)) {
            return ['success' => false, 'error' => 'Файл медиа не найден на диске.'];
        }

        // Браузерный MediaRecorder → .webm; finfo часто даёт application/octet-stream — тогда без проверки
        // расширения снова включается sendAudioAsVoice и WA Web падает с нечитаемой ошибкой.
        $mimeBase = strtolower(trim(explode(';', $mimetype, 2)[0]));
        $looksWebm = str_contains($mimeBase, 'webm')
            || ($filenameLower !== '' && str_ends_with($filenameLower, '.webm'));
        if ($looksWebm && ! str_contains(strtolower($mimetype), 'webm')) {
            $mimetype = 'audio/webm';
        }

        $sendPath = $absolutePath;
        $sendMime = $mimetype;
        $sendFilename = $filename;
        $tmpOgg = null;

        if ($message->type === 'voice' && $looksWebm) {
            $tmpOgg = $this->transcodeWebmToOpusOgg($absolutePath);
            if ($tmpOgg !== null) {
                $sendPath = $tmpOgg;
                // Без «codecs=opus» в строке — часть стеков WA/multer хуже переваривает параметр в multipart.
                $sendMime = 'audio/ogg';
                $sendFilename = $filename !== null && str_ends_with(strtolower($filename), '.webm')
                    ? (string) preg_replace('/\.webm$/i', '.ogg', $filename)
                    : 'voice.ogg';
            }
        }

        // sendAudioAsVoice (PTT) через wwebjs: sendMessage() = 200, но на телефонах часто «аудио недоступно».
        // Обычное вложение audio/ogg воспроизводится стабильно (визуально — аудиофайл, не «кружок»).
        try {
            return $whatsapp->sendMediaFile($sessionName, $to, $sendPath, $sendMime, $sendFilename, $caption, false);
        } finally {
            if ($tmpOgg !== null && is_file($tmpOgg)) {
                @unlink($tmpOgg);
            }
        }
    }

    private function transcodeWebmToOpusOgg(string $webmPath): ?string
    {
        $dir = storage_path('app/tmp');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return null;
        }

        $out = $dir.'/'.uniqid('wa-voice-', true).'.ogg';
        // Параметры как у нативных голосовых WhatsApp (см. wwebjs #5683 / обсуждения Opus PTT).
        $process = new Process([
            'ffmpeg',
            '-nostdin',
            '-hide_banner',
            '-loglevel', 'error',
            '-y',
            '-i',
            $webmPath,
            '-vn',
            '-c:a',
            'libopus',
            '-b:a',
            '16k',
            '-ac',
            '1',
            '-ar',
            '16000',
            '-application',
            'voip',
            '-map_metadata',
            '-1',
            $out,
        ]);
        $process->setTimeout(120);

        try {
            $process->mustRun();
        } catch (\Throwable) {
            @unlink($out);

            return null;
        }

        if (! is_file($out) || filesize($out) < 32) {
            @unlink($out);

            return null;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function sendPoll(WhatsappService $whatsapp, string $sessionName, string $to): array
    {
        $question = trim((string) ($this->payload['question'] ?? ''));
        $options = $this->payload['options'] ?? [];
        if (! is_array($options)) {
            return ['success' => false, 'error' => 'Некорректные варианты опроса.'];
        }

        $options = array_values(array_filter(
            array_map(static fn ($o) => trim((string) $o), $options),
            static fn (string $o) => $o !== '',
        ));

        if ($question === '' || count($options) < 2) {
            return ['success' => false, 'error' => 'Некорректный опрос.'];
        }

        $allowMultiple = (bool) ($this->payload['allow_multiple'] ?? false);

        return $whatsapp->sendPoll($sessionName, $to, $question, $options, $allowMultiple);
    }

    /** @return array<string, mixed> */
    private function sendContact(WhatsappService $whatsapp, string $sessionName, string $to): array
    {
        $vcard = (string) ($this->payload['vcard'] ?? '');
        if ($vcard === '') {
            return ['success' => false, 'error' => 'Пустой vCard.'];
        }

        $displayName = isset($this->payload['display_name']) ? (string) $this->payload['display_name'] : null;
        $displayName = $displayName !== '' ? $displayName : null;

        return $whatsapp->sendContact($sessionName, $to, $vcard, $displayName);
    }

    private function markFailed(Message $message, string $reason): void
    {
        Log::warning('[whatsapp-outbound] send failed', [
            'message_id' => $message->id,
            'chat_id' => $message->chat_id,
            'payload_type' => $this->payloadType,
            'reason' => $reason,
        ]);

        $message->forceFill(['ack' => 'failed'])->save();

        broadcast(new MessageAckUpdated($message->chat_id, $message->id, 'failed'));
    }

    private function isRetryableTransportError(string $reason): bool
    {
        $r = mb_strtolower(trim($reason));
        if ($r === '') {
            return false;
        }

        return str_contains($r, 'detached frame')
            || str_contains($r, 'client not ready')
            || str_contains($r, 'target closed')
            || str_contains($r, 'execution context was destroyed')
            || str_contains($r, 'navigation')
            || str_contains($r, 'timed out')
            || str_contains($r, 'timeout');
    }
}
