<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_sanctum_token_from_other_tenant_is_rejected(): void
    {
        $homeCompany = Company::query()
            ->withoutGlobalScope('tenant')
            ->where('slug', config('tenancy.fallback_slug', 'demo'))
            ->firstOrFail();

        $otherCompany = Company::query()->create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        $user = User::factory()->create(['company_id' => $homeCompany->id]);
        $user->assignRole('employee');

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')->assertOk();

        $this->switchTenant($otherCompany);

        $this->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonFragment([
                'message' => 'Токен не действителен для этого рабочего пространства.',
            ]);
    }

    public function test_employee_cannot_upsert_contacts(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole('employee');
        Sanctum::actingAs($employee);

        $this->postJson('/api/v1/contacts/upsert', [
            'phone' => '+77001234567',
            'name' => 'Test Client',
        ])->assertForbidden();
    }

    public function test_manager_can_upsert_contacts(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        Sanctum::actingAs($manager);

        $this->postJson('/api/v1/contacts/upsert', [
            'phone' => '+77007654321',
            'name' => 'Manager Client',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_whatsapp_sessions_filtered_by_user_access(): void
    {
        Http::fake([
            '127.0.0.1:3050/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $assigned = WhatsappSession::factory()->create(['session_name' => 'wa-assigned']);
        $other = WhatsappSession::factory()->create(['session_name' => 'wa-other']);

        $employee = User::factory()->create();
        $employee->assignRole('employee');
        $employee->whatsappSessions()->attach($assigned->id);

        Sanctum::actingAs($employee);

        $this->getJson('/api/v1/whatsapp/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'sessions')
            ->assertJsonPath('sessions.0.session_name', 'wa-assigned')
            ->assertJsonMissing(['session_name' => 'wa-other']);
    }
}
