<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

final class TeamMessage extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        self::deleting(function (TeamMessage $message): void {
            foreach ($message->attachments()->get() as $attachment) {
                if ($attachment->path !== '') {
                    Storage::disk('public')->delete($attachment->path);
                }
                $attachment->delete();
            }
        });

        self::deleted(function (TeamMessage $message): void {
            TeamConversation::query()
                ->where('pinned_team_message_id', $message->id)
                ->update(['pinned_team_message_id' => null]);
        });
    }

    protected $fillable = [
        'team_conversation_id',
        'parent_team_message_id',
        'sender_id',
        'body',
        'client_message_id',
        'mentioned_user_ids',
        'forwarded_from_team_message_id',
        'forwarded_from_message_id',
        'forward_source_title',
        'forward_quote_sender_name',
        'forward_quote_body',
        'link_preview',
    ];

    protected function casts(): array
    {
        return [
            'mentioned_user_ids' => 'array',
            'link_preview' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(TeamConversation::class, 'team_conversation_id');
    }

    public function parentMessage(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_team_message_id');
    }

    /** @return HasMany<TeamMessage, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_team_message_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function forwardedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'forwarded_from_team_message_id');
    }

    public function forwardedFromWhatsapp(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'forwarded_from_message_id');
    }

    /** @return HasMany<TeamMessageMention, $this> */
    public function mentionRows(): HasMany
    {
        return $this->hasMany(TeamMessageMention::class, 'team_message_id');
    }

    /** @return HasMany<TeamMessageAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(TeamMessageAttachment::class, 'team_message_id')->orderBy('id');
    }

    /** @return HasMany<TeamMessageReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(TeamMessageReaction::class, 'team_message_id')->orderBy('id');
    }

    /**
     * Фрагмент для API/UI: на что отвечаем (только корневые родители).
     *
     * @return array{id: int, sender_name: string, body_preview: string}|null
     */
    public function replyToApiFragment(): ?array
    {
        $pid = $this->parent_team_message_id;
        if ($pid === null || (int) $pid < 1) {
            return null;
        }

        $parent = $this->relationLoaded('parentMessage') ? $this->parentMessage : null;
        if ($parent === null) {
            return [
                'id' => (int) $pid,
                'sender_name' => '…',
                'body_preview' => '',
            ];
        }

        $body = trim((string) $parent->body);
        if ($body === '' && is_string($parent->forward_quote_body) && trim((string) $parent->forward_quote_body) !== '') {
            $body = trim((string) $parent->forward_quote_body);
        }

        $preview = mb_substr(preg_replace('/\s+/u', ' ', $body) ?? '', 0, 160);

        return [
            'id' => (int) $parent->id,
            'sender_name' => (string) ($parent->sender?->name ?? '…'),
            'body_preview' => $preview,
        ];
    }
}
