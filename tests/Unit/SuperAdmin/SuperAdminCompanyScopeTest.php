<?php

declare(strict_types=1);

namespace Tests\Unit\SuperAdmin;

use App\Models\Company;
use App\Models\User;
use App\Services\SuperAdmin\SuperAdminCompanyScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SuperAdminCompanyScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_sandbox_super_admin_sees_only_provisioned_companies(): void
    {
        $scope = new SuperAdminCompanyScope;

        $sandbox = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
        ]);
        $sandbox->forceFill(['super_admin_scope' => SuperAdminCompanyScope::SCOPE_SANDBOX])->save();

        $mine = Company::query()->create([
            'name' => 'Mine',
            'slug' => 'mine-sandbox-test',
            'phone' => '+77001110001',
            'is_active' => true,
            'provisioned_by_user_id' => $sandbox->id,
        ]);
        Company::query()->create([
            'name' => 'Other',
            'slug' => 'other-sandbox-test',
            'phone' => '+77001110002',
            'is_active' => true,
        ]);

        $ids = $scope
            ->applyToCompaniesQuery(Company::query(), $sandbox)
            ->pluck('id')
            ->all();

        $this->assertSame([$mine->id], $ids);
        $this->assertTrue($scope->canManage($sandbox, $mine));
    }
}
