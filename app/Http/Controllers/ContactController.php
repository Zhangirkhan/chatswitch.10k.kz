<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageMedia;
use App\Services\Contact\ContactCardCrmService;
use App\Support\ChatUrl;
use App\Support\PhoneFormatter;
use App\Support\TenantCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class ContactController extends Controller
{
    public function settingsIndex(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));
        $activeTab = 'clients';
        $clientsPage = max(1, (int) $request->input('clients_page', 1));
        $companiesPage = max(1, (int) $request->input('companies_page', 1));
        $clientsPerPage = 20;
        $companiesPerPage = 12;

        $query = Contact::query()->orderByRaw('COALESCE(name, push_name, phone_number) asc');

        if ($search !== '') {
            $digits = preg_replace('/\D/', '', $search);
            $query->where(function ($q) use ($search, $digits) {
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

        $contacts = $query
            ->with([
                'companies:id,name',
                'chats' => fn ($q) => $q
                    ->where('is_group', false)
                    ->with('whatsappSession:id,display_name,phone_number')
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

        $companiesQuery = Company::query()
            ->whereKey(TenantCompany::id())
            ->with(['contacts:id,name,push_name,phone_number'])
            ->withCount('contacts')
            ->orderBy('name');

        if ($search !== '') {
            $companiesQuery->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('website', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('contacts', function ($contactQuery) use ($search): void {
                        $contactQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('push_name', 'like', "%{$search}%")
                            ->orWhere('phone_number', 'like', "%{$search}%");
                    });
            });
        }

        $companiesPaginator = $companiesQuery->paginate(
            perPage: $companiesPerPage,
            columns: ['*'],
            pageName: 'companies_page',
            page: $companiesPage,
        )->through(fn (Company $company) => [
            'id' => $company->id,
            'name' => $company->name,
            'phone' => $company->phone,
            'email' => $company->email,
            'website' => $company->website,
            'description' => $company->description,
            'clients_count' => (int) ($company->contacts_count ?? $company->contacts->count()),
            'clients' => $company->contacts
                ->map(fn (Contact $contact) => [
                    'id' => $contact->id,
                    'name' => $contact->name ?: $contact->push_name ?: $contact->phone_number ?: 'Без имени',
                    'phone_number' => $contact->phone_number,
                    'position' => $contact->pivot?->position,
                ])
                ->values()
                ->all(),
        ]);

        $companyOptions = Company::query()
            ->whereKey(TenantCompany::id())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->name,
            ])
            ->values();

        $clients = $contacts
            ->groupBy(function (Contact $c) {
                $digits = $this->normalizedDigits((string) ($c->phone_number ?: $c->whatsapp_id ?: ''));

                return $digits !== '' ? "phone:{$digits}" : "contact:{$c->id}";
            })
            ->map(function ($bucket, string $groupKey) {
                /** @var Contact $primary */
                $primary = $bucket->first();
                $allChats = $bucket
                    ->flatMap(fn (Contact $c) => $c->chats)
                    ->sortByDesc(fn (Chat $chat) => (string) ($chat->last_message_at ?? $chat->updated_at ?? ''));

                $latestChat = $allChats->first();
                $savedName = $bucket
                    ->map(fn (Contact $c) => trim((string) ($c->name ?? '')))
                    ->first(fn (string $name) => $name !== '');
                $pushName = $bucket
                    ->map(fn (Contact $c) => trim((string) ($c->push_name ?? '')))
                    ->first(fn (string $name) => $name !== '');

                $channels = $allChats
                    ->map(function (Chat $chat) {
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
                    // Show only one “channel” per WA session number (even if there are multiple chats
                    // for the same client inside the same WA session due to duplicated WA ids).
                    ->groupBy(fn (array $row) => (string) ($row['session_id'] ?? ''))
                    ->map(fn ($rows) => $rows->first())
                    ->values();

                $clientCompanies = $bucket
                    ->flatMap(fn (Contact $c) => $c->companies)
                    ->unique('id')
                    ->map(fn (Company $company) => [
                        'id' => $company->id,
                        'name' => $company->name,
                        'position' => $company->pivot?->position,
                    ])
                    ->values();

                $phoneDigits = $this->normalizedDigits((string) ($primary->phone_number ?? ''));
                if ($phoneDigits === '') {
                    $phoneDigits = str_replace('phone:', '', $groupKey);
                }

                return [
                    'id' => $primary->id,
                    'whatsapp_id' => $primary->whatsapp_id,
                    'phone_number' => $phoneDigits !== '' ? $phoneDigits : $primary->phone_number,
                    'name' => $savedName !== null ? $savedName : null,
                    'push_name' => $pushName !== null ? $pushName : null,
                    'profile_picture_url' => $primary->profile_picture_url,
                    // channels[] already grouped by WA session number; use the same unique basis for count.
                    'chats_count' => $channels->count(),
                    'last_chat_name' => $latestChat?->chat_name,
                    'last_chat_at' => $latestChat?->last_message_at?->toISOString(),
                    'channels' => $channels,
                    'companies' => $clientCompanies,
                ];
            })
            ->sortByDesc(fn (array $client) => (string) ($client['last_chat_at'] ?? ''))
            ->values();

        $clientsPaginator = $this->paginateCollection($clients, $clientsPage, $clientsPerPage, 'clients_page');

        return Inertia::render('Settings/Clients', [
            'search' => $search,
            'activeTab' => $activeTab,
            'clients' => $this->paginationPayload($clientsPaginator),
            'companies' => $this->paginationPayload($companiesPaginator),
            'companyOptions' => $companyOptions,
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return LengthAwarePaginator<array<string, mixed>>
     */
    private function paginateCollection(Collection $items, int $page, int $perPage, string $pageName): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            items: $items->forPage($page, $perPage)->values(),
            total: $items->count(),
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => request()->url(),
                'pageName' => $pageName,
            ],
        );
    }

    /**
     * @param  LengthAwarePaginator<mixed>  $paginator
     * @return array<string, mixed>
     */
    private function paginationPayload(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    private function normalizedDigits(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw);

        return is_string($digits) ? trim($digits) : '';
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));

        $query = Contact::query()->orderByRaw('COALESCE(name, push_name, phone_number) asc');

        if ($search !== '') {
            $digits = preg_replace('/\D/', '', $search);
            $query->where(function ($q) use ($search, $digits) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('push_name', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%");
                if (is_string($digits) && $digits !== '') {
                    $q->orWhere('whatsapp_id', 'like', "%{$digits}%");
                }
            });
        }

        return Inertia::render('Contacts/Index', [
            'search' => $search,
            'contacts' => $query->limit(500)->get([
                'id',
                'whatsapp_id',
                'phone_number',
                'name',
                'push_name',
                'profile_picture_url',
            ]),
        ]);
    }

    public function card(Request $request, Contact $contact): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->authorize('view', $contact);

        $contactIds = $this->contactBucketIds($contact);

        $preferredChatId = $request->filled('chat_id') ? $request->integer('chat_id') : null;

        $chats = Chat::query()
            ->with([
                'whatsappSession:id,session_name,display_name,phone_number,status',
                'funnel:id,name,color',
                'funnelStage:id,name,color,position,funnel_id',
                'assignments.user:id,name',
            ])
            ->whereIn('contact_id', $contactIds)
            ->where('is_group', false)
            ->orderByDesc('last_message_at')
            ->get([
                'id',
                'contact_id',
                'whatsapp_session_id',
                'chat_name',
                'last_message_text',
                'last_message_at',
                'last_message_direction',
                'is_archived',
                'unread_count',
                'funnel_id',
                'funnel_stage_id',
                'ai_enabled',
                'ai_mode',
                'ai_orchestrator_status',
                'ai_orchestrator_last_summary',
                'funnel_ai_last_reason',
            ])
            ->filter(fn (Chat $chat): bool => $user->can('view', $chat))
            ->values();

        $chatIds = $chats->pluck('id')->map(fn ($id) => (int) $id)->all();
        $latestChat = $chats->first();

        $messagesBase = Message::query()->whereIn('chat_id', $chatIds);
        $firstMessage = (clone $messagesBase)
            ->orderByRaw('COALESCE(message_timestamp, created_at)')
            ->orderBy('id')
            ->first(['id', 'body', 'direction', 'sender_name', 'message_timestamp', 'created_at']);
        $lastInbound = (clone $messagesBase)
            ->where('direction', 'inbound')
            ->orderByRaw('COALESCE(message_timestamp, created_at) DESC')
            ->orderByDesc('id')
            ->first(['id', 'body', 'direction', 'sender_name', 'message_timestamp', 'created_at']);
        $lastOutbound = (clone $messagesBase)
            ->where('direction', 'outbound')
            ->orderByRaw('COALESCE(message_timestamp, created_at) DESC')
            ->orderByDesc('id')
            ->first(['id', 'body', 'direction', 'sender_name', 'message_timestamp', 'created_at']);

        $messageCounts = [
            'total' => $chatIds === [] ? 0 : (clone $messagesBase)->count(),
            'inbound' => $chatIds === [] ? 0 : (clone $messagesBase)->where('direction', 'inbound')->count(),
            'outbound' => $chatIds === [] ? 0 : (clone $messagesBase)->where('direction', 'outbound')->count(),
        ];

        $mediaCounts = ['media' => 0, 'documents' => 0, 'links' => 0];
        if ($chatIds !== []) {
            $mediaCounts['media'] = MessageMedia::query()
                ->whereHas('message', fn ($q) => $q->whereIn('chat_id', $chatIds))
                ->where(function ($q): void {
                    $q->where('mime_type', 'like', 'image/%')
                        ->orWhere('mime_type', 'like', 'video/%');
                })
                ->count();
            $mediaCounts['documents'] = MessageMedia::query()
                ->whereHas('message', fn ($q) => $q->whereIn('chat_id', $chatIds))
                ->where(function ($q): void {
                    $q->where('mime_type', 'not like', 'image/%')
                        ->where('mime_type', 'not like', 'video/%');
                })
                ->count();
            $linksQuery = clone $messagesBase;
            if (DB::connection()->getDriverName() === 'sqlite') {
                $linksQuery->where(function ($q): void {
                    $q->where('body', 'like', '%http://%')
                        ->orWhere('body', 'like', '%https://%')
                        ->orWhere('body', 'like', '%www.%');
                });
            } else {
                $linksQuery->where('body', 'regexp', 'https?://|www\\.');
            }
            $mediaCounts['links'] = $linksQuery->count();
        }

        $contacts = Contact::query()
            ->whereIn('id', $contactIds)
            ->get(['id', 'whatsapp_id', 'phone_number', 'name', 'push_name', 'profile_picture_url', 'is_business']);

        $possibleNames = $contacts
            ->flatMap(fn (Contact $c) => [$c->name, $c->push_name])
            ->merge($chats->pluck('chat_name'))
            ->merge(
                Message::query()
                    ->whereIn('chat_id', $chatIds)
                    ->whereNotNull('sender_name')
                    ->distinct()
                    ->limit(10)
                    ->pluck('sender_name'),
            )
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return response()->json([
            'identity' => [
                'contact_id' => $contact->id,
                'display_name' => $this->displayNameForContact($contact, $chats->first()),
                'saved_name' => $contacts->map(fn (Contact $c) => trim((string) $c->name))->first(fn (string $v) => $v !== '') ?: null,
                'push_name' => $contacts->map(fn (Contact $c) => trim((string) $c->push_name))->first(fn (string $v) => $v !== '') ?: null,
                'phone_number' => $this->normalizedDigits((string) ($contact->phone_number ?: $contact->whatsapp_id ?: '')) ?: $contact->phone_number,
                'whatsapp_ids' => $contacts->pluck('whatsapp_id')->filter()->unique()->values()->all(),
                'profile_picture_url' => $contacts->pluck('profile_picture_url')->filter()->first(),
                'is_business' => $contacts->contains(fn (Contact $c) => (bool) $c->is_business),
                'possible_names' => $possibleNames,
            ],
            'activity' => [
                'chats_count' => $chats->count(),
                'channels_count' => $chats->pluck('whatsapp_session_id')->filter()->unique()->count(),
                'first_message_at' => $this->messageIso($firstMessage),
                'last_message_at' => $latestChat?->last_message_at?->toIso8601String(),
                'last_client_message' => $this->messagePreview($lastInbound),
                'last_operator_message' => $this->messagePreview($lastOutbound),
                'messages' => $messageCounts,
                'attachments' => $mediaCounts,
            ],
            'channels' => $chats->map(function (Chat $chat): array {
                $session = $chat->whatsappSession;

                return [
                    'chat_id' => $chat->id,
                    'session_id' => $chat->whatsapp_session_id,
                    'session_label' => trim((string) ($session?->display_name ?? '')) ?: trim((string) ($session?->phone_number ?? '')) ?: 'Без подписи номера',
                    'session_phone' => $session?->phone_number,
                    'session_status' => $session?->status,
                    'chat_name' => $chat->chat_name,
                    'last_message_text' => $chat->last_message_text,
                    'last_message_at' => $chat->last_message_at?->toIso8601String(),
                    'unread_count' => $chat->unread_count,
                    'is_archived' => (bool) $chat->is_archived,
                    'open_url' => ChatUrl::show($chat),
                ];
            })->all(),
            'crm' => app(ContactCardCrmService::class)->build($chats, $contactIds, $preferredChatId ?: null),
        ]);
    }

    public function update(Request $request, Contact $contact): JsonResponse
    {
        $this->authorize('update', $contact);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $name = isset($data['name']) ? trim((string) $data['name']) : null;
        $contact->name = $name !== '' ? $name : null;
        $contact->saveQuietly();

        // Keep chat UI consistent:
        // in lists/headers we prefer `chat.chat_name` over `contact.name`.
        //
        // In “Settings → Clients” we group a single real person across multiple WA ids
        // (e.g. one Contact row with @lid and another with @c.us, but same phone digits).
        // So when operator edits the saved client name, update chat_name for all duplicated
        // Contact rows that belong to the same digit bucket.
        $digits = $this->normalizedDigits((string) ($contact->phone_number ?: $contact->whatsapp_id ?: ''));
        $contactIds = $digits !== ''
            ? Contact::query()
                ->where(function ($q) use ($digits) {
                    $q->where('phone_number', $digits)
                        ->orWhere('whatsapp_id', 'like', "%{$digits}%");
                })
                ->pluck('id')
                ->all()
            : [$contact->id];

        $newChatName = $contact->name ?: $contact->push_name ?: $contact->phone_number;
        Chat::query()
            ->whereIn('contact_id', $contactIds)
            ->where('is_group', false)
            ->update(['chat_name' => $newChatName]);

        return response()->json(['success' => true, 'contact' => $contact]);
    }

    public function syncCompanies(Request $request, Contact $contact): JsonResponse
    {
        $this->authorize('syncCompanies', $contact);

        $data = $request->validate([
            'companies' => ['array'],
            'companies.*.company_id' => ['required', 'integer', 'exists:companies,id'],
            'companies.*.position' => ['nullable', 'string', 'max:160'],
        ]);

        $contactIds = $this->contactBucketIds($contact);
        $payload = collect($data['companies'] ?? [])
            ->filter(fn (array $row): bool => (int) $row['company_id'] === TenantCompany::id())
            ->mapWithKeys(function (array $row): array {
                $position = trim((string) ($row['position'] ?? ''));

                return [
                    (int) $row['company_id'] => [
                        'position' => $position !== '' ? $position : null,
                    ],
                ];
            })
            ->all();

        DB::transaction(function () use ($contactIds, $payload): void {
            Contact::query()
                ->whereIn('id', $contactIds)
                ->get()
                ->each(fn (Contact $bucketContact) => $bucketContact->companies()->sync($payload));
        });

        return response()->json([
            'success' => true,
            'companies' => Company::query()
                ->whereKey(TenantCompany::id())
                ->whereIn('id', array_keys($payload))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Company $company) => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'position' => $payload[$company->id]['position'] ?? null,
                ])
                ->values(),
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function contactBucketIds(Contact $contact): array
    {
        $digits = $this->normalizedDigits((string) ($contact->phone_number ?: $contact->whatsapp_id ?: ''));
        if ($digits === '') {
            return [(int) $contact->id];
        }

        return Contact::query()
            ->where(function ($q) use ($digits): void {
                $q->where('phone_number', $digits)
                    ->orWhere('whatsapp_id', 'like', "%{$digits}%");
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function displayNameForContact(Contact $contact, ?Chat $latestChat): string
    {
        $name = trim((string) ($contact->name ?? ''))
            ?: trim((string) ($contact->push_name ?? ''))
            ?: trim((string) ($latestChat?->chat_name ?? ''))
            ?: trim((string) ($contact->phone_number ?? ''));

        return $name !== '' ? $name : 'Без имени';
    }

    private function messageIso(?Message $message): ?string
    {
        if ($message === null) {
            return null;
        }

        return ($message->message_timestamp ?: $message->created_at)?->toIso8601String();
    }

    /**
     * @return array{id: int, body: ?string, sender_name: ?string, at: ?string}|null
     */
    private function messagePreview(?Message $message): ?array
    {
        if ($message === null) {
            return null;
        }

        return [
            'id' => $message->id,
            'body' => $message->body,
            'sender_name' => $message->sender_name,
            'at' => $this->messageIso($message),
        ];
    }

    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $phone = PhoneFormatter::normalize((string) $data['phone']);
        if (! $phone) {
            return response()->json(['success' => false, 'error' => 'Некорректный номер.'], 422);
        }

        $name = isset($data['name']) ? trim((string) $data['name']) : null;
        $name = ($name !== '') ? $name : null;

        $contact = Contact::query()->where('phone_number', $phone)->first();
        if (! $contact) {
            $contact = Contact::create([
                'phone_number' => $phone,
                'whatsapp_id' => $phone,
                'name' => $name,
                'push_name' => null,
                'profile_picture_url' => null,
                'is_business' => false,
            ]);
        } else {
            if ($name !== null) {
                $contact->name = $name;
            }
            $contact->saveQuietly();
        }

        return response()->json(['success' => true, 'contact' => $contact]);
    }
}
