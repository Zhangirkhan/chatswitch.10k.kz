<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\Department;
use App\Models\User;
use App\Services\AI\AiWorkspaceAccessService;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AiWorkspaceAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    private AiWorkspaceAccessService $access;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }

        $this->access = app(AiWorkspaceAccessService::class);
    }

    public function test_manager_can_view_department_peer(): void
    {
        $companyId = TenantCompany::id();
        $dept = Department::query()->create(['name' => 'Продажи', 'is_active' => true]);

        $manager = User::factory()->create(['company_id' => $companyId]);
        $manager->assignRole('manager');
        $manager->departments()->sync([$dept->id]);

        $peer = User::factory()->create(['company_id' => $companyId, 'name' => 'Михаил']);
        $peer->assignRole('employee');
        $peer->departments()->sync([$dept->id]);

        $this->assertTrue($this->access->canViewEmployee($manager, $peer));
    }

    public function test_employee_cannot_view_colleague(): void
    {
        $companyId = TenantCompany::id();
        $dept = Department::query()->create(['name' => 'Продажи', 'is_active' => true]);

        $employee = User::factory()->create(['company_id' => $companyId]);
        $employee->assignRole('employee');
        $employee->departments()->sync([$dept->id]);

        $colleague = User::factory()->create(['company_id' => $companyId, 'name' => 'Михаил']);
        $colleague->assignRole('employee');
        $colleague->departments()->sync([$dept->id]);

        $this->assertFalse($this->access->canViewEmployee($employee, $colleague));
        $this->assertSame([], $this->access->resolveEmployeesByName($employee, 'Михаил'));
    }

    public function test_employee_can_list_department_colleagues(): void
    {
        $companyId = TenantCompany::id();
        $dept = Department::query()->create(['name' => 'Продажи', 'is_active' => true]);

        $employee = User::factory()->create(['company_id' => $companyId]);
        $employee->assignRole('employee');
        $employee->departments()->sync([$dept->id]);

        $colleague = User::factory()->create(['company_id' => $companyId, 'name' => 'Михаил']);
        $colleague->assignRole('employee');
        $colleague->departments()->sync([$dept->id]);

        $list = $this->access->listDepartmentColleagues($employee);

        $this->assertCount(2, $list);
        $this->assertContains('Михаил', array_column($list, 'name'));
    }
}
