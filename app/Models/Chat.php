<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\WhatsappMessageType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Chat extends Model
{
    use BelongsToTenant, HasFactory;

    protected static function booted(): void
    {
        self::creating(function (Chat $chat): void {
            if ($chat->is_group) {
                if (! array_key_exists('ai_enabled', $chat->getAttributes())) {
                    $chat->ai_enabled = false;
                }

                return;
            }

            if (! array_key_exists('ai_enabled', $chat->getAttributes())) {
                $chat->ai_enabled = true;
            }

            if (! array_key_exists('ai_mode', $chat->getAttributes())) {
                $chat->ai_mode = 'auto';
            }

            if (! array_key_exists('funnel_tracking_enabled', $chat->getAttributes())) {
                $chat->funnel_tracking_enabled = true;
            }
        });
    }

    protected $fillable = [
        'whatsapp_chat_id',
        'whatsapp_session_id',
        'contact_id',
        'company_id',
        'community_id',
        'chat_name',
        'is_group',
        'is_sandbox',
        'last_message_text',
        'last_message_at',
        'last_message_direction',
        'last_message_is_ai',
        'messages_cleared_at',
        'unread_count',
        'is_archived',
        'is_pinned',
        'pinned_message_id',
        'is_muted',
        'muted_until',
        'is_favorite',
        'ai_enabled',
        'ai_mode',
        'ai_responder_user_id',
        'funnel_id',
        'funnel_stage_id',
        'funnel_tracking_enabled',
        'funnel_stage_locked',
        'funnel_ai_last_analyzed_at',
        'funnel_ai_last_message_id',
        'funnel_ai_last_reason',
        'ai_orchestrator_status',
        'ai_orchestrator_last_run_id',
        'ai_orchestrator_last_action_at',
        'ai_orchestrator_last_summary',
    ];

    protected function casts(): array
    {
        return [
            'is_group' => 'boolean',
            'is_sandbox' => 'boolean',
            'is_archived' => 'boolean',
            'is_pinned' => 'boolean',
            'is_muted' => 'boolean',
            'is_favorite' => 'boolean',
            'ai_enabled' => 'boolean',
            'funnel_tracking_enabled' => 'boolean',
            'funnel_stage_locked' => 'boolean',
            'last_message_at' => 'datetime',
            'last_message_is_ai' => 'boolean',
            'messages_cleared_at' => 'datetime',
            'muted_until' => 'datetime',
            'funnel_ai_last_analyzed_at' => 'datetime',
            'ai_orchestrator_last_action_at' => 'datetime',
        ];
    }

    public function whatsappSession(): BelongsTo
    {
        return $this->belongsTo(WhatsappSession::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function aiResponder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ai_responder_user_id');
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }

    /**
     * Последнее сообщение чата — нужно для превью в списке (иконка + локализованная
     * подпись «Фото/Видео/Голосовое (0:12)»). Сортируем так же, как в
     * ChatService::refreshChatLastMessageSnapshot, чтобы денормализованные
     * `last_message_*` колонки и это отношение ссылались на одно и то же сообщение.
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany(['message_timestamp', 'id']);
    }

    public function pinnedMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'pinned_message_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ChatAssignment::class);
    }

    /**
     * Есть ли на чате вручную назначенный активный сотрудник.
     * Пока назначений нет, AI отвечает от имени компании без личной подписи.
     */
    public function hasManualAssignees(): bool
    {
        if ($this->relationLoaded('assignments')) {
            return $this->assignments->isNotEmpty();
        }

        return $this->assignments()->exists();
    }

    public function assignedUsers(): HasMany
    {
        return $this->hasMany(ChatAssignment::class);
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'chat_department')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<Funnel, $this>
     */
    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }

    /**
     * @return BelongsTo<FunnelStage, $this>
     */
    public function funnelStage(): BelongsTo
    {
        return $this->belongsTo(FunnelStage::class, 'funnel_stage_id');
    }

    /**
     * @return HasMany<ChatFunnelTransition>
     */
    public function funnelTransitions(): HasMany
    {
        return $this->hasMany(ChatFunnelTransition::class)->orderByDesc('created_at');
    }

    /**
     * @return HasMany<ScheduledMessage>
     */
    public function scheduledMessages(): HasMany
    {
        return $this->hasMany(ScheduledMessage::class);
    }

    /**
     * @return HasMany<AiFollowUpProposal>
     */
    public function aiFollowUpProposals(): HasMany
    {
        return $this->hasMany(AiFollowUpProposal::class);
    }

    /**
     * @return BelongsTo<AiOrchestratorRun, $this>
     */
    public function lastOrchestratorRun(): BelongsTo
    {
        return $this->belongsTo(AiOrchestratorRun::class, 'ai_orchestrator_last_run_id');
    }

    /**
     * Чаты, где есть переписка с клиентом или исходящее от менеджера (не только AI в пустоту).
     *
     * @param  Builder<Chat>  $query
     * @return Builder<Chat>
     */
    public function scopeWithOperatorVisibleActivity(Builder $query): Builder
    {
        return $query->where(function (Builder $scope): void {
            $scope
                ->whereHas('messages', function (Builder $messageQuery): void {
                    $messageQuery->where('direction', 'inbound');
                    WhatsappMessageType::applyOperatorVisibleScope($messageQuery);
                })
                ->orWhereHas('messages', function (Builder $messageQuery): void {
                    $messageQuery->humanOutbound();
                });
        });
    }
}
