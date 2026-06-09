<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class UsersApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_users_returns_active_colleagues_in_tenant_envelope(): void
    {
        $company = $this->createTenantCompany(['slug' => 'staff-co']);

        $manager = User::factory()->create(['company_id' => $company->id, 'name' => 'Manager One']);
        $manager->assignRole('manager');

        $peer = User::factory()->create([
            'company_id' => $company->id,
            'name' => 'Айгуль Менеджер',
            'email' => 'aigul@company.kz',
        ]);
        $peer->assignRole('employee');

        User::factory()->create([
            'company_id' => $company->id,
            'is_active' => false,
            'name' => 'Inactive',
        ]);

        $otherTenant = Company::query()->create([
            'name' => 'Other',
            'slug' => 'other-co',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);
        User::factory()->create(['company_id' => $otherTenant->id, 'name' => 'Stranger']);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $manager->email,
            'password' => 'password',
        ])->assertOk()->json('token');

        $response = $this->withToken($token)->getJson('/api/v1/users');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $peer->id);
        $response->assertJsonPath('data.0.name', 'Айгуль Менеджер');
        $response->assertJsonPath('data.0.email', 'aigul@company.kz');
        $this->assertCount(1, $response->json('data'));
    }

    public function test_staff_alias_matches_users(): void
    {
        $company = $this->createTenantCompany(['slug' => 'staff-alias']);

        $employee = User::factory()->create(['company_id' => $company->id]);
        $employee->assignRole('employee');

        $colleague = User::factory()->create(['company_id' => $company->id, 'name' => 'Colleague']);
        $colleague->assignRole('employee');

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $employee->email,
            'password' => 'password',
        ])->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/v1/staff')
            ->assertOk()
            ->assertJsonPath('data.0.id', $colleague->id)
            ->assertJsonPath('data.0.name', 'Colleague');
    }

    public function test_manager_sees_all_colleagues_not_only_self(): void
    {
        $company = $this->createTenantCompany(['slug' => 'mgr-all']);

        $manager = User::factory()->create(['company_id' => $company->id, 'name' => 'Mgr']);
        $manager->assignRole('manager');

        $a = User::factory()->create(['company_id' => $company->id, 'name' => 'Alice']);
        $a->assignRole('employee');
        $b = User::factory()->create(['company_id' => $company->id, 'name' => 'Bob']);
        $b->assignRole('administrator');

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $manager->email,
            'password' => 'password',
        ])->assertOk()->json('token');

        $ids = collect($this->withToken($token)->getJson('/api/v1/users')->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
        $this->assertNotContains($manager->id, $ids);
    }

    public function test_users_requires_authentication(): void
    {
        $this->getJson('/api/v1/users')->assertUnauthorized();
    }
}
