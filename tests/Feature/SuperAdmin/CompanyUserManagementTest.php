<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\Company;
use App\Models\User;
use App\Support\TenantRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CompanyUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function adminHost(): string
    {
        return config('tenancy.admin_subdomain', 'app').'.'.config('tenancy.root_domain', 'accel.kz');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('administrator', 'web');
    }

    public function test_super_admin_can_create_tenant_administrator_with_email(): void
    {
        $adminHost = $this->adminHost();
        $this->withServerVariables(['HTTP_HOST' => $adminHost]);
        URL::forceRootUrl('http://'.$adminHost);

        $company = Company::query()->create([
            'name' => 'ESL Test',
            'slug' => 'esl-test-users',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        $super = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
        ]);

        $response = $this->actingAs($super)->post("http://{$adminHost}/companies/{$company->id}/users", [
            'name' => 'Новый админ',
            'email' => 'new-admin@esl-test.kz',
            'password' => 'Secret123',
            'role' => 'administrator',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'company_id' => $company->id,
            'email' => 'new-admin@esl-test.kz',
            'name' => 'Новый админ',
            'is_super_admin' => false,
        ]);

        $user = User::query()->where('company_id', $company->id)->where('email', 'new-admin@esl-test.kz')->firstOrFail();
        setPermissionsTeamId($company->id);
        $this->assertTrue($user->hasRole('administrator'));
    }

    public function test_super_admin_can_update_tenant_user_role_from_admin_host(): void
    {
        $adminHost = $this->adminHost();
        $this->withServerVariables(['HTTP_HOST' => $adminHost]);
        URL::forceRootUrl('http://'.$adminHost);

        $company = Company::query()->create([
            'name' => 'TRX Test',
            'slug' => 'trx-test-users',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        $employee = User::factory()->create([
            'company_id' => $company->id,
            'is_super_admin' => false,
        ]);
        TenantRoles::assign($employee, 'employee');

        $super = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
        ]);

        $response = $this->actingAs($super)->from("http://{$adminHost}/companies/{$company->id}?tab=users")
            ->put("http://{$adminHost}/companies/{$company->id}/users/{$employee->id}", [
                'name' => $employee->name,
                'email' => $employee->email,
                'is_active' => true,
                'role' => 'administrator',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        setPermissionsTeamId($company->id);
        $this->assertTrue($employee->fresh()->hasRole('administrator'));
        $this->assertFalse($employee->fresh()->hasRole('employee'));
    }

    public function test_administrator_requires_email_on_create(): void
    {
        $adminHost = $this->adminHost();

        $company = Company::query()->create([
            'name' => 'ESL Test',
            'slug' => 'esl-test-no-email',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        $super = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
        ]);

        $response = $this->actingAs($super)->from("http://{$adminHost}/companies/{$company->id}")
            ->post("http://{$adminHost}/companies/{$company->id}/users", [
                'name' => 'Без email',
                'email' => '',
                'password' => 'Secret123',
                'role' => 'administrator',
            ]);

        $response->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('users', [
            'company_id' => $company->id,
            'name' => 'Без email',
        ]);
    }
}
