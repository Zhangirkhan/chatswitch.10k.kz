<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Tenancy\TenantDoctorService;
use Illuminate\Console\Command;

final class TenantsDoctorCommand extends Command
{
    protected $signature = 'tenants:doctor
                            {tenant? : slug или ID компании}
                            {--fix : Автоматически починить найденные проблемы}
                            {--all : Проверить все активные компании}
                            {--no-infra : Не проверять инфраструктуру (cron, supervisor, Node)}';

    protected $description = 'Диагностика и починка тенанта: provisioning, permissions, SSL, WhatsApp, readiness';

    public function handle(TenantDoctorService $doctor): int
    {
        $includeInfra = ! $this->option('no-infra');

        if ($this->option('all')) {
            return $this->diagnoseAll($doctor, $includeInfra);
        }

        $tenant = $this->argument('tenant');
        if (! is_string($tenant) || $tenant === '') {
            $this->error('Укажите tenant (slug или ID) или используйте --all.');

            return self::FAILURE;
        }

        $company = $this->resolveCompany($tenant);
        if ($company === null) {
            $this->error("Компания не найдена: {$tenant}");

            return self::FAILURE;
        }

        return $this->diagnoseOne($doctor, $company, $includeInfra);
    }

    private function diagnoseAll(TenantDoctorService $doctor, bool $includeInfra): int
    {
        $companies = Company::query()
            ->withoutGlobalScope('tenant')
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $failed = 0;

        foreach ($companies as $company) {
            $this->newLine();
            $this->line("<fg=cyan>=== {$company->slug} (#{$company->id}) ===</>");
            $exit = $this->diagnoseOne($doctor, $company, $includeInfra, quietHeader: true);
            if ($exit !== self::SUCCESS) {
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Проверено компаний: {$companies->count()}, с critical: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function diagnoseOne(
        TenantDoctorService $doctor,
        Company $company,
        bool $includeInfra,
        bool $quietHeader = false,
    ): int {
        if (! $quietHeader) {
            $this->info("Диагностика тенанта {$company->slug} (#{$company->id})");
        }

        $report = $doctor->diagnose($company, $includeInfra);

        if ($this->option('fix')) {
            $actions = $doctor->fix($company, $report['groups']);
            if ($actions !== []) {
                $this->warn('Применены исправления: '.implode(', ', $actions));
            }
            $report = $doctor->diagnose($company, $includeInfra);
        }

        $this->renderReport($report);

        return $doctor->hasCriticalFailures($report) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array{company_id: int, slug: string, ok: bool, groups: array<string, array{ok: bool, checks: list<array<string, mixed>>}>}  $report
     */
    private function renderReport(array $report): void
    {
        foreach ($report['groups'] as $groupName => $group) {
            $groupLabel = strtoupper($groupName);
            $groupOk = $group['ok'] ?? false;
            $this->line('');
            $this->line($groupOk ? "<fg=green>[{$groupLabel}] OK</>" : "<fg=yellow>[{$groupLabel}] ISSUES</>");

            foreach ($group['checks'] ?? [] as $check) {
                $icon = ($check['ok'] ?? false) ? '✓' : '✗';
                $color = ($check['ok'] ?? false) ? 'green' : (($check['severity'] ?? '') === 'critical' ? 'red' : 'yellow');
                $fixable = ($check['fixable'] ?? false) ? ' [fixable]' : '';
                $this->line("  <fg={$color}>{$icon}</> {$check['message']}{$fixable}");
            }
        }

        $this->newLine();
        if ($report['ok'] ?? false) {
            $this->info('Итог: OK');
        } else {
            $this->error('Итог: есть critical-проблемы'.($this->option('fix') ? '' : ' (запустите с --fix)'));
        }
    }

    private function resolveCompany(string $tenant): ?Company
    {
        if (ctype_digit($tenant)) {
            return Company::query()->withoutGlobalScope('tenant')->find((int) $tenant);
        }

        return Company::query()->withoutGlobalScope('tenant')->where('slug', strtolower($tenant))->first();
    }
}
