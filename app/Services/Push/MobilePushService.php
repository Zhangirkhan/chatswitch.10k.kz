<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Jobs\SendMobilePushJob;
use App\Models\Chat;
use App\Models\Message;
use App\Models\TeamMessage;
use App\Models\UserDevice;
use App\Support\ChatBroadcastAudience;
use Illuminate\Support\Str;

final class MobilePushService
{
    public function isEnabled(): bool
    {
        return (bool) config('services.firebase.enabled', false);
    }

    /**
     * @param  list<int>  $userIds
     * @param  array<string, string>  $data
     * @param  list<int>  $excludeUserIds
     */
    public function dispatchToUsers(array $userIds, array $data, array $excludeUserIds = []): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $exclude = array_fill_keys(array_map(intval(...), $excludeUserIds), true);
        $recipients = array_values(array_unique(array_filter(
            array_map(intval(...), $userIds),
            static fn (int $id): bool => $id > 0 && ! isset($exclude[$id]),
        )));

        if ($recipients === []) {
            return;
        }

        SendMobilePushJob::dispatch($recipients, $this->stringifyData($data));
    }

    public function notifyClientMessage(Message $message, Chat $chat): void
    {
        if ($message->direction !== 'inbound' || (bool) $chat->is_muted) {
            return;
        }

        $chat->loadMissing(['contact', 'whatsappSession', 'company']);
        $desktop = ChatBroadcastAudience::payloadForNewMessage($chat, $message);
        $tenantSlug = (string) ($chat->company?->slug ?? '');

        $this->dispatchToUsers(
            ChatBroadcastAudience::userIdsWithAccessToChat($chat),
            [
                'kind' => 'client_message',
                'chat_id' => (string) $chat->id,
                'contact_id' => (string) ($chat->contact_id ?? ''),
                'title' => $desktop['title'],
                'body' => $desktop['body'],
                'message_id' => (string) $message->id,
                'tenant_slug' => $tenantSlug,
            ],
        );
    }

    public function notifyTeamMessage(TeamMessage $message): void
    {
        $message->loadMissing(['sender:id,name', 'conversation.participants']);
        $conversation = $message->conversation;
        if ($conversation === null) {
            return;
        }

        $senderId = (int) ($message->sender_id ?? 0);
        $recipientIds = $conversation->participants()
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $senderName = trim((string) ($message->sender?->name ?? 'Команда'));
        $preview = $this->teamMessagePreview($message);

        $this->dispatchToUsers(
            $recipientIds,
            [
                'kind' => 'team_message',
                'conversation_id' => (string) $conversation->id,
                'title' => 'Команда · '.$senderName,
                'body' => $preview,
                'message_id' => (string) $message->id,
            ],
            excludeUserIds: $senderId > 0 ? [$senderId] : [],
        );
    }

    /**
     * @param  list<int>  $assigneeUserIds
     */
    public function notifyChatAssigned(Chat $chat, array $assigneeUserIds): void
    {
        if ((bool) $chat->is_muted) {
            return;
        }

        $chat->loadMissing(['contact', 'whatsappSession']);
        $name = ChatBroadcastAudience::chatDisplayName($chat);

        $this->dispatchToUsers(
            $assigneeUserIds,
            [
                'kind' => 'chat_assigned',
                'chat_id' => (string) $chat->id,
                'title' => 'Новый чат',
                'body' => 'Вам назначен чат с '.$name,
            ],
        );
    }

    /**
     * @param  list<int>  $recipientUserIds
     */
    public function notifyLeadUpdate(Chat $chat, string $kind, array $recipientUserIds): void
    {
        if (! in_array($kind, ['lead_closed', 'lead_reopened'], true) || (bool) $chat->is_muted) {
            return;
        }

        $chat->loadMissing(['contact', 'whatsappSession']);
        $name = ChatBroadcastAudience::chatDisplayName($chat);
        $body = $kind === 'lead_closed' ? 'Лид закрыт' : 'Лид снова открыт';

        $this->dispatchToUsers(
            $recipientUserIds,
            [
                'kind' => $kind,
                'chat_id' => (string) $chat->id,
                'title' => $name,
                'body' => $body,
            ],
        );
    }

    /**
     * @param  list<int>  $userIds
     * @param  array<string, string>  $data
     */
    public function sendToUsersNow(array $userIds, array $data): void
    {
        if (! $this->isEnabled() || $userIds === []) {
            return;
        }

        $devices = UserDevice::query()
            ->withoutGlobalScope('tenant')
            ->whereIn('user_id', $userIds)
            ->get();

        foreach ($devices as $device) {
            $result = app(FcmClient::class)->sendData($device->fcm_token, $data);
            if ($result->tokenInvalid) {
                $device->delete();
            }
        }
    }

    private function teamMessagePreview(TeamMessage $message): string
    {
        $body = trim((string) $message->body);
        if ($body !== '') {
            return Str::limit($body, 240);
        }

        $message->loadMissing('attachments');
        if ($message->attachments->isNotEmpty()) {
            return 'Вложение';
        }

        return 'Новое сообщение';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function stringifyData(array $data): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            $normalized[$key] = is_scalar($value) || $value === null
                ? (string) ($value ?? '')
                : '';
        }

        return $normalized;
    }
}
