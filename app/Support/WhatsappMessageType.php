<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Классификация типов сообщений WhatsApp для UI и списков чатов.
 */
final class WhatsappMessageType
{
    /** @var list<string> */
    private const IGNORED_INBOUND_TYPES = [
        'e2e_notification',
        'protocol',
        'gp2',
        'notification',
        'notification_template',
        'broadcast_notification',
        'call_log',
        'ciphertext',
        'debug',
        'hsm',
    ];

    public static function shouldIgnoreInbound(?string $type): bool
    {
        $normalized = strtolower(trim((string) $type));

        return $normalized !== '' && in_array($normalized, self::IGNORED_INBOUND_TYPES, true);
    }

    public static function isOperatorVisible(?string $type, ?string $direction = null): bool
    {
        if ($direction === 'system') {
            return false;
        }

        return ! self::shouldIgnoreInbound($type);
    }

    /**
     * @param  Builder<\App\Models\Message>  $query
     */
    public static function applyOperatorVisibleScope(Builder $query): void
    {
        $query
            ->where(function (Builder $scope): void {
                $scope->whereNull('type')
                    ->orWhereNotIn('type', self::IGNORED_INBOUND_TYPES);
            })
            ->where('direction', '!=', 'system');
    }
}
