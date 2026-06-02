<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Models\Chat;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Services\ChatService;
use App\Support\ContactListFilters;
use App\Support\PhoneFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class ClientsListService
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly ContactBucketResolver $contactBucketResolver,
        private readonly ContactListFilterService $contactListFilters,
    ) {}

    /**
     * @return LengthAwarePaginator<array<string, mixed>>
     */
    public function paginate(
        User $user,
        string $search,
        ContactListFilters $listFilters,
        int $page,
        int $perPage,
        string $pageName = 'clients_page',
    ): LengthAwarePaginator {
        $contactQuery = $this->baseContactQuery($search, $listFilters);
        $bucketSummaries = $this->fetchBucketSummaries($user, $contactQuery);

        $buckets = $bucketSummaries
            ->groupBy(fn (object $row): string => $this->bucketKeyFromRow($row))
            ->map(function (Collection $group, string $bucketKey): array {
                /** @var Collection<int, object> $group */
                return [
                    'bucket_key' => $bucketKey,
                    'contact_ids' => $group->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
                    'last_chat_at' => $group->max(fn (object $row) => (string) ($row->last_chat_at ?? '')),
                ];
            })
            ->sortByDesc(fn (array $bucket) => (string) ($bucket['last_chat_at'] ?? ''))
            ->values();

        $pageBuckets = $buckets->forPage($page, $perPage)->values();
        $contactIds = $pageBuckets
            ->flatMap(fn (array $bucket) => $bucket['contact_ids'])
            ->unique()
            ->values()
            ->all();

        $clients = $contactIds === []
            ? collect()
            : $this->buildClientsList(
                $user,
                $this->loadContactsForBuckets($contactIds),
            );

        return new LengthAwarePaginator(
            items: $clients->values()->all(),
            total: $buckets->count(),
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => request()->url(),
                'pageName' => $pageName,
            ],
        );
    }

    /**
     * @return Builder<Contact>
     */
    private function baseContactQuery(string $search, ContactListFilters $listFilters): Builder
    {
        $query = Contact::query()->orderByRaw('COALESCE(name, push_name, phone_number) asc');

        if ($search !== '') {
            $digits = preg_replace('/\D/', '', $search);
            $query->where(function ($q) use ($search, $digits): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('push_name', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%")
                    ->orWhere('whatsapp_id', 'like', "%{$search}%")
                    ->orWhereHas('companies', fn ($companyQuery) => $companyQuery->where('name', 'like', "%{$search}%"));
                if (is_string($digits) && $digits !== '') {
                    $q->orWhere('phone_number', 'like', "%{$digits}%")
                        ->orWhere('whatsapp_id', 'like', "%{$digits}%");
                }
            });
        }

        $this->contactListFilters->apply($query, $listFilters);

        return $query;
    }

    /**
     * Lightweight rows for bucket grouping — no chat/company eager loads.
     *
     * @param  Builder<Contact>  $contactQuery
     * @return Collection<int, object{id: int, phone_number: ?string, whatsapp_id: ?string, last_chat_at: ?string}>
     */
    private function fetchBucketSummaries(User $user, Builder $contactQuery): Collection
    {
        $visibleChatsSub = $this->chatService->queryVisibleToUser($user)
            ->where('is_group', false)
            ->selectRaw('contact_id, MAX(COALESCE(last_message_at, updated_at)) as last_chat_at')
            ->groupBy('contact_id');

        return (clone $contactQuery)
            ->joinSub($visibleChatsSub, 'visible_client_chats', function (JoinClause $join): void {
                $join->on('contacts.id', '=', 'visible_client_chats.contact_id');
            })
            ->get([
                'contacts.id',
                'contacts.phone_number',
                'contacts.whatsapp_id',
                'visible_client_chats.last_chat_at',
            ]);
    }

    /**
     * @param  list<int>  $contactIds
     * @return Collection<int, Contact>
     */
    private function loadContactsForBuckets(array $contactIds): Collection
    {
        return Contact::query()
            ->whereIn('id', $contactIds)
            ->with([
                'companies:id,name',
                'chats' => fn ($q) => $q
                    ->where('is_group', false)
                    ->with([
                        'whatsappSession:id,display_name,phone_number',
                        'funnelStage:id,name,color',
                    ])
                    ->orderByDesc('last_message_at')
                    ->orderByDesc('id'),
            ])
            ->get([
                'id',
                'whatsapp_id',
                'phone_number',
                'name',
                'push_name',
                'profile_picture_url',
            ]);
    }

    /**
     * @param  Collection<int, Contact>  $contacts
     * @return Collection<int, array<string, mixed>>
     */
    private function buildClientsList(User $user, Collection $contacts): Collection
    {
        return $contacts
            ->groupBy(fn (Contact $contact): string => $this->bucketKeyFromContact($contact))
            ->map(function (Collection $bucket) use ($user): ?array {
                /** @var Contact $primary */
                $primary = $bucket->first();
                $allChats = $bucket
                    ->flatMap(fn (Contact $contact) => $contact->chats)
                    ->filter(fn (Chat $chat): bool => $user->can('view', $chat))
                    ->sortByDesc(fn (Chat $chat) => (string) ($chat->last_message_at ?? $chat->updated_at ?? ''));

                if ($allChats->isEmpty()) {
                    return null;
                }

                $latestChat = $allChats->first();
                $savedName = $bucket
                    ->map(fn (Contact $contact) => trim((string) ($contact->name ?? '')))
                    ->first(fn (string $name) => $name !== '');
                $pushName = $bucket
                    ->map(fn (Contact $contact) => trim((string) ($contact->push_name ?? '')))
                    ->first(fn (string $name) => $name !== '');

                $channels = $allChats
                    ->map(function (Chat $chat): array {
                        $session = $chat->whatsappSession;

                        return [
                            'chat_id' => $chat->id,
                            'session_id' => $chat->whatsapp_session_id,
                            'session_label' => trim((string) ($session?->display_name ?? '')) ?: trim((string) ($session?->phone_number ?? '')) ?: 'Без подписи номера',
                            'session_phone' => $session?->phone_number,
                            'chat_name' => $chat->chat_name,
                            'last_message_at' => $chat->last_message_at?->toISOString(),
                        ];
                    })
                    ->groupBy(fn (array $row) => (string) ($row['session_id'] ?? ''))
                    ->map(fn (Collection $rows) => $rows->first())
                    ->values();

                $clientCompanies = $bucket
                    ->flatMap(fn (Contact $contact) => $contact->companies)
                    ->unique('id')
                    ->map(fn (Company $company) => [
                        'id' => $company->id,
                        'name' => $company->name,
                        'position' => $company->pivot?->position,
                    ])
                    ->values();

                $phoneIdentity = PhoneFormatter::resolveContactIdentity($bucket);
                $stage = $latestChat?->funnelStage;

                return [
                    'id' => $primary->id,
                    'whatsapp_id' => $primary->whatsapp_id,
                    'phone_number' => $phoneIdentity['phone_number'],
                    'phone_display' => $phoneIdentity['phone_display'],
                    'lead_id' => $phoneIdentity['lead_id'],
                    'name' => $savedName !== null ? $savedName : null,
                    'push_name' => $pushName !== null ? $pushName : null,
                    'profile_picture_url' => $primary->profile_picture_url,
                    'chats_count' => $channels->count(),
                    'last_chat_name' => $latestChat?->chat_name,
                    'last_chat_at' => $latestChat?->last_message_at?->toISOString(),
                    'primary_chat_id' => $latestChat?->id,
                    'unread_count' => (int) $allChats->sum(fn (Chat $chat): int => (int) $chat->unread_count),
                    'stage' => $stage !== null ? [
                        'name' => $stage->name,
                        'color' => $stage->color,
                    ] : null,
                    'channels' => $channels,
                    'companies' => $clientCompanies,
                ];
            })
            ->filter()
            ->sortByDesc(fn (array $client) => (string) ($client['last_chat_at'] ?? ''))
            ->values();
    }

    private function bucketKeyFromContact(Contact $contact): string
    {
        $digits = $this->contactBucketResolver->normalizedDigits(
            (string) ($contact->phone_number ?: $contact->whatsapp_id ?: ''),
        );

        return $digits !== '' ? "phone:{$digits}" : "contact:{$contact->id}";
    }

    private function bucketKeyFromRow(object $row): string
    {
        $digits = $this->contactBucketResolver->normalizedDigits(
            (string) (($row->phone_number ?? null) ?: ($row->whatsapp_id ?? null) ?: ''),
        );

        return $digits !== '' ? "phone:{$digits}" : "contact:{$row->id}";
    }
}
