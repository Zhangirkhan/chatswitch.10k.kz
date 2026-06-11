<?php

declare(strict_types=1);

namespace App\Services\SuperAdmin;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

final class CompanyExportService
{
    private const CHUNK_SIZE = 200;

    /** @var list<string> */
    private const HEADERS = [
        'ID',
        'Название',
        'Tenant (slug)',
        'URL',
        'БИН',
        'Юр. адрес',
        'Вид деятельности',
        'Телефон',
        'Email',
        'Сайт',
        'Описание',
        'Активна',
        'AI-акции',
        'Тариф',
        'Код тарифа',
        'Цена (₸)',
        'Интервал',
        'Статус подписки',
        'Trial до',
        'Период до',
        'Дней trial осталось',
        'MRR (₸)',
        'Просроченных счетов',
        'Владелец',
        'Email владельца',
        'Пользователей',
        'WhatsApp-сессий',
        'Создана',
        'Обновлена',
    ];

    public function __construct(
        private readonly SuperAdminCompanyScope $superAdminScope,
        private readonly CompanyDemoMaintenanceService $demoMaintenance,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function exportToStream(User $user, array $filters, string $stream): void
    {
        $writer = new Writer;
        $writer->openToFile($stream);
        $writer->addRow(Row::fromValues(self::HEADERS));

        $this->filteredQuery($user, $filters)
            ->chunkById(self::CHUNK_SIZE, function ($companies) use ($writer): void {
                /** @var list<int> $companyIds */
                $companyIds = $companies->pluck('id')->all();
                $overdueCounts = $this->overdueInvoiceCounts($companyIds);

                foreach ($companies as $company) {
                    $writer->addRow(Row::fromValues($this->rowValues(
                        $company,
                        (int) ($overdueCounts[$company->id] ?? 0),
                    )));
                }
            }, column: 'companies.id', alias: 'id');

        $writer->close();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Company>
     */
    public function filteredQuery(User $user, array $filters): Builder
    {
        $demoSlug = $this->demoMaintenance->demoSlug();

        $query = $this->superAdminScope->applyToCompaniesQuery(
            Company::query()
                ->select('companies.*')
                ->with(['plan:id,code,name,price_cents,interval', 'owner:id,name,email'])
                ->withCount(['users', 'whatsappSessions']),
            $user,
        );

        if ($this->superAdminScope->isGlobalSuperAdmin($user)) {
            $query->where('companies.slug', '!=', $demoSlug);
        }

        if (! empty($filters['q'])) {
            $term = '%'.addcslashes((string) $filters['q'], '%_\\').'%';
            $query->where(function ($q) use ($term): void {
                $q->where('companies.name', 'like', $term)
                    ->orWhere('companies.slug', 'like', $term)
                    ->orWhereHas('owner', fn ($oq) => $oq->where('email', 'like', $term)->orWhere('name', 'like', $term));
            });
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
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

        return $query;
    }

    /**
     * @param  list<int>  $companyIds
     * @return array<int, int>
     */
    private function overdueInvoiceCounts(array $companyIds): array
    {
        if ($companyIds === []) {
            return [];
        }

        return Invoice::query()
            ->whereIn('company_id', $companyIds)
            ->where('status', 'issued')
            ->selectRaw('company_id, COUNT(*) as aggregate')
            ->groupBy('company_id')
            ->pluck('aggregate', 'company_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @return list<string|int|float|null>
     */
    private function rowValues(Company $company, int $overdueInvoices): array
    {
        $plan = $company->plan;
        $mrrKzt = $company->subscription_status === 'active' && $plan !== null
            ? round($plan->price_cents / 100, 2)
            : 0.0;

        $trialDaysLeft = null;
        if ($company->subscription_status === 'trial' && $company->trial_ends_at !== null) {
            $trialDaysLeft = max(0, (int) now()->diffInDays($company->trial_ends_at, false));
        }

        $rootDomain = (string) config('tenancy.root_domain', 'accel.kz');
        $scheme = config('app.env') === 'production' ? 'https' : 'http';

        return [
            $company->id,
            $company->name,
            $company->slug,
            $scheme.'://'.$company->slug.'.'.$rootDomain.'/',
            $company->bin,
            $company->legal_address,
            $company->business_activity,
            $company->phone,
            $company->email,
            $company->website,
            $company->description,
            $company->is_active ? 'Да' : 'Нет',
            $company->ai_promotions_enabled ? 'Да' : 'Нет',
            $plan?->name,
            $plan?->code,
            $plan !== null ? round($plan->price_cents / 100, 2) : null,
            $plan?->interval,
            $company->subscription_status,
            $company->trial_ends_at?->format('d.m.Y'),
            $company->current_period_ends_at?->format('d.m.Y'),
            $trialDaysLeft,
            $mrrKzt,
            $overdueInvoices,
            $company->owner?->name,
            $company->owner?->email,
            $company->users_count,
            $company->whatsapp_sessions_count,
            $company->created_at?->format('d.m.Y H:i'),
            $company->updated_at?->format('d.m.Y H:i'),
        ];
    }
}
