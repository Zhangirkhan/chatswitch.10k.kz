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
            'text' => $this->sendText($whatsapp, $sessionName, $to),
            'media' => $this->sendMedia($whatsapp, $sessionName, $to),
            'poll' => $this->sendPoll($whatsapp, $sessionName, $to),
            'contact' => $this->sendContact($whatsapp, $sessionName, $to),
            default => ['success' => false, 'error' => 'Неизвестный тип исходящего сообщения.'],
        };

        if (($result['success'] ?? false) !== true) {
            $this->markFailed($message, (string) ($result['error'] ?? 'Ошибка отправки в WhatsApp.'));

            return;
        }

        $waId = (string) ($result['messageId'] ?? '');
        if ($waId === '') {
            $this->markFailed($message, 'WhatsApp вернул пустой идентификатор сообщения.');

            return;
        }

        $message->forceFill([
            'whatsapp_message_id' => $waId,
            'ack' => 'sent',
        ])->save();

        broadcast(new MessageAckUpdated($message->chat_id, $message->id, 'sent'));
    }

    /** @return array<string, mixed> */
    private function sendText(WhatsappService $whatsapp, string $sessionName, string $to): array
    {
        $body = (string) ($this->payload['body'] ?? '');
        $quotedRaw = $this->payload['quoted_message_id'] ?? null;
        $quotedId = is_string($quotedRaw) && $quotedRaw !== '' ? $quotedRaw : null;

        return $whatsapp->sendMessage($sessionName, $to, $body, $quotedId);
    }

    /** @return array<string, mixed> */
    private function sendMedia(WhatsappService $whatsapp, string $sessionName, string $to): array
    {
        $disk = (string) ($this->payload['disk'] ?? 'local');
        $relativePath = (string) ($this->payload['path'] ?? '');
        $mimetype = (string) ($this->payload['mimetype'] ?? 'application/octet-stream');
        $filename = isset($this->payload['filename']) ? (string) $this->payload['filename'] : null;
        $caption = isset($this->payload['caption']) ? (string) $this->payload['caption'] : null;
        $caption = $caption !== '' ? $caption : null;

        if ($relativePath === '') {
            return ['success' => false, 'error' => 'Не указан путь к файлу медиа.'];
        }

        $absolutePath = Storage::disk($disk)->path($relativePath);
        if (! is_file($absolutePath)) {
            return ['success' => false, 'error' => 'Файл медиа не найден на диске.'];
        }

        $asVoice = $message->type === 'voice';

        return $whatsapp->sendMediaFile($sessionName, $to, $absolutePath, $mimetype, $filename, $caption, $asVoice);
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
}
