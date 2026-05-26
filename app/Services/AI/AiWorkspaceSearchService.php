<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\DepartmentPost;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\Message;
use App\Models\MessageMedia;
use App\Models\User;
use App\Services\Calendar\CalendarEventsInRangeCollector;
use App\Services\ChatService;
use App\Services\Funnel\FunnelBoardService;
use App\Support\FunnelBoardFilters;
use App\Support\TenantCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

final class AiWorkspaceSearchService
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly FunnelBoardService $funnelBoard,
        private readonly CalendarEventsInRangeCollector $calendarCollector,
        private readonly AiWorkspaceAccessService $access,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function searchContacts(User $user, array $filters): array
    {
        $limit = min(50, max(1, (int) ($filters['limit'] ?? 25)));
        $text = $this->nullableString($filters['text'] ?? $filters['search'] ?? null);
        $companyName = $this->nullableString($filters['company_name'] ?? null);
        $phoneContains = $this->nullableString($filters['phone_contains'] ?? null);
        $hasUnread = filter_var($filters['has_unread_chat'] ?? false, FILTER_VALIDATE_BOOL);

        $visibleChats = $this->chatService->queryVisibleToUser($user)
            ->where('is_group', false);

        $query = Contact::query()
            ->whereHas('chats', fn (Builder $q) => (clone $visibleChats)->whereColumn('chats.contact_id', 'contacts.id'))
            ->with([
                'companies:id,name',
                'chats' => fn ($q) => (clone $visibleChats)
                    ->orderByDesc('last_message_at')
                    ->orderByDesc('id')
                    ->limit(3),
            ]);

        if ($text !== null) {
            $digits = preg_replace('/\D/', '', $text);
            $query->where(function (Builder $q) use ($text, $digits): void {
                $q->where('name', 'like', "%{$text}%")
                    ->orWhere('push_name', 'like', "%{$text}%")
                    ->orWhere('phone_number', 'like', "%{$text}%")
                    ->orWhere('whatsapp_id', 'like', "%{$text}%")
                    ->orWhereHas('companies', fn (Builder $cq) => $cq->where('name', 'like', "%{$text}%"));
                if (is_string($digits) && $digits !== '') {
                    $q->orWhere('phone_number', 'like', "%{$digits}%")
                        ->orWhere('whatsapp_id', 'like', "%{$digits}%");
                }
            });
        }

        if ($companyName !== null) {
            $query->whereHas('companies', fn (Builder $q) => $q
                ->where('companies.id', TenantCompany::id())
                ->where('name', 'like', "%{$companyName}%"));
        }

        if ($phoneContains !== null) {
            $digits = preg_replace('/\D/', '', $phoneContains) ?: $phoneContains;
            $query->where(function (Builder $q) use ($digits): void {
                $q->where('phone_number', 'like', "%{$digits}%")
                    ->orWhere('whatsapp_id', 'like', "%{$digits}%");
            });
        }

        if ($hasUnread) {
            $query->whereHas('chats', fn (Builder $q) => (clone $visibleChats)
                ->where('unread_count', '>', 0)
                ->whereColumn('chats.contact_id', 'contacts.id'));
        }

        return $query
            ->orderByRaw('COALESCE(name, push_name, phone_number) asc')
            ->limit($limit)
            ->get(['id', 'name', 'push_name', 'phone_number', 'whatsapp_id', 'profile_picture_url'])
            ->map(function (Contact $contact): array {
                /** @var Chat|null $latestChat */
                $latestChat = $contact->chats->first();

                return [
                    'id' => $contact->id,
                    'name' => trim((string) ($contact->name ?: $contact->push_name ?: $contact->phone_number ?: 'Без имени')),
                    'phone_number' => $contact->phone_number,
                    'whatsapp_id' => $contact->whatsapp_id,
                    'profile_picture_url' => $contact->profile_picture_url,
                    'companies' => $contact->companies->pluck('name')->values()->all(),
                    'chat_id' => $latestChat?->id,
                    'last_message_at' => $latestChat?->last_message_at?->toIso8601String(),
                    'unread_count' => (int) $contact->chats->sum('unread_count'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function searchMedia(User $user, array $filters): array
    {
        $limit = min(60, max(1, (int) ($filters['limit'] ?? 30)));
        $filename = $this->nullableString($filters['filename_contains'] ?? $filters['filename'] ?? null);
        $textQuery = $this->nullableString($filters['text_query'] ?? $filters['query'] ?? null);
        $contactText = $this->nullableString($filters['contact_text'] ?? null);
        $mimeCategory = $this->nullableString($filters['mime_category'] ?? 'any');
        $dateFrom = $this->parseDate($filters['date_from'] ?? null);
        $dateTo = $this->parseDate($filters['date_to'] ?? null, endOfDay: true);

        $visibleChatIds = $this->chatService->queryVisibleToUser($user)->select('id');

        $query = MessageMedia::query()
            ->whereHas('message', fn (Builder $m) => $m->whereIn('chat_id', $visibleChatIds))
            ->with([
                'message:id,chat_id,message_timestamp,created_at',
                'message.chat:id,contact_id,chat_name,is_group',
                'message.chat.contact:id,name,push_name,phone_number',
            ]);

        if ($filename !== null) {
            $query->where('filename', 'like', "%{$filename}%");
        }

        if ($textQuery !== null && $filename === null) {
            $query->where(function (Builder $q) use ($textQuery): void {
                $q->where('filename', 'like', "%{$textQuery}%")
                    ->orWhereHas('message', fn (Builder $m) => $m->where('body', 'like', "%{$textQuery}%"));
            });
        }

        if ($contactText !== null) {
            $digits = preg_replace('/\D/', '', $contactText);
            $query->whereHas('message.chat.contact', function (Builder $q) use ($contactText, $digits): void {
                $q->where('name', 'like', "%{$contactText}%")
                    ->orWhere('push_name', 'like', "%{$contactText}%");
                if (is_string($digits) && $digits !== '') {
                    $q->orWhere('phone_number', 'like', "%{$digits}%");
                }
            });
        }

        $this->applyMimeCategory($query, $mimeCategory);

        if ($dateFrom !== null || $dateTo !== null) {
            $query->whereHas('message', function (Builder $m) use ($dateFrom, $dateTo): void {
                if ($dateFrom !== null) {
                    $m->whereRaw('COALESCE(message_timestamp, created_at) >= ?', [$dateFrom]);
                }
                if ($dateTo !== null) {
                    $m->whereRaw('COALESCE(message_timestamp, created_at) <= ?', [$dateTo]);
                }
            });
        }

        return $query
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (MessageMedia $media): array {
                $message = $media->message;
                $chat = $message?->chat;
                $contact = $chat?->contact;
                $contactName = $contact
                    ? trim((string) ($contact->name ?: $contact->push_name ?: $contact->phone_number ?: ''))
                    : null;

                return [
                    'id' => $media->id,
                    'filename' => $media->filename,
                    'mime_type' => $media->mime_type,
                    'file_size' => $media->file_size,
                    'url' => route('media.show', $media->id),
                    'download_url' => route('media.show', ['media' => $media->id, 'download' => 1]),
                    'chat_id' => $chat?->id,
                    'chat_name' => $chat?->chat_name ?? ($chat?->is_group ? 'Группа' : $contactName),
                    'contact_name' => $contactName,
                    'message_at' => optional($message?->message_timestamp ?: $message?->created_at)->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function searchMessages(User $user, array $filters): array
    {
        $textQuery = $this->nullableString($filters['text_query'] ?? $filters['query'] ?? null);
        if ($textQuery === null) {
            return [];
        }

        $limit = min(40, max(1, (int) ($filters['limit'] ?? 20)));
        $contactText = $this->nullableString($filters['contact_text'] ?? null);
        $dateFrom = $this->parseDate($filters['date_from'] ?? null);
        $dateTo = $this->parseDate($filters['date_to'] ?? null, endOfDay: true);

        $visibleChatIds = $this->chatService->queryVisibleToUser($user)->select('id');

        $query = Message::query()
            ->whereIn('chat_id', $visibleChatIds)
            ->where('body', 'like', "%{$textQuery}%")
            ->with([
                'chat:id,contact_id,chat_name,is_group',
                'chat.contact:id,name,push_name,phone_number',
            ]);

        if ($contactText !== null) {
            $digits = preg_replace('/\D/', '', $contactText);
            $query->whereHas('chat.contact', function (Builder $q) use ($contactText, $digits): void {
                $q->where('name', 'like', "%{$contactText}%")
                    ->orWhere('push_name', 'like', "%{$contactText}%");
                if (is_string($digits) && $digits !== '') {
                    $q->orWhere('phone_number', 'like', "%{$digits}%");
                }
            });
        }

        if ($dateFrom !== null) {
            $query->whereRaw('COALESCE(message_timestamp, created_at) >= ?', [$dateFrom]);
        }
        if ($dateTo !== null) {
            $query->whereRaw('COALESCE(message_timestamp, created_at) <= ?', [$dateTo]);
        }

        return $query
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'chat_id', 'body', 'message_timestamp', 'created_at', 'direction'])
            ->map(function (Message $message): array {
                $chat = $message->chat;
                $contact = $chat?->contact;
                $contactName = $contact
                    ? trim((string) ($contact->name ?: $contact->push_name ?: $contact->phone_number ?: ''))
                    : null;

                return [
                    'id' => (int) $message->id,
                    'body' => (string) ($message->body ?? ''),
                    'direction' => (string) $message->direction,
                    'chat_id' => $chat?->id,
                    'chat_name' => $chat?->chat_name,
                    'contact_name' => $contactName,
                    'message_at' => optional($message->message_timestamp ?: $message->created_at)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{meta: array<string, mixed>, events: list<array<string, mixed>>}
     */
    public function searchCalendarEvents(User $user, array $filters): array
    {
        $employeeName = $this->nullableString($filters['employee_name'] ?? $filters['assignee_name'] ?? null);
        $employeeId = isset($filters['employee_id']) ? (int) $filters['employee_id'] : null;
        $daysAhead = min(90, max(1, (int) ($filters['days_ahead'] ?? 14)));

        $dateFrom = $this->parseDate($filters['date_from'] ?? null) ?? Carbon::now()->startOfDay();
        $dateTo = $this->parseDate($filters['date_to'] ?? null, endOfDay: true)
            ?? Carbon::now()->addDays($daysAhead)->endOfDay();

        $meta = [
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
        ];

        $assignee = null;

        if ($employeeId !== null && $employeeId > 0) {
            $assignee = User::query()->find($employeeId);
            if ($assignee === null || ! $this->access->canViewEmployee($user, $assignee)) {
                return [
                    'meta' => array_merge($meta, ['access_denied' => true]),
                    'events' => [],
                ];
            }
        } elseif ($employeeName !== null) {
            $matches = $this->access->resolveEmployeesByName($user, $employeeName);
            if ($matches === []) {
                return [
                    'meta' => array_merge($meta, ['not_found' => true, 'query' => $employeeName]),
                    'events' => [],
                ];
            }
            if (count($matches) > 1) {
                return [
                    'meta' => array_merge($meta, [
                        'ambiguous' => array_map(static fn (User $u): array => [
                            'id' => (int) $u->id,
                            'name' => (string) $u->name,
                        ], $matches),
                    ]),
                    'events' => [],
                ];
            }
            $assignee = $matches[0];
        } else {
            $assignee = $user;
        }

        $meta['employee_name'] = (string) $assignee->name;
        $meta['employee_id'] = (int) $assignee->id;

        $events = $this->calendarCollector
            ->collect($user, $dateFrom, $dateTo, 'all', null, (int) $assignee->id)
            ->sortBy(fn (array $row) => Carbon::parse((string) $row['starts_at'])->timestamp)
            ->values()
            ->all();

        return [
            'meta' => $meta,
            'events' => $events,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function searchFunnelDeals(User $user, array $filters): array
    {
        $limit = min(50, max(1, (int) ($filters['limit'] ?? 25)));
        $funnelName = $this->nullableString($filters['funnel_name'] ?? null);
        $stageName = $this->nullableString($filters['stage_name'] ?? null);
        $assigneeName = $this->nullableString($filters['assignee_name'] ?? null);
        $search = $this->nullableString($filters['search'] ?? $filters['text'] ?? null);
        $scope = in_array($filters['scope'] ?? 'all', ['all', 'mine', 'department'], true)
            ? (string) ($filters['scope'] ?? 'all')
            : 'all';

        $funnels = Funnel::query()
            ->where('company_id', TenantCompany::id())
            ->where('is_active', true)
            ->when($funnelName !== null, fn (Builder $q) => $q->where('name', 'like', "%{$funnelName}%"))
            ->orderBy('position')
            ->get(['id', 'name']);

        if ($funnels->isEmpty()) {
            return [];
        }

        $assigneeId = null;
        if ($assigneeName !== null) {
            $matches = $this->access->resolveEmployeesByName($user, $assigneeName, 1);
            $assigneeId = isset($matches[0]) ? (int) $matches[0]->id : null;
        }

        $boardFilters = new FunnelBoardFilters(
            scope: $scope,
            assigneeId: $assigneeId,
            search: $search,
        );

        $stageIds = [];
        if ($stageName !== null) {
            $stageIds = FunnelStage::query()
                ->whereIn('funnel_id', $funnels->pluck('id'))
                ->where('name', 'like', "%{$stageName}%")
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $results = [];

        foreach ($funnels as $funnel) {
            $query = $this->funnelBoard->boardChatsQuery($user, (int) $funnel->id, $boardFilters);
            if ($stageIds !== []) {
                $query->whereIn('funnel_stage_id', $stageIds);
            }

            foreach ($query->limit($limit)->get() as $chat) {
                $stage = $chat->funnel_stage_id
                    ? FunnelStage::query()->find($chat->funnel_stage_id, ['id', 'name'])
                    : null;
                $card = $this->funnelBoard->serializeCard($chat);
                $card['funnel_id'] = (int) $funnel->id;
                $card['funnel_name'] = (string) $funnel->name;
                $card['stage_name'] = $stage?->name ?? 'Без этапа';
                $results[] = $card;
                if (count($results) >= $limit) {
                    break 2;
                }
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function searchDepartmentPosts(User $user, array $filters): array
    {
        $limit = min(40, max(1, (int) ($filters['limit'] ?? 20)));
        $assigneeName = $this->nullableString($filters['assignee_name'] ?? null);
        $departmentName = $this->nullableString($filters['department_name'] ?? null);
        $status = $this->nullableString($filters['status'] ?? null);
        $text = $this->nullableString($filters['text'] ?? $filters['search'] ?? null);

        $query = DepartmentPost::query()
            ->with([
                'department:id,name',
                'author:id,name',
                'assignees:id,name',
            ]);

        if ($user->hasRole('administrator')) {
            if ($departmentName !== null) {
                $query->whereHas('department', fn (Builder $q) => $q->where('name', 'like', "%{$departmentName}%"));
            }
        } else {
            $deptIds = $user->departmentIds();
            if ($deptIds === []) {
                return [];
            }
            $query->whereIn('department_id', $deptIds);
            if ($departmentName !== null) {
                $query->whereHas('department', fn (Builder $q) => $q
                    ->whereIn('id', $deptIds)
                    ->where('name', 'like', "%{$departmentName}%"));
            }
        }

        if ($assigneeName !== null) {
            $matches = $this->access->resolveEmployeesByName($user, $assigneeName);
            if ($matches === []) {
                return [];
            }
            $ids = array_map(static fn (User $u): int => (int) $u->id, $matches);
            $query->where(function (Builder $q) use ($ids): void {
                $q->whereIn('author_id', $ids)
                    ->orWhereHas('assignees', fn (Builder $a) => $a->whereIn('users.id', $ids));
            });
        }

        if ($status !== null && in_array($status, DepartmentPost::STATUSES, true)) {
            $query->where('status', $status);
        }

        if ($text !== null) {
            $query->where(function (Builder $q) use ($text): void {
                $q->where('title', 'like', "%{$text}%")
                    ->orWhere('body', 'like', "%{$text}%");
            });
        }

        return $query
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(static fn (DepartmentPost $post): array => [
                'id' => (int) $post->id,
                'title' => (string) $post->title,
                'status' => (string) $post->status,
                'due_at' => $post->due_at?->toIso8601String(),
                'department_name' => $post->department?->name,
                'author_name' => $post->author?->name,
                'assignees' => $post->assignees
                    ->map(static fn (User $u): array => ['id' => (int) $u->id, 'name' => (string) $u->name])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{id: int, name: string, email: string|null}>
     */
    public function searchEmployees(User $user, array $filters): array
    {
        if (filter_var($filters['list_department'] ?? false, FILTER_VALIDATE_BOOL)) {
            return $this->access->listDepartmentColleagues(
                $user,
                $this->nullableString($filters['department_name'] ?? null),
            );
        }

        $name = $this->nullableString($filters['name'] ?? $filters['text'] ?? null);
        if ($name === null) {
            return [];
        }

        return array_map(static fn (User $u): array => [
            'id' => (int) $u->id,
            'name' => (string) $u->name,
            'email' => $u->email,
        ], $this->access->resolveEmployeesByName($user, $name));
    }

    /**
     * @param  Builder<MessageMedia>  $query
     */
    private function applyMimeCategory(Builder $query, ?string $category): void
    {
        if ($category === null || $category === '' || $category === 'any') {
            return;
        }

        $query->where(function (Builder $q) use ($category): void {
            match ($category) {
                'image' => $q->where('mime_type', 'like', 'image/%'),
                'video' => $q->where('mime_type', 'like', 'video/%'),
                'audio' => $q->where(function (Builder $inner): void {
                    $inner->where('mime_type', 'like', 'audio/%')
                        ->orWhere('mime_type', 'like', '%ogg%');
                }),
                'document' => $q->where(function (Builder $inner): void {
                    $inner->where('mime_type', 'like', 'application/%')
                        ->orWhere('mime_type', 'like', 'text/%');
                }),
                default => null,
            };
        });
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function parseDate(mixed $value, bool $endOfDay = false): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $date = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }
}
