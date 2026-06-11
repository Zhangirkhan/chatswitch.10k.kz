<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

final class ChatBroadcastAudience
{
    /**
     * Пользователи, которые должны получать события списка чатов и чат-канал.
     *
     * Логика уведомлений:
     * • Если за чатом закреплены специалисты (ChatAssignment) →
     *     уведомляем только их + их менеджеров + администраторов.
     * • Если никто не закреплён →
     *     уведомляем ВСЕХ сотрудников + администраторов
     *     (пришёл новый клиент — хочет, чтобы кто-нибудь ответил).
     *
     * @return list<int>
     */
    public static function userIdsWithAccessToChat(Chat $chat): array
    {
        $admins = self::userIdsWithRole('administrator');
        $assigned = ChatAssignment::where('chat_id', $chat->id)->pluck('user_id')->all();

        if ($assigned !== []) {
            // ── Есть назначенные специалисты ────────────────────────────────
            // Уведомляем только их + менеджеров их отделов + администраторов.
            $assignedDeptIds = DB::table('department_user')
                ->whereIn('user_id', $assigned)
                ->pluck('department_id')
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->values()
                ->all();

            $managerIds = [];
            if ($assignedDeptIds !== []) {
                $managerIds = User::query()
                    ->whereIn('id', self::userIdsWithRole('manager'))
                    ->whereHas('departments', fn ($q) => $q->whereIn('departments.id', $assignedDeptIds))
                    ->pluck('id')
                    ->all();
            }

            return array_values(array_unique(array_merge($admins, $assigned, $managerIds)));
        }

        // ── Никто не назначен → уведомляем всех сотрудников ────────────────
        // Это стандартный входящий чат без ответственного — любой может взять его в работу.
        $allEmployeeIds = User::whereDoesntHave(
            'roles',
            fn ($q) => $q->where('name', 'administrator'),
        )->pluck('id')->all();

        return array_values(array_unique(array_merge($admins, $allEmployeeIds)));
    }

    /**
     * @return list<int>
     */
    private static function userIdsWithRole(string $roleName): array
    {
        $roleExists = Role::query()
            ->where('name', $roleName)
            ->where('guard_name', 'web')
            ->exists();

        if (! $roleExists) {
            return [];
        }

        return User::role($roleName)->pluck('id')->all();
    }

    /**
     * Абсолютный URL для иконки уведомления (HTTPS).
     */
    public static function absoluteIconUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
            return $url;
        }

        $absolute = url($url);

        return $absolute !== '' ? $absolute : null;
    }

    public static function chatDisplayName(Chat $chat): string
    {
        $chat->loadMissing(['contact', 'whatsappSession']);

        $chatName = trim((string) ($chat->chat_name ?? ''));
        if ($chatName !== '') {
            return $chatName;
        }

        $contact = $chat->contact;
        if ($contact !== null) {
            $contactName = trim((string) ($contact->name ?? ''));
            if ($contactName !== '') {
                return $contactName;
            }
            $phone = trim((string) ($contact->phone_number ?? ''));
            if ($phone !== '') {
                return $phone;
            }
        }

        $session = $chat->whatsappSession;
        if ($session !== null) {
            $dn = trim((string) ($session->display_name ?? ''));
            if ($dn !== '') {
                return $dn;
            }
            $sp = trim((string) ($session->phone_number ?? ''));
            if ($sp !== '') {
                return $sp;
            }
        }

        return 'Чат #'.$chat->id;
    }

    /**
     * @return array{title: string, body: string, icon: ?string, chat_id: int, is_muted: bool}
     */
    public static function payloadForNewMessage(Chat $chat, Message $message): array
    {
        $chat->loadMissing(['contact', 'whatsappSession']);
        $message->loadMissing(['sentByUser']);

        $icon = self::absoluteIconUrl($chat->contact?->profile_picture_url);

        $preview = self::messagePreview($message);
        // Push: заголовок — имя чата/клиента, текст — само сообщение (для входящих от клиента).
        if ($message->direction === 'inbound') {
            $body = $preview;
        } else {
            $sender = self::senderLabel($message);
            $body = $sender !== '' ? $sender.': '.$preview : $preview;
        }

        return [
            'title' => self::chatDisplayName($chat),
            'body' => $body,
            'icon' => $icon,
            'chat_id' => $chat->id,
            'is_muted' => (bool) $chat->is_muted,
        ];
    }

    private static function senderLabel(Message $message): string
    {
        if ($message->direction === 'outbound') {
            return trim((string) ($message->sentByUser?->name ?? $message->sender_name ?? ''));
        }

        return trim((string) ($message->sender_name ?? $message->sender_phone ?? ''));
    }

    private static function messagePreview(Message $message): string
    {
        $body = trim((string) $message->body);
        $type = (string) $message->type;

        if ($type === 'chat') {
            return Str::limit($body !== '' ? $body : 'Сообщение', 240);
        }

        $duration = null;
        $meta = $message->metadata;
        if (is_array($meta) && isset($meta['media']['duration']) && is_numeric($meta['media']['duration'])) {
            $duration = max(0, (int) $meta['media']['duration']);
        }

        $caption = $body !== '' ? $body : null;
        $preview = MediaType::previewText($type, $caption);
        if ($duration !== null && $duration > 0 && in_array($type, ['voice', 'ptt', 'audio'], true)) {
            $m = intdiv($duration, 60);
            $s = $duration % 60;

            return $preview.' ('.$m.':'.str_pad((string) $s, 2, '0', STR_PAD_LEFT).')';
        }

        return Str::limit($preview, 240);
    }
}
