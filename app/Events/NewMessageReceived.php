<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Message;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class NewMessageReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Message $message,
        public readonly int $chatId,
    ) {}

    /** @return array<Channel> */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel("chat.{$this->chatId}")];

        foreach ($this->userIdsWithAccess() as $userId) {
            $channels[] = new PrivateChannel("chats.list.{$userId}");
        }

        return $channels;
    }

    /** @return list<int> */
    private function userIdsWithAccess(): array
    {
        $chat = Chat::find($this->chatId);
        if (! $chat) {
            return [];
        }

        $admins = User::role('administrator')->pluck('id')->all();
        $assigned = ChatAssignment::where('chat_id', $chat->id)->pluck('user_id')->all();

        // Отделы, имеющие отношение к чату: прикреплённые pill'ом + отделы назначенных сотрудников.
        $chatDepartmentIds = $chat->departments()->pluck('departments.id')->all();
        $assignedDepartmentIds = [];
        if (! empty($assigned)) {
            $assignedDepartmentIds = User::whereIn('id', $assigned)
                ->whereNotNull('department_id')
                ->pluck('department_id')
                ->unique()
                ->all();
        }

        // Руководители всех этих отделов получают уведомления — они супервизоры.
        $supervisorDepartmentIds = array_values(array_unique(array_merge($chatDepartmentIds, $assignedDepartmentIds)));
        $managerIds = [];
        if (! empty($supervisorDepartmentIds)) {
            $managerIds = User::role('manager')
                ->whereIn('department_id', $supervisorDepartmentIds)
                ->pluck('id')
                ->all();
        }

        // Рядовые сотрудники отдела — только если чат «в общем пуле» (никому не назначен).
        $departmentMemberIds = [];
        if (! empty($chatDepartmentIds) && empty($assigned)) {
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

    public function broadcastAs(): string
    {
        return 'message.received';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id' => $this->message->id,
                'chat_id' => $this->message->chat_id,
                'whatsapp_session_id' => $this->message->whatsapp_session_id,
                'direction' => $this->message->direction,
                'type' => $this->message->type,
                'body' => $this->message->body,
                'metadata' => $this->message->metadata,
                'sender_phone' => $this->message->sender_phone,
                'sender_name' => $this->message->sender_name,
                'sent_by_user_id' => $this->message->sent_by_user_id,
                'is_forwarded' => $this->message->is_forwarded,
                'ack' => $this->message->ack,
                'message_timestamp' => $this->message->message_timestamp?->toISOString(),
                'created_at' => $this->message->created_at?->toISOString(),
                'media' => $this->message->media->map(fn ($m) => [
                    'id' => $m->id,
                    'mime_type' => $m->mime_type,
                    'filename' => $m->filename,
                ])->toArray(),
                'sent_by_user' => $this->message->sentByUser ? [
                    'id' => $this->message->sentByUser->id,
                    'name' => $this->message->sentByUser->name,
                ] : null,
                'whatsapp_session' => $this->message->whatsappSession ? [
                    'id' => $this->message->whatsappSession->id,
                    'phone_number' => $this->message->whatsappSession->phone_number,
                    'display_name' => $this->message->whatsappSession->display_name,
                ] : null,
                'quoted_message' => $this->message->quotedMessage ? [
                    'id' => $this->message->quotedMessage->id,
                    'direction' => $this->message->quotedMessage->direction,
                    'type' => $this->message->quotedMessage->type,
                    'body' => $this->message->quotedMessage->body,
                    'sender_name' => $this->message->quotedMessage->sender_name,
                    'sender_phone' => $this->message->quotedMessage->sender_phone,
                    'sent_by_user' => $this->message->quotedMessage->sentByUser ? [
                        'id' => $this->message->quotedMessage->sentByUser->id,
                        'name' => $this->message->quotedMessage->sentByUser->name,
                    ] : null,
                    'media' => $this->message->quotedMessage->media->map(fn ($m) => [
                        'id' => $m->id,
                        'mime_type' => $m->mime_type,
                        'filename' => $m->filename,
                    ])->toArray(),
                ] : null,
            ],
        ];
    }
}
