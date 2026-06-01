<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\SuperAdmin\DemoTenantPopulationService;
use App\Services\SuperAdmin\SuperAdminCompanyScope;
use App\Services\Tenancy\CompanyProvisioningService;
use Illuminate\Console\Command;
final class ProvisionSalesSandboxSuperAdminCommand extends Command
{
    protected $signature = 'super-admin:provision-sales-sandbox
                            {email : Email супер-админа (песочница)}
                            {--slug=alim : Slug тестового тенанта}
                            {--name=Песочница продаж : Название компании}
                            {--owner-email= : Email владельца тенанта (по умолчанию admin@{slug}.test)}
                            {--owner-password=Admin123 : Пароль владельца тенанта}
                            {--populate : Заполнить чаты, воронку и каталог}';

    protected $description = 'Назначить супер-админа «песочница» и создать изолированную тестовую компанию';

    public function handle(
        CompanyProvisioningService $provisioning,
        DemoTenantPopulationService $demoPopulation,
    ): int {
        $email = strtolower(trim((string) $this->argument('email')));
        $slug = strtolower(trim((string) $this->option('slug')));
        $ownerEmail = trim((string) $this->option('owner-email'));
        if ($ownerEmail === '') {
            $ownerEmail = "admin@{$slug}.test";
        }

        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $this->error("Пользователь {$email} не найден.");

            return self::FAILURE;
        }

        $user->forceFill([
            'is_super_admin' => true,
            'super_admin_scope' => SuperAdminCompanyScope::SCOPE_SANDBOX,
            'company_id' => null,
        ])->save();

        $company = Company::query()
            ->withoutGlobalScope('tenant')
            ->where('slug', $slug)
            ->first();

        if ($company === null) {
            $result = $provisioning->create([
                'name' => (string) $this->option('name'),
                'slug' => $slug,
                'phone' => '+77001230130',
                'owner_name' => 'Админ теста',
                'owner_email' => $ownerEmail,
                'owner_password' => (string) $this->option('owner-password'),
            ]);
            $company = $result['company'];
            $this->info("Создана компания: {$company->name} ({$company->slug})");
            $this->line("Владелец тенанта: {$ownerEmail} / ".(string) $this->option('owner-password'));
        } else {
            $this->warn("Компания со slug «{$slug}» уже есть (id={$company->id}).");
        }

        $company->update(['provisioned_by_user_id' => $user->id]);

        if ($this->option('populate')) {
            $demoPopulation->populateCompany($company->fresh(), $user);
            $this->info('Тестовые данные загружены (чаты, воронка, WhatsApp-сессии).');
        }

        $this->newLine();
        $this->info('Готово.');
        $this->line("Суперадминка (только свои компании): https://app.".config('tenancy.root_domain', 'accel.kz'));
        $this->line("Логин: {$email}");
        $this->line("Тенант для теста: https://{$slug}.".config('tenancy.root_domain', 'accel.kz'));
        $this->line("Вход в тенант: {$ownerEmail} / ".(string) $this->option('owner-password'));

        return self::SUCCESS;
    }
}
