<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Plan;
use App\Models\SuperAdminAuditLog;
use App\Models\WhatsappSession;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\SuperAdmin\CompanyBillingSummaryService;
use App\Services\SuperAdmin\CompanyDemoMaintenanceService;
use App\Services\SuperAdmin\CompanyModuleSettingsService;
use App\Services\SuperAdmin\CompanyUsersService;
use App\Services\SuperAdmin\SuperAdminAuditLogger;
use App\Services\SuperAdmin\TenantImpersonationService;
use App\Services\Tenancy\CompanyProvisioningService;
use App\Services\WhatsappService;
use App\Support\PhoneFormatter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CompanyController extends Controller
{
    public function __construct(
        private readonly CompanyDemoMaintenanceService $demoMaintenance,
        private readonly CompanyModuleSettingsService $moduleSettings,
        private readonly CompanyBillingSummaryService $billingSummary,
        private readonly WhatsappService $whatsapp,
        private readonly SuperAdminAuditLogger $audit,
        private readonly TenantImpersonationService $impersonation,
        private readonly CompanyUsersService $companyUsers,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'string', 'in:1,0'],
            'subscription_status' => ['nullable', 'string', 'in:trial,active,past_due,suspended,canceled'],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'sort' => ['nullable', 'string', 'in:created_desc,created_asc,name'],
        ]);

        $demoSlug = $this->demoMaintenance->demoSlug();

        $query = Company::query()
            ->with(['plan:id,name', 'owner:id,name,email'])
            ->where('slug', '!=', $demoSlug);

        if (! empty($filters['q'])) {
            $term = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', $term)
                    ->orWhere('slug', 'like', $term)
                    ->orWhereHas('owner', fn ($oq) => $oq->where('email', 'like', $term)->orWhere('name', 'like', $term));
            });
        }

        if (isset($filters['is_active'])) {
            $query->where('companies.is_active', $filters['is_active'] === '1');
        }

        if (! empty($filters['subscription_status'])) {
            $query->where('companies.subscription_status', $filters['subscription_status']);
        }

        if (! empty($filters['plan_id'])) {
            $query->where('companies.plan_id', (int) $filters['plan_id']);
        }

        match ($filters['sort'] ?? 'created_desc') {
            'created_asc' => $query->orderBy('companies.id'),
            'name' => $query->orderBy('companies.name'),
            default => $query->orderByDesc('companies.id'),
        };

        $companies = $query->paginate(20)->withQueryString();

        $companies->getCollection()->transform(fn (Company $company): Company => $this->decorateCompanyForIndex($company));

        $demoCompany = $this->demoMaintenance->findDemoCompany();
        if ($demoCompany !== null) {
            $demoCompany = $this->decorateCompanyForIndex($demoCompany);
        }

        return Inertia::render('SuperAdmin/Companies/Index', [
            'companies' => $companies,
            'demoCompany' => $demoCompany,
            'demoSlug' => $demoSlug,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'is_active' => $filters['is_active'] ?? '',
                'subscription_status' => $filters['subscription_status'] ?? '',
                'plan_id' => isset($filters['plan_id']) ? (string) $filters['plan_id'] : '',
                'sort' => $filters['sort'] ?? 'created_desc',
            ],
            'plans' => Plan::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('SuperAdmin/Companies/Create', [
            'plans' => Plan::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'reservedSlugs' => config('tenancy.reserved_slugs', []),
        ]);
    }

    public function store(Request $request, CompanyProvisioningService $provisioning): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9-]+$/', Rule::notIn(config('tenancy.reserved_slugs', [])), Rule::unique('companies', 'slug')],
            'phone' => ['required', 'string', 'max:32', 'regex:/^[\d\s+\-()]+$/'],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:160'],
        ]);

        $data['phone'] = PhoneFormatter::normalize($data['phone']);
        if ($data['phone'] === null || strlen($data['phone']) < 7) {
            return back()->withErrors(['phone' => 'Укажите корректный номер телефона.'])->withInput();
        }

        $result = $provisioning->create($data);

        $company = $result['company'];
        $this->audit->log($company, $request->user(), 'company.created', $company, [
            'company_name' => $company->name,
            'slug' => $company->slug,
            'source' => 'manual',
            'owner_email' => $data['owner_email'],
        ]);

        return redirect()
            ->route('super.companies.show', $result['company'])
            ->with('success', 'Компания создана. Временный пароль владельца: '.$result['temporary_password']);
    }

    public function show(Company $company): Response
    {
        $company->load([
            'plan',
            'owner:id,name,email,created_at',
            'subscriptions' => fn ($q) => $q->with('plan')->orderByDesc('started_at')->orderByDesc('id'),
        ]);
        $company->loadCount(['users', 'subscriptions', 'invoices', 'whatsappSessions']);

        $usersPayload = $this->companyUsers->payloadForCompany($company);
        $companyUsers = $this->companyUsers->markOwner(
            $usersPayload['users'],
            $company->owner_user_id,
        );

        $invoices = $company->invoices()
            ->with(['payments' => fn ($q) => $q->orderByDesc('paid_at')])
            ->orderByDesc('id')
            ->get();

        $whatsappSessions = WhatsappSession::query()
            ->where('company_id', $company->id)
            ->orderBy('id')
            ->get();

        $auditLogs = SuperAdminAuditLog::query()
            ->where('company_id', $company->id)
            ->with('actor:id,name,email')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return Inertia::render('SuperAdmin/Companies/Show', [
            'company' => $company,
            'companyUsers' => $companyUsers,
            'companyDepartments' => $usersPayload['departments'],
            'companyWhatsappSessions' => $usersPayload['whatsapp_sessions'],
            'companyModules' => $this->moduleSettings->payloadFor($company),
            'invoices' => $invoices,
            'billingSummary' => $this->billingSummary->forCompany($company),
            'whatsappSessions' => $whatsappSessions,
            'whatsappServiceReachable' => $this->whatsapp->healthReachable(),
            'whatsappMaxSessions' => (int) config('billing.default_max_whatsapp_sessions', 5),
            'auditLogs' => $auditLogs,
            'tenantUrl' => $company->tenantUrl('/login'),
            'canImpersonate' => $this->impersonation->canImpersonate($company),
            'impersonateBlockedReason' => $this->impersonation->impersonationBlockedReason($company),
            'plans' => Plan::query()->where('is_active', true)->orderBy('name')->get(),
            'billing' => [
                'trial_days' => (int) config('billing.trial_days', 14),
                'standard_price_label' => Plan::query()
                    ->where('code', config('billing.default_plan_code', 'standard'))
                    ->first()
                    ?->pricePerMonthLabel() ?? '40 000 ₸ / мес.',
                'seller' => config('billing.seller'),
            ],
        ]);
    }

    public function update(Request $request, Company $company, SubscriptionLifecycleService $subscriptions): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[\d\s+\-()]+$/'],
            'is_active' => ['boolean'],
            'subscription_status' => ['required', 'string', Rule::in(['trial', 'active', 'past_due', 'suspended', 'canceled'])],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'trial_ends_at' => ['nullable', 'date'],
            'current_period_ends_at' => ['nullable', 'date'],
        ]);

        if (array_key_exists('phone', $data)) {
            $normalized = PhoneFormatter::normalize($data['phone'] ?? null);
            $data['phone'] = $normalized;
        }

        $trackedFields = ['name', 'phone', 'is_active', 'subscription_status', 'plan_id', 'trial_ends_at', 'current_period_ends_at'];
        $before = $company->only($trackedFields);

        $previousPlanId = $company->plan_id;
        $company->update($data);

        $fresh = $company->fresh();
        $after = $fresh->only($trackedFields);
        $changes = $this->describeCompanyChanges($before, $after);

        if ($changes !== []) {
            $this->audit->log($fresh, $request->user(), 'company.updated', $fresh, [
                'company_name' => $fresh->name,
                'changes' => $changes,
            ]);
        }

        if (
            isset($data['plan_id'])
            && (int) $data['plan_id'] !== (int) $previousPlanId
            && $data['plan_id'] !== null
        ) {
            $plan = Plan::query()->findOrFail((int) $data['plan_id']);
            $restartTrial = $data['subscription_status'] === 'trial';
            $subscriptions->changePlan($fresh, $plan, $restartTrial);

            $this->audit->log($fresh, $request->user(), 'subscription.plan_changed', $plan, [
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'restart_trial' => $restartTrial,
                'source' => 'company_update',
            ]);
        }

        return back()->with('success', 'Компания обновлена.');
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    private function describeCompanyChanges(array $before, array $after): array
    {
        $labels = [
            'name' => 'название',
            'phone' => 'телефон',
            'is_active' => 'активность',
            'subscription_status' => 'статус подписки',
            'plan_id' => 'тариф',
            'trial_ends_at' => 'окончание триала',
            'current_period_ends_at' => 'конец периода',
        ];

        $changes = [];
        foreach ($labels as $field => $label) {
            $prev = $before[$field] ?? null;
            $next = $after[$field] ?? null;
            if ((string) $prev !== (string) $next) {
                if ($field === 'is_active') {
                    $changes[] = $label.': '.($next ? 'да' : 'нет');
                } else {
                    $changes[] = $label;
                }
            }
        }

        return $changes;
    }

    /**
     * Быстрое включение/отключение тенанта без полной формы.
     * При is_active=false на поддомене slug.accel.kz будет показана
     * страница «Сайт отключён» (см. EnsureActiveCompany middleware).
     */
    public function toggleActive(Request $request, Company $company): RedirectResponse
    {
        $company->update(['is_active' => ! $company->is_active]);

        $fresh = $company->fresh();
        $this->audit->log($fresh, $request->user(), 'company.tenant_toggled', $fresh, [
            'is_active' => $fresh->is_active,
        ]);

        $msg = $fresh->is_active
            ? 'Тенант включён.'
            : 'Тенант отключён. Поддомен покажет страницу «Сайт отключён».';

        return back()->with('success', $msg);
    }

    private function decorateCompanyForIndex(Company $company): Company
    {
        $company->setAttribute('can_impersonate', $this->impersonation->canImpersonate($company));
        $company->setAttribute(
            'impersonate_blocked_reason',
            $this->impersonation->impersonationBlockedReason($company),
        );

        return $company;
    }
}
