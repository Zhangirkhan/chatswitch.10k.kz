<?php

declare(strict_types=1);

namespace App\Services\SuperAdmin;

use App\Models\Company;
use App\Models\User;
use App\Services\Tenancy\CompanyProvisioningService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

final class CompanyDemoMaintenanceService
{
    public function __construct(
        private readonly CompanyProvisioningService $provisioning,
        private readonly SuperAdminAuditLogger $audit,
    ) {}

    public function demoSlug(): string
    {
        return (string) config('tenancy.fallback_slug', 'demo');
    }

    public function isDemoCompany(Company $company): bool
    {
        return $company->slug === $this->demoSlug();
    }

    public function findDemoCompany(): ?Company
    {
        return Company::query()
            ->with(['plan:id,name', 'owner:id,name,email'])
            ->where('slug', $this->demoSlug())
            ->first();
    }

    /**
     * @return list<array{name: string, slug: string, phone: string, owner_name: string, owner_email: string, subscription_status?: string}>
     */
    public function testCompanyFixtures(): array
    {
        return [
            [
                'name' => 'Кофейня «Утро»',
                'slug' => 'kofeynya-utro',
                'phone' => '+77001234501',
                'owner_name' => 'Айгуль Нурланова',
                'owner_email' => 'owner@kofeynya-utro.test',
            ],
            [
                'name' => 'Агентство «Брокер»',
                'slug' => 'agentstvo-broker',
                'phone' => '+77001234502',
                'owner_name' => 'Марат Касымов',
                'owner_email' => 'owner@agentstvo-broker.test',
            ],
            [
                'name' => 'Салон «Линия»',
                'slug' => 'salon-liniya',
                'phone' => '+77001234503',
                'owner_name' => 'Дина Серикова',
                'owner_email' => 'owner@salon-liniya.test',
            ],
            [
                'name' => 'Медцентр «Здоровье»',
                'slug' => 'medtsentr-zdorove',
                'phone' => '+77001234504',
                'owner_name' => 'Ерлан Беков',
                'owner_email' => 'owner@medtsentr-zdorove.test',
            ],
            [
                'name' => 'ООО «Учебный центр»',
                'slug' => 'uchebny-tsentr',
                'phone' => '+77001234505',
                'owner_name' => 'Светлана Орлова',
                'owner_email' => 'owner@uchebny-tsentr.test',
            ],
            [
                'name' => 'Студия красоты «Glow»',
                'slug' => 'glow-studio',
                'phone' => '+77001234506',
                'owner_name' => 'Алина Жумабекова',
                'owner_email' => 'owner@glow-studio.test',
            ],
        ];
    }

    public function seedTestCompanies(?User $actor = null): int
    {
        $created = 0;

        foreach ($this->testCompanyFixtures() as $fixture) {
            if (Company::query()->where('slug', $fixture['slug'])->exists()) {
                continue;
            }

            $result = $this->provisioning->create($fixture);
            $created++;

            $this->audit->log($result['company'], $actor, 'company.created', $result['company'], [
                'company_name' => $result['company']->name,
                'slug' => $result['company']->slug,
                'source' => 'test_seed',
                'owner_email' => $fixture['owner_email'],
            ]);
        }

        return $created;
    }

    public function deleteAllExceptDemo(?User $actor = null): int
    {
        $demoSlug = $this->demoSlug();

        $deleted = DB::transaction(function () use ($demoSlug, $actor): int {
            $companies = Company::query()
                ->where('slug', '!=', $demoSlug)
                ->orderBy('id')
                ->get();

            $count = 0;

            foreach ($companies as $company) {
                $this->deleteCompanyWithinTransaction($company, $actor, 'bulk_delete');
                $count++;
            }

            return $count;
        });

        $this->syncNginxKnownTenantsMap();

        return $deleted;
    }

    public function deleteCompany(Company $company, ?User $actor = null): void
    {
        if ($this->isDemoCompany($company)) {
            throw new \InvalidArgumentException('Демо-тенант удалять нельзя.');
        }

        DB::transaction(function () use ($company, $actor): void {
            $this->deleteCompanyWithinTransaction($company, $actor, 'single_delete');
        });

        $this->syncNginxKnownTenantsMap();
    }

    private function deleteCompanyWithinTransaction(Company $company, ?User $actor, string $source): void
    {
        User::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $company->id)
            ->where('is_super_admin', false)
            ->delete();

        $name = $company->name;
        $slug = $company->slug;
        $companyId = $company->id;

        $company->delete();

        $this->audit->log(null, $actor, 'company.deleted', null, [
            'company_id' => $companyId,
            'company_name' => $name,
            'slug' => $slug,
            'source' => $source,
        ]);
    }

    private function syncNginxKnownTenantsMap(): void
    {
        try {
            Artisan::call('tenants:sync-nginx-map');
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
