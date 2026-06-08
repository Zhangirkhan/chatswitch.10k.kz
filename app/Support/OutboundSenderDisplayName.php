<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Chat;
use App\Models\Message;
use App\Models\User;

/**
 * Подпись отправителя для исходящих сообщений в API/mobile (без WhatsApp-разметки).
 */
final class OutboundSenderDisplayName
{
    public static function resolve(User $user, ?Chat $chat = null, ?array $metadata = null): string
    {
        if (data_get($metadata, 'ai.generated') === true) {
            return self::forAi($user, $chat, $metadata);
        }

        return OperatorSignature::plainLabel($user);
    }

    public static function forMessage(Message $message): ?string
    {
        if ($message->direction !== 'outbound') {
            $name = trim((string) ($message->sender_name ?? ''));

            return $name !== '' ? $name : null;
        }

        if (data_get($message->metadata, 'ai.generated') === true) {
            $message->loadMissing(['sentByUser', 'chat.company']);

            return self::forAi(
                $message->sentByUser ?? new User(['name' => '']),
                $message->chat,
                is_array($message->metadata) ? $message->metadata : null,
            );
        }

        $stored = trim((string) ($message->sender_name ?? ''));
        if ($stored !== '' && self::looksLikeDisplayLabel($stored)) {
            return $stored;
        }

        $message->loadMissing('sentByUser');
        if ($message->sentByUser !== null) {
            return OperatorSignature::plainLabel($message->sentByUser);
        }

        $fromBody = self::extractFromBodySignature((string) ($message->body ?? ''));
        if ($fromBody !== null) {
            return $fromBody;
        }

        $fromMetadata = trim((string) data_get($message->metadata, 'sender_display_name', ''));
        if ($fromMetadata !== '') {
            return $fromMetadata;
        }

        $fromMetadata = trim((string) data_get($message->metadata, 'sender_label', ''));
        if ($fromMetadata !== '') {
            return $fromMetadata;
        }

        return $stored !== '' ? $stored : null;
    }

    /**
     * @return array{id: int|null, name: string, role: string|null, label: string}|null
     */
    public static function senderPayload(Message $message): ?array
    {
        if ($message->direction !== 'outbound') {
            return null;
        }

        $label = self::forMessage($message);
        if ($label === null || $label === '') {
            return null;
        }

        $message->loadMissing('sentByUser');
        $isAi = data_get($message->metadata, 'ai.generated') === true;

        return [
            'id' => $message->sentByUser?->id,
            'name' => trim((string) ($message->sentByUser?->name ?? '')) !== ''
                ? (string) $message->sentByUser?->name
                : $label,
            'role' => $isAi ? 'AI' : OperatorSignature::roleLabel($message->sentByUser),
            'label' => $label,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private static function forAi(User $user, ?Chat $chat, ?array $metadata): string
    {
        $aiMeta = is_array($metadata['ai'] ?? null) ? $metadata['ai'] : [];
        if (($aiMeta['reply_as_company'] ?? false) === true) {
            $chat?->loadMissing('company');
            $companyName = trim((string) ($chat?->company?->name ?? ''));

            return $companyName !== '' ? "{$companyName} (AI)" : 'AI';
        }

        $name = trim($user->name);

        return $name !== '' ? "{$name} (AI)" : 'AI';
    }

    private static function looksLikeDisplayLabel(string $value): bool
    {
        return preg_match('/\([^)]+\)/u', $value) === 1;
    }

    private static function extractFromBodySignature(string $body): ?string
    {
        $trimmed = ltrim($body);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^\*([^*\n]+)\*\R?/u', $trimmed, $match) !== 1) {
            return null;
        }

        $label = trim($match[1]);

        return $label !== '' ? $label : null;
    }
}
