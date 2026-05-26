<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\TeamMessage;
use App\Models\TeamMessageReaction;
use App\Models\User;
use App\Tenancy\TenantChannels;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class TeamMessageReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly TeamMessage $message,
    ) {
        $this->message->loadMissing(['sender:id,name', 'conversation', 'parentMessage.sender:id,name', 'attachments', 'reactions.user:id,name']);
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        $conversation = $this->message->conversation;
        if ($conversation === null) {
            return [];
        }

        $companyId = (int) $conversation->company_id;
        $channels = [
            new PrivateChannel(TenantChannels::teamConversation($companyId, (int) $conversation->id)),
        ];

        $userIds = $conversation->participants()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        foreach ($userIds as $uid) {
            $channels[] = new PrivateChannel(TenantChannels::teamInbox($companyId, $uid));
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'team.message';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $m = $this->message;
        $mentionIds = $m->mentioned_user_ids ?? [];
        $mentionIds = is_array($mentionIds) ? $mentionIds : [];
        $mentionNamesById = $mentionIds === []
            ? []
            : User::query()->whereIn('id', $mentionIds)->pluck('name', 'id')->all();

        $mentionedUsers = [];
        foreach ($mentionIds as $uid) {
            $id = (int) $uid;
            if ($id < 1) {
                continue;
            }
            $name = $mentionNamesById[$id] ?? $mentionNamesById[(string) $id] ?? '…';
            $mentionedUsers[] = ['id' => $id, 'name' => $name];
        }

        $forward = null;
        $fromTeam = $m->forwarded_from_team_message_id !== null && (int) $m->forwarded_from_team_message_id > 0;
        $fromWhatsapp = $m->forwarded_from_message_id !== null && (int) $m->forwarded_from_message_id > 0;
        if ($fromTeam || $fromWhatsapp) {
            $forward = [
                'from_message_id' => $fromTeam
                    ? (int) $m->forwarded_from_team_message_id
                    : (int) $m->forwarded_from_message_id,
                'source_kind' => $fromWhatsapp ? 'whatsapp' : 'team',
                'source_title' => (string) ($m->forward_source_title ?? ''),
                'quote_sender_name' => (string) ($m->forward_quote_sender_name ?? ''),
                'quote_body' => (string) ($m->forward_quote_body ?? ''),
            ];
        }

        $attachments = $m->attachments->map(static function ($a): array {
            return [
                'id' => $a->id,
                'original_name' => $a->original_name,
                'url' => $a->url(),
                'mime_type' => $a->mime_type,
                'size' => (int) $a->size,
                'is_image' => $a->isImage(),
            ];
        })->values()->all();

        $reactions = $m->reactions->map(static fn (TeamMessageReaction $r): array => $r->toApiArray())->values()->all();

        $linkPreview = $m->link_preview;
        if (! is_array($linkPreview) || $linkPreview === []) {
            $linkPreview = null;
        }

        return [
            'conversation_id' => $m->team_conversation_id,
            'message' => [
                'id' => $m->id,
                'team_conversation_id' => $m->team_conversation_id,
                'parent_team_message_id' => $m->parent_team_message_id !== null ? (int) $m->parent_team_message_id : null,
                'sender_id' => $m->sender_id,
                'body' => $m->body,
                'client_message_id' => $m->client_message_id,
                'mentioned_user_ids' => $mentionIds,
                'mentioned_users' => $mentionedUsers,
                'forward' => $forward,
                'reply_to' => $m->replyToApiFragment(),
                'attachments' => $attachments,
                'link_preview' => $linkPreview,
                'reactions' => $reactions,
                'created_at' => $m->created_at?->toIso8601String(),
                'sender' => $m->sender ? [
                    'id' => $m->sender->id,
                    'name' => $m->sender->name,
                ] : null,
            ],
            'last_message_at' => $m->conversation?->last_message_at?->toIso8601String(),
            'last_message_preview' => $m->conversation?->last_message_preview,
        ];
    }
}
