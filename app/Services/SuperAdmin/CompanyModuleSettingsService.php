<?php

declare(strict_types=1);

namespace App\Services\SuperAdmin;

use App\Models\Company;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\CompanyModules;

final class CompanyModuleSettingsService
{
    public function __construct(
        private readonly SuperAdminAuditLogger $audit,
    ) {}

  /**
     * @return list<array{key: string, label: string, description: string, enabled: bool}>
     */
    public function payloadFor(Company $company): array
    {
        $this->ensureDefaults($company);

        $stored = SystemSetting::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $company->id)
            ->whereIn('key', CompanyModules::keys())
            ->pluck('value', 'key');

        $items = [];
        foreach (CompanyModules::definitions() as $key => $definition) {
            $items[] = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'enabled' => ($stored[$key] ?? 'on') === 'on',
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, bool>  $modules
     */
    public function update(Company $company, array $modules, ?User $actor = null): void
    {
        $this->ensureDefaults($company);

        $changes = [];

        foreach ($modules as $key => $enabled) {
            if (! is_string($key) || ! CompanyModules::isModuleKey($key)) {
                continue;
            }

            $value = $enabled ? 'on' : 'off';
            $previous = SystemSetting::query()
                ->withoutGlobalScope('tenant')
                ->where('company_id', $company->id)
                ->where('key', $key)
                ->value('value');

            SystemSetting::setValue($key, $value, $company->id);

            if ($previous !== $value) {
                $changes[] = CompanyModules::definitions()[$key]['label'].': '.($enabled ? 'вкл' : 'выкл');
            }
        }

        if ($changes !== []) {
            $this->audit->log($company, $actor, 'company.modules_updated', $company, [
                'company_name' => $company->name,
                'changes' => $changes,
            ]);
        }
    }

    public function ensureDefaults(Company $company): void
    {
        foreach (CompanyModules::defaultValues() as $key => $value) {
            SystemSetting::query()
                ->withoutGlobalScope('tenant')
                ->firstOrCreate(
                    ['company_id' => $company->id, 'key' => $key],
                    ['value' => $value],
                );
        }
    }
}
