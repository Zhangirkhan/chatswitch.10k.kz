<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Services\AI\AiWorkspaceClientSummaryService;
use App\Services\Contact\ClientProfileAiService;
use App\Services\Contact\ClientProfileAssembler;
use App\Services\Contact\ContactBucketResolver;
use App\Services\Contact\ContactCardAssembler;
use App\Support\PhoneFormatter;
use App\Support\TenantCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class ContactController extends Controller
{
    public function __construct(
        private readonly ContactCardAssembler $contactCardAssembler,
        private readonly ContactBucketResolver $contactBucketResolver,
        private readonly ClientProfileAssembler $clientProfileAssembler,
        private readonly ClientProfileAiService $clientProfileAiService,
    ) {}

    public function settingsIndex(Request $request): RedirectResponse
    {
        return redirect()->route('clients.index', $this->clientsRedirectParams($request));
    }

    public function clientsIndex(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user, 403);

        $search = trim((string) $request->input('search', ''));
        $activeTab = in_array($request->input('tab'), ['clients', 'companies'], true)
            ? (string) $request->input('tab')
            : 'clients';
        if ($activeTab === 'companies' && ! $user->hasRole('administrator')) {
            $activeTab = 'clients';
        }

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

        $companiesPaginator = null;
        $companyOptions = collect();

        if ($user->hasRole('administrator')) {
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
        }

        $clients = $this->buildClientsList($user, $contacts);

        $clientsPaginator = $this->paginateCollection($clients, $clientsPage, $clientsPerPage, 'clients_page');

        return Inertia::render('Clients/Index', [
            'search' => $search,
            'activeTab' => $activeTab,
            'clients' => $this->paginationPayload($clientsPaginator),
            'companies' => $companiesPaginator !== null
                ? $this->paginationPayload($companiesPaginator)
                : $this->emptyPagination(),
            'companyOptions' => $companyOptions,
            'canManageCompanies' => $user->hasRole('administrator'),
        ]);
    }

    public function clientProfile(Request $request, Contact $contact): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->authorize('view', $contact);

        $preferredChatId = $request->filled('chat_id') ? $request->integer('chat_id') : null;
        $profile = $this->clientProfileAssembler->build($user, $contact, $preferredChatId);

        if ($request->boolean('with_ai')) {
            $profile = $this->clientProfileAiService->enrich($user, $contact, $profile, $preferredChatId);
            $profile['ai_enriched'] = true;
        }

        return response()->json(['profile' => $profile]);
    }

    public function clientSummary(Request $request, Contact $contact, AiWorkspaceClientSummaryService $summary): JsonResponse
    {
        $this->authorize('view', $contact);

        $preferredChatId = $request->filled('chat_id') ? $request->integer('chat_id') : null;
        $payload = $summary->build($request->user(), $contact, $preferredChatId);

        return response()->json([
            'client_summary' => $payload,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function clientsRedirectParams(Request $request): array
    {
        $params = [];
        if ($request->input('tab') === 'companies') {
            $params['tab'] = 'companies';
        }
        if ($request->filled('search')) {
            $params['search'] = $request->string('search')->toString();
        }
        foreach (['clients_page', 'companies_page'] as $key) {
            if ($request->filled($key)) {
                $params[$key] = $request->input($key);
            }
        }

        return $params;
    }

    /**
     * @param  Collection<int, Contact>  $contacts
     * @return Collection<int, array<string, mixed>>
     */
    private function buildClientsList(User $user, Collection $contacts): Collection
    {
        return $contacts
            ->groupBy(function (Contact $c) {
                $digits = $this->contactBucketResolver->normalizedDigits((string) ($c->phone_number ?: $c->whatsapp_id ?: ''));

                return $digits !== '' ? "phone:{$digits}" : "contact:{$c->id}";
            })
            ->map(function ($bucket, string $groupKey) use ($user) {
                /** @var Contact $primary */
                $primary = $bucket->first();
                $allChats = $bucket
                    ->flatMap(fn (Contact $c) => $c->chats)
                    ->filter(fn (Chat $chat): bool => $user->can('view', $chat))
                    ->sortByDesc(fn (Chat $chat) => (string) ($chat->last_message_at ?? $chat->updated_at ?? ''));

                if ($allChats->isEmpty()) {
                    return null;
                }

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

    /**
     * @return array<string, mixed>
     */
    private function emptyPagination(): array
    {
        return [
            'data' => [],
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => 12,
            'total' => 0,
            'from' => null,
            'to' => null,
        ];
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

    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('clients.index', $this->clientsRedirectParams($request));
    }

    public function card(Request $request, Contact $contact): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->authorize('view', $contact);

        $preferredChatId = $request->filled('chat_id') ? $request->integer('chat_id') : null;

        return response()->json($this->contactCardAssembler->build($user, $contact, $preferredChatId));
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
        $digits = $this->contactBucketResolver->normalizedDigits((string) ($contact->phone_number ?: $contact->whatsapp_id ?: ''));
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

        $contactIds = $this->contactBucketResolver->bucketIds($contact);
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
