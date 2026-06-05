<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EntityMemorySubjectType;
use App\Models\Chat;
use App\Models\Company;
use App\Models\Contact;
use App\Models\FunnelStage;
use App\Models\User;
use App\Services\AI\AiWorkspaceClientSummaryService;
use App\Services\ChatService;
use App\Services\Contact\ContactFieldValueService;
use App\Services\Contact\ClientProfileAiService;
use App\Services\Contact\ClientProfileAssembler;
use App\Services\Contact\ClientsListService;
use App\Services\Contact\ContactBucketResolver;
use App\Services\Contact\ContactCardAssembler;
use App\Services\Contact\ContactListFilterService;
use App\Services\Memory\EntityMemoryService;
use App\Support\ContactListFilters;
use App\Support\NavSectionAccess;
use App\Support\PhoneFormatter;
use App\Support\TenantCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
        private readonly ContactFieldValueService $contactFieldValues,
        private readonly ContactListFilterService $contactListFilters,
        private readonly ClientsListService $clientsListService,
        private readonly ChatService $chatService,
        private readonly EntityMemoryService $entityMemory,
    ) {}

    public function settingsIndex(Request $request): RedirectResponse
    {
        return redirect()->route('clients.index', $this->clientsRedirectParams($request));
    }

    public function clientsIndex(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user, 403);
        NavSectionAccess::assertModuleEnabled('module_clients');

        $search = trim((string) $request->input('search', ''));
        $listFilters = ContactListFilters::fromRequest($request);
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

        $clientsPaginator = $this->clientsListService->paginate(
            user: $user,
            search: $search,
            listFilters: $listFilters,
            page: $clientsPage,
            perPage: $clientsPerPage,
            pageName: 'clients_page',
        );

        return Inertia::render('Clients/Index', [
            'search' => $search,
            'filters' => $listFilters->values,
            'filterFields' => $this->contactListFilters->filterableFieldDefinitions(),
            'funnelStages' => FunnelStage::query()
                ->whereHas('funnel', fn ($funnelQuery) => $funnelQuery->where('company_id', TenantCompany::id()))
                ->where('is_active', true)
                ->orderBy('funnel_id')
                ->orderBy('position')
                ->get(['id', 'name', 'color'])
                ->map(fn (FunnelStage $stage) => [
                    'id' => $stage->id,
                    'name' => $stage->name,
                    'color' => $stage->color,
                ])
                ->values()
                ->all(),
            'assigneeOptions' => User::query()
                ->where('company_id', TenantCompany::id())
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $assignee) => [
                    'id' => $assignee->id,
                    'name' => $assignee->name,
                ])
                ->values()
                ->all(),
            'activeTab' => $activeTab,
            'clients' => $this->paginationPayload($clientsPaginator),
            'companies' => $companiesPaginator !== null
                ? $this->paginationPayload($companiesPaginator)
                : $this->emptyPagination(),
            'companyOptions' => $companyOptions,
            'canManageCompanies' => $user->hasRole('administrator'),
            'canManageContactFields' => $user->hasRole('administrator'),
            'canClearClientData' => $user->hasAnyRole(['administrator', 'manager']),
        ]);
    }

    public function clearClientMemory(Request $request, Contact $contact): JsonResponse
    {
        $this->authorize('clearData', $contact);

        $user = $request->user();
        abort_unless($user, 403);

        foreach ($this->contactBucketResolver->bucketIds($contact) as $contactId) {
            $this->entityMemory->clear(EntityMemorySubjectType::Contact, $contactId, $user);
        }

        return response()->json(['success' => true]);
    }

    public function clearClientChat(Request $request, Contact $contact, Chat $chat): JsonResponse
    {
        $this->authorize('clearData', $contact);
        abort_if($chat->is_group, 422, 'Нельзя очистить групповой чат из карточки клиента.');

        $bucketIds = $this->contactBucketResolver->bucketIds($contact);
        abort_unless(in_array((int) $chat->contact_id, $bucketIds, true), 404);

        $this->authorize('manage', $chat);
        $this->chatService->clearChatMessages($chat);

        return response()->json(['success' => true]);
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
        $filterParams = ContactListFilters::fromRequest($request)->toQueryParams();
        if ($filterParams !== []) {
            $params = array_merge($params, $filterParams);
        }
        foreach (['clients_page', 'companies_page'] as $key) {
            if ($request->filled($key)) {
                $params[$key] = $request->input($key);
            }
        }

        return $params;
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

    public function updateFields(Request $request, Contact $contact): JsonResponse
    {
        $this->authorize('update', $contact);
        abort_unless($request->user()?->hasRole('administrator'), 403);

        $data = $request->validate([
            'fields' => ['required', 'array'],
            'fields.*.field_id' => ['required', 'integer'],
            'fields.*.value' => ['nullable'],
        ]);

        $this->contactFieldValues->upsertForContact($contact, $data['fields']);

        $profile = $this->clientProfileAssembler->build($request->user(), $contact);

        return response()->json([
            'profile' => $profile,
            'contact' => [
                'id' => $contact->id,
                'profile_picture_url' => $contact->fresh()?->profile_picture_url,
            ],
        ]);
    }

    public function uploadFieldFile(Request $request, Contact $contact, int $fieldDefinition): JsonResponse
    {
        $this->authorize('update', $contact);
        abort_unless($request->user()?->hasRole('administrator'), 403);

        $definition = \App\Models\ContactFieldDefinition::query()
            ->where('company_id', TenantCompany::id())
            ->whereKey($fieldDefinition)
            ->firstOrFail();

        $rules = $definition->type === \App\Support\ContactFieldType::PHOTO
            ? ['file' => ['required', 'file', 'image', 'max:5120']]
            : ['file' => ['required', 'file', 'max:10240']];

        $data = $request->validate($rules);

        $upload = $this->contactFieldValues->uploadForDefinition(
            $contact,
            $definition,
            $data['file'],
        );

        $contact->refresh();
        $profile = $this->clientProfileAssembler->build($request->user(), $contact);

        return response()->json([
            'profile' => $profile,
            'upload' => $upload,
            'contact' => [
                'id' => $contact->id,
                'profile_picture_url' => $contact->profile_picture_url,
            ],
        ]);
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
        $this->authorize('create', Contact::class);

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
