<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class WorkspaceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_workspace_returns_tenant_info(): void
    {
        $response = $this->getJson('/api/v1/workspace');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['slug', 'name'],
            ]);

        $this->assertArrayNotHasKey('subscription_status', $response->json('data') ?? []);
        $this->assertArrayNotHasKey('is_active', $response->json('data') ?? []);
    }

    public function test_login_includes_tenant_block(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('my-password'),
        ]);
        $user->assignRole('employee');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'my-password',
        ])
            ->assertOk()
            ->assertJsonStructure([
                'token',
                'tenant' => ['id', 'slug', 'name'],
                'user' => ['id', 'email'],
            ]);
    }

    public function test_login_rejects_user_from_other_tenant(): void
    {
        $otherCompany = Company::query()->create([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        $user = User::factory()->create([
            'company_id' => $otherCompany->id,
            'password' => Hash::make('secret'),
        ]);
        $user->assignRole('employee');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_suspended_tenant_blocks_api_login(): void
    {
        $company = Company::query()
            ->withoutGlobalScope('tenant')
            ->where('slug', config('tenancy.fallback_slug', 'demo'))
            ->first();

        $company?->update(['subscription_status' => 'suspended']);

        $user = User::factory()->create([
            'password' => Hash::make('secret'),
        ]);
        $user->assignRole('employee');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret',
        ])->assertForbidden();
    }
}
