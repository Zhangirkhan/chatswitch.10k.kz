<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Models\CalendarEvent;
use App\Models\Chat;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DepartmentPost;
use App\Services\AI\ChatAttentionService;
use App\Services\Funnel\FunnelProgressCalculator;
use Illuminate\Support\Collection;

final class ContactCardCrmService
{
    public function __construct(
        private readonly ChatAttentionService $attention,
        private readonly FunnelProgressCalculator $progressCalculator,
    ) {}

    /**
     * @param  Collection<int, Chat>  $chats
     * @param  array<int, int>  $contactIds
     * @return array{
     *     deal: array<string, mixed>|null,
     *     companies: list<array{id: int, name: string, position: string|null}>,
     *     upcoming_events: list<array<string, mixed>>,
     *     open_tasks: list<array<string, mixed>>,
     *     facts: list<array{label: string, value: string, source: string}>
     * }
     */
    public function build(Collection $chats, array $contactIds, ?int $preferredChatId = null): array
    {
        $chatIds = $chats->pluck('id')->map(fn ($id) => (int) $id)->all();
        $primary = $this->resolvePrimaryChat($chats, $preferredChatId);

        return [
            'deal' => $primary !== null ? $this->dealPayload($primary) : null,
            'companies' => $this->companiesPayload($contactIds),
            'upcoming_events' => $this->eventsPayload($chatIds, $contactIds),
            'open_tasks' => $this->tasksPayload($chatIds),
            'facts' => $this->factsPayload($chats, $primary),
        ];
    }

    /**
     * @param  Collection<int, Chat>  $chats
     */
    private function resolvePrimaryChat(Collection $chats, ?int $preferredChatId): ?Chat
    {
        if ($chats->isEmpty()) {
            return null;
        }

        if ($preferredChatId !== null) {
            $preferred = $chats->firstWhere('id', $preferredChatId);
            if ($preferred instanceof Chat) {
                return $preferred;
            }
        }

        $activeWithFunnel = $chats
            ->filter(fn (Chat $chat): bool => ! $chat->is_archived && $chat->funnel_id !== null)
            ->sortByDesc(fn (Chat $chat) => (string) ($chat->last_message_at ?? ''));

        if ($activeWithFunnel->isNotEmpty()) {
            return $activeWithFunnel->first();
        }

        $active = $chats
            ->filter(fn (Chat $chat): bool => ! $chat->is_archived)
            ->sortByDesc(fn (Chat $chat) => (string) ($chat->last_message_at ?? ''));

        if ($active->isNotEmpty()) {
            return $active->first();
        }

        return $chats->sortByDesc(fn (Chat $chat) => (string) ($chat->last_message_at ?? ''))->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function dealPayload(Chat $chat): array
    {
        $chat->loadMissing(['funnel', 'funnelStage', 'assignments.user']);
        $progress = $this->progressCalculator->forChat($chat);
        $attention = $this->attention->describe($chat);

        return [
            'chat_id' => $chat->id,
            'chat_name' => $chat->chat_name,
            'open_url' => route('chats.show', $chat->id),
            'funnel' => $chat->funnel ? [
                'id' => $chat->funnel->id,
                'name' => $chat->funnel->name,
                'color' => $chat->funnel->color,
            ] : null,
            'stage' => $chat->funnelStage ? [
                'id' => $chat->funnelStage->id,
                'name' => $chat->funnelStage->name,
                'color' => $chat->funnelStage->color,
                'stage_type' => $chat->funnelStage->stage_type,
            ] : null,
            'progress_percent' => (int) round((float) ($progress['percent'] ?? 0)),
            'ai_enabled' => (bool) $chat->ai_enabled,
            'ai_mode' => $chat->ai_mode,
            'ai_orchestrator_status' => $chat->ai_orchestrator_status,
            'ai_orchestrator_summary' => $chat->ai_orchestrator_last_summary,
            'assignees' => $chat->assignments
                ->map(fn ($assignment) => [
                    'id' => $assignment->user?->id,
                    'name' => $assignment->user?->name,
                ])
                ->filter(fn (array $row): bool => $row['id'] !== null)
                ->values()
                ->all(),
            'attention' => [
                'needs_attention' => in_array($attention['severity'], ['critical', 'danger', 'warning'], true)
                    || (int) $chat->unread_count > 0,
                'reason' => $attention['reason'],
                'severity' => $attention['severity'],
            ],
            'is_archived' => (bool) $chat->is_archived,
        ];
    }

    /**
     * @param  array<int, int>  $contactIds
     * @return list<array{id: int, name: string, position: string|null}>
     */
    private function companiesPayload(array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }

        return Company::query()
            ->whereHas('contacts', fn ($q) => $q->whereIn('contacts.id', $contactIds))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (Company $company) use ($contactIds): array {
                $position = Contact::query()
                    ->whereIn('id', $contactIds)
                    ->whereHas('companies', fn ($q) => $q->whereKey($company->id))
                    ->with(['companies' => fn ($q) => $q->whereKey($company->id)])
                    ->first()
                    ?->companies
                    ->first()
                    ?->pivot
                    ?->position;

                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'position' => is_string($position) && trim($position) !== '' ? trim($position) : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $chatIds
     * @param  array<int, int>  $contactIds
     * @return list<array<string, mixed>>
     */
    private function eventsPayload(array $chatIds, array $contactIds): array
    {
        if ($chatIds === [] && $contactIds === []) {
            return [];
        }

        return CalendarEvent::query()
            ->with('assignee:id,name')
            ->where(function ($query) use ($chatIds, $contactIds): void {
                $hasScope = false;
                if ($chatIds !== []) {
                    $query->whereIn('chat_id', $chatIds);
                    $hasScope = true;
                }
                if ($contactIds !== []) {
                    if ($hasScope) {
                        $query->orWhereIn('contact_id', $contactIds);
                    } else {
                        $query->whereIn('contact_id', $contactIds);
                    }
                }
            })
            ->where('starts_at', '>=', now()->subDay())
            ->orderBy('starts_at')
            ->limit(5)
            ->get(['id', 'title', 'starts_at', 'ends_at', 'chat_id', 'assignee_user_id', 'source'])
            ->map(fn (CalendarEvent $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'ends_at' => $event->ends_at?->toIso8601String(),
                'assignee' => $event->assignee?->name,
                'source' => $event->source,
                'chat_id' => $event->chat_id,
                'open_url' => $event->chat_id ? route('chats.show', $event->chat_id) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $chatIds
     * @return list<array<string, mixed>>
     */
    private function tasksPayload(array $chatIds): array
    {
        if ($chatIds === []) {
            return [];
        }

        return DepartmentPost::query()
            ->with(['department:id,name', 'assignees:id,name'])
            ->whereIn('status', [DepartmentPost::STATUS_OPEN, DepartmentPost::STATUS_IN_PROGRESS])
            ->where(function ($query) use ($chatIds): void {
                foreach ($chatIds as $chatId) {
                    $query->orWhere('body', 'like', '%/chats/'.$chatId.'%');
                }
            })
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'title', 'body', 'status', 'department_id', 'created_at', 'due_at'])
            ->map(fn (DepartmentPost $post): array => [
                'id' => $post->id,
                'title' => $post->title,
                'status' => $post->status,
                'department' => $post->department?->name,
                'assignees' => $post->assignees->pluck('name')->filter()->values()->all(),
                'due_at' => $post->due_at?->toIso8601String(),
                'created_at' => $post->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Chat>  $chats
     * @return list<array{label: string, value: string, source: string}>
     */
    private function factsPayload(Collection $chats, ?Chat $primary): array
    {
        $facts = [];

        if ($primary !== null) {
            $summary = trim((string) ($primary->ai_orchestrator_last_summary ?? ''));
            if ($summary !== '') {
                $facts[] = [
                    'label' => 'Контекст AI',
                    'value' => mb_substr($summary, 0, 280),
                    'source' => 'ai',
                ];
            }

            $reason = trim((string) ($primary->funnel_ai_last_reason ?? ''));
            if ($reason !== '' && $reason !== $summary) {
                $facts[] = [
                    'label' => 'Этап воронки',
                    'value' => mb_substr($reason, 0, 280),
                    'source' => 'funnel',
                ];
            }
        }

        foreach ($chats->sortByDesc('last_message_at')->take(3) as $chat) {
            $text = trim((string) ($chat->last_message_text ?? ''));
            if ($text === '' || $chat->last_message_direction !== 'inbound') {
                continue;
            }

            $facts[] = [
                'label' => 'Последний запрос клиента',
                'value' => mb_substr($text, 0, 220),
                'source' => 'message',
            ];
            break;
        }

        return array_slice($facts, 0, 4);
    }
}
