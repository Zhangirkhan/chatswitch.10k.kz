<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\WhatsappSession;

final class WhatsappSessionStatusHints
{
    public static function forSession(WhatsappSession $session): string
    {
        return match ($session->status) {
            'connected' => 'Подключён и принимает сообщения.',
            'connecting' => 'Идёт подключение к WhatsApp…',
            'qr_pending' => self::qrPendingHint($session),
            'disconnected' => self::disconnectedHint($session),
            default => 'Статус подключения неизвестен.',
        };
    }

    private static function qrPendingHint(WhatsappSession $session): string
    {
        if ($session->qr_required_at !== null) {
            return 'Требуется повторное сканирование QR-кода в настройках подключений.';
        }

        return 'Отсканируйте QR-код в настройках подключений.';
    }

    private static function disconnectedHint(WhatsappSession $session): string
    {
        if ($session->last_auth_failure_message !== null && $session->last_auth_failure_message !== '') {
            return 'Ошибка авторизации WhatsApp. Подключите номер заново и отсканируйте QR.';
        }

        $reason = trim((string) ($session->last_disconnect_reason ?? ''));
        if ($reason !== '') {
            return match ($reason) {
                'LOGOUT' => 'WhatsApp разлогинил связанное устройство. Отсканируйте QR в настройках.',
                'NAVIGATION' => 'Сессия WhatsApp прервана. Система попробует восстановить подключение.',
                'CONFLICT' => 'Конфликт сессии WhatsApp. Отсканируйте QR в настройках.',
                'UNLAUNCHED', 'UNPAIRED' => 'Сессия WhatsApp не активна. Отсканируйте QR в настройках.',
                default => 'Подключение потеряно ('.$reason.'). Откройте настройки подключений.',
            };
        }

        if ($session->desired_state === WhatsappSession::DESIRED_LOGGED_OUT) {
            return 'Номер отключён вручную. Нажмите «Подключить» для повторной привязки.';
        }

        return 'Подключение потеряно. Система попробует восстановить его автоматически.';
    }
}
