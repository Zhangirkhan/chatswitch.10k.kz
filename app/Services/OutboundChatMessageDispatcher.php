<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\NewMessageReceived;
use App\Jobs\ProcessOutboundFunnelSignalJob;
use App\Jobs\SendOutboundMessageJob;
use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\AiDraftToneLearningService;
use App\Services\Knowledge\ProductMessageAttachmentService;
use App\Support\OperatorSignature;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Общая отправка исходящего текстового сообщения (веб и Mobile API v1).
 */
final class OutboundChatMessageDispatcher
{
    /**
     * Набор relations для сообщений после отправки и в broadcast.
     *
     * @return list<string>
     */
    public static function messageWithRelations(): array
    {
        return [
            'media',
            'sentByUser',
            'whatsappSession',
            'reactions.user:id,name',
            'quotedMessage:id,whatsapp_message_id,direction,type,body,sender_name,sender_phone,sent_by_user_id',
            'quotedMessage.sentByUser:id,name',
            'quotedMessage.media:id,message_id,mime_type,filename',
        ];
    }

    public function __construct(
        private readonly ChatService $chatService,
        private readonly ProductMessageAttachmentService $productAttachments,
        private readonly AiDraftToneLearningService $draftToneLearning,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *                                       Keys: message, display_message?, quoted_message_id?, mentions?, mentions_meta?, metadata?, product_id?
     */
    public function sendTextMessage(User $user, Chat $chat, array $input): OutboundTextSendResult
    {
        $chat->load('whatsappSession');
        $session = $chat->whatsappSession;
        $quotedMessageId = $input['quoted_message_id'] ?? null;
        $text = (string) ($input['message'] ?? '');
        $displayText = (string) ($input['display_message'] ?? '');
        if (trim($displayText) === '') {
            $displayText = $text;
        }

        $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
        if (isset($input['product_id']) && $input['product_id'] !== null && $input['product_id'] !== '') {
            $product = $this->productAttachments->findForChat($chat, (int) $input['product_id']);
            if ($product === null) {
                throw ValidationException::withMessages([
                    'product_id' => ['Товар не найден или недоступен для этого чата.'],
                ]);
            }
            $metadata['product'] = $this->productAttachments->snapshot($product);
        }

        $productSnapshot = is_array($metadata['product'] ?? null) ? $metadata['product'] : null;
        if (trim($displayText) === '' && $productSnapshot !== null) {
            $displayText = $this->productAttachments->whatsappCaptionFallback($productSnapshot);
        }
        if (trim($text) === '' && $productSnapshot !== null) {
            $text = $displayText;
        }
        if (trim($text) === '' && trim($displayText) === '') {
            throw ValidationException::withMessages([
                'message' => ['Введите текст сообщения или прикрепите товар.'],
            ]);
        }
        $mentionsRaw = $input['mentions'] ?? [];
        $mentionsMetaRaw = $input['mentions_meta'] ?? [];
        $mentions = is_array($mentionsRaw)
            ? array_values(array_filter(array_map(
                static fn ($m) => is_string($m) ? $m : null,
                $mentionsRaw
            )))
            : [];
        $mentionsMeta = [];
        if (is_array($mentionsMetaRaw)) {
            foreach ($mentionsMetaRaw as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = isset($row['id']) ? trim((string) $row['id']) : '';
                $number = isset($row['number']) ? preg_replace('/\D/', '', (string) $row['number']) : '';
                $label = isset($row['label']) ? trim((string) $row['label']) : '';
                if ($id === '' || $number === '' || $label === '') {
                    continue;
                }
                $mentionsMeta[] = [
                    'id' => $id,
                    'number' => $number,
                    'label' => $label,
                ];
            }
        }

        if ($mentions === []) {
            $found = [];
            if (preg_match_all('/(^|\s)@(\d{5,20})\b/u', $text, $m)) {
                /** @var array<int, string> $nums */
                $nums = $m[2] ?? [];
                foreach ($nums as $n) {
                    $n = preg_replace('/\D/', '', (string) $n);
                    if ($n !== '') {
                        $found[] = $n;
                    }
                }
            }
            if ($found !== []) {
                $mentions = array_values(array_unique($found));
            }
        }

        try {
            Log::info('chat.sendMessage mention debug', [
                'chat_id' => $chat->id,
                'is_group' => (bool) $chat->is_group,
                'mentions_raw_type' => gettype($mentionsRaw),
                'mentions_raw_n' => is_array($mentionsRaw) ? count($mentionsRaw) : null,
                'mentions_before_norm_n' => count($mentions),
                'text_has_at' => str_contains($text, '@'),
                'text_preview' => mb_substr(preg_replace('/\s+/u', ' ', $text) ?: '', 0, 120),
            ]);
        } catch (\Throwable $e) {
            // ignore
        }

        $mentions = array_values(array_filter(array_map(
            static function ($m): ?string {
                if (! is_string($m)) {
                    return null;
                }
                $m = trim($m);
                if ($m === '') {
                    return null;
                }
                if (str_contains($m, '@')) {
                    return $m;
                }

                return preg_replace('/\D/', '', $m).'@c.us';
            },
            $mentions
        )));

        $skipSignature = data_get($metadata, 'ai.generated') === true
            && $user->email === (string) config('accel.system_user_email', 'system@chatswitch.internal');
        $signedText = $skipSignature ? $text : OperatorSignature::prepend($user, $text);
        $signedDisplayText = $skipSignature ? $displayText : OperatorSignature::prepend($user, $displayText);
        $waText = $signedText;
        if ($productSnapshot !== null) {
            $waText = $this->productAttachments->appendToWhatsappBody($signedText, $productSnapshot);
        }

        $message = $this->chatService->storeOutboundMessage(
            $chat,
            $session,
            $user,
            $signedDisplayText,
            null,
            is_string($quotedMessageId) ? $quotedMessageId : null,
            $metadata !== [] ? $metadata : null,
        );

        if ($mentionsMeta !== []) {
            $meta = is_array($message->metadata) ? $message->metadata : [];
            $meta['mentions'] = array_slice($mentionsMeta, 0, 20);
            $message->forceFill(['metadata' => $meta])->saveQuietly();
        }

        $message->load(self::messageWithRelations());
        broadcast(new NewMessageReceived($message, $chat->id));

        $imagePath = is_string($productSnapshot['image_path'] ?? null) ? $productSnapshot['image_path'] : '';
        if ($imagePath !== '' && Storage::disk('public')->exists($imagePath)) {
            $mime = $this->productAttachments->publicImageMimeType($imagePath);
            $filename = basename($imagePath);
            $caption = trim($waText) !== '' ? $waText : null;

            SendOutboundMessageJob::dispatch(
                $message->id,
                'media',
                [
                    'disk' => 'public',
                    'path' => $imagePath,
                    'mimetype' => $mime,
                    'filename' => $filename,
                    'caption' => $caption,
                ],
            );
        } else {
            SendOutboundMessageJob::dispatch(
                $message->id,
                'text',
                [
                    'body' => $waText,
                    'quoted_message_id' => $quotedMessageId,
                    'mentions' => $mentions,
                ],
            );
        }

        ProcessOutboundFunnelSignalJob::dispatch($message->id);

        $draftEditKind = $this->draftToneLearning->learnFromOutbound($user, $chat, $signedDisplayText);

        return new OutboundTextSendResult(
            $message,
            $draftEditKind !== null,
            $draftEditKind,
        );
    }
}
