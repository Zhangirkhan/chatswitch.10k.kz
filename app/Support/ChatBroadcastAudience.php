<?php

declare(strict_types=1);

namespace App\Support;

use App\Events\NewMessageReceived;
use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Str;

final class ChatBroadcastAudience
{
    /**
     * Пользователи, которые должны получать события списка чатов и чат-канал
     * (совпадает с логикой {@see NewMessageReceived}).
     *
     * @return list<int>
     */
    public static function userIdsWithAccessToChat(Chat $chat): array
    {
        $admins = User::role('administrator')->pluck('id')->all();
        $assigned = ChatAssignment::where('chat_id', $chat->id)->pluck('user_id')->all();

        $chatDepartmentIds = $chat->departments()->pluck('departments.id')->all();
        $assignedDepartmentIds = [];
        if ($assigned !== []) {
            $assignedDepartmentIds = User::whereIn('id', $assigned)
                ->whereNotNull('department_id')
                ->pluck('department_id')
                ->unique()
                ->all();
        }

        $supervisorDepartmentIds = array_values(array_unique(array_merge($chatDepartmentIds, $assignedDepartmentIds)));
        $managerIds = [];
        if ($supervisorDepartmentIds !== []) {
            $managerIds = User::role('manager')
                ->whereIn('department_id', $supervisorDepartmentIds)
                ->pluck('id')
                ->all();
        }

        $departmentMemberIds = [];
        if ($chatDepartmentIds !== [] && $assigned === []) {
            $departmentMemberIds = User::whereIn('department_id', $chatDepartmentIds)
                ->pluck('id')
                ->all();
        }

        return array_values(array_unique(array_merge(
            $admins,
            $assigned,
            $managerIds,
            $departmentMemberIds,
        )));
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

        $sender = self::senderLabel($message);
        $preview = self::messagePreview($message);
        $body = $sender !== '' ? $sender.': '.$preview : $preview;

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
