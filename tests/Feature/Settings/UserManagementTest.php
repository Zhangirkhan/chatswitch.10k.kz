<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Department;
use App\Models\TeamConversation;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $r) {
            Role::findOrCreate($r);
        }
    }

    public function test_non_admin_cannot_access_users_page(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->actingAs($manager)->get('/settings/users')->assertForbidden();
    }

    public function test_admin_can_create_user(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create();

        $payload = [
            'name' => 'Alice',
            'email' => 'alice@example.test',
            'password' => 'secret123',
            'role' => 'employee',
            'whatsapp_session_ids' => [$session->id],
        ];

        $response = $this->actingAs($admin)->postJson('/settings/users', $payload);
        $response->assertOk();

        $this->assertDatabaseHas('users', ['email' => 'alice@example.test']);

        $user = User::where('email', 'alice@example.test')->first();
        $this->assertTrue($user->hasRole('employee'));
        $this->assertSame(1, $user->whatsappSessions()->where('whatsapp_sessions.id', $session->id)->count());
    }

    public function test_same_email_allowed_in_different_companies(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $otherCompanyId = $admin->company_id === 1 ? 2 : 1;
        \App\Models\Company::query()->withoutGlobalScope('tenant')->updateOrInsert(
            ['id' => $otherCompanyId],
            [
                'name' => 'Other Co',
                'slug' => 'other-co-'.$otherCompanyId,
                'is_active' => true,
                'subscription_status' => 'trial',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        User::factory()->create([
            'email' => 'shared@example.test',
            'company_id' => $otherCompanyId,
        ]);

        $this->actingAs($admin)->postJson('/settings/users', [
            'name' => 'Shared Email User',
            'email' => 'shared@example.test',
            'password' => 'secret123',
            'role' => 'employee',
            'whatsapp_session_ids' => [],
        ])->assertOk();

        $this->assertSame(
            2,
            User::query()->withoutGlobalScope('tenant')->where('email', 'shared@example.test')->count(),
        );
    }

    public function test_admin_can_create_user_without_email(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)->postJson('/settings/users', [
            'name' => 'No Email User',
            'email' => '',
            'password' => 'secret123',
            'role' => 'employee',
            'whatsapp_session_ids' => [],
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'name' => 'No Email User',
            'email' => null,
            'company_id' => $admin->company_id,
        ]);
    }

    public function test_admin_can_create_user_with_pin_only_without_email_or_password(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)->postJson('/settings/users', [
            'name' => 'Pin Only',
            'email' => null,
            'pin' => '4321',
            'role' => 'employee',
            'whatsapp_session_ids' => [],
        ])->assertOk();

        $user = User::query()->where('name', 'Pin Only')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email);
        $this->assertNotNull($user->pin_hash);
    }

    public function test_admin_can_create_user_with_department_when_team_chat_exists_under_other_company_scope(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $otherCompanyId = $admin->company_id === 1 ? 2 : 1;
        \App\Models\Company::query()->withoutGlobalScope('tenant')->updateOrInsert(
            ['id' => $otherCompanyId],
            [
                'name' => 'Other Co',
                'slug' => 'other-co-'.$otherCompanyId,
                'is_active' => true,
                'subscription_status' => 'trial',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $department = Department::query()->create([
            'name' => 'Test Dept',
            'company_id' => $admin->company_id,
            'is_active' => true,
        ]);

        TeamConversation::query()->withoutGlobalScope('tenant')->updateOrCreate(
            [
                'department_id' => $department->id,
                'type' => TeamConversation::TYPE_DEPARTMENT,
            ],
            [
                'company_id' => $otherCompanyId,
            ],
        );

        $this->actingAs($admin)->postJson('/settings/users', [
            'name' => 'Dept Member',
            'email' => 'dept-member@example.test',
            'password' => 'secret123',
            'role' => 'employee',
            'department_ids' => [$department->id],
            'whatsapp_session_ids' => [],
        ])->assertOk();

        $this->assertDatabaseHas('department_user', [
            'user_id' => User::query()->where('email', 'dept-member@example.test')->value('id'),
            'department_id' => $department->id,
        ]);
    }

    public function test_duplicate_email_in_same_company_is_rejected(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        User::factory()->create([
            'email' => 'taken@example.test',
            'company_id' => $admin->company_id,
        ]);

        $this->actingAs($admin)->postJson('/settings/users', [
            'name' => 'Duplicate',
            'email' => 'taken@example.test',
            'password' => 'secret123',
            'role' => 'employee',
            'whatsapp_session_ids' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_admin_password_change_revokes_target_personal_access_tokens(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $target = User::factory()->create(['email' => 'bob@example.test']);
        $target->assignRole('employee');
        $target->createToken('mobile');

        $this->assertSame(1, $target->tokens()->count());

        $this->actingAs($admin)->putJson(route('settings.users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'password' => 'new-secret-999',
            'role' => 'employee',
            'is_active' => true,
            'whatsapp_session_ids' => [],
        ])->assertOk();

        $this->assertSame(0, $target->fresh()->tokens()->count());
    }

    public function test_admin_deactivating_user_revokes_personal_access_tokens(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $target = User::factory()->create(['email' => 'carol@example.test']);
        $target->assignRole('employee');
        $target->createToken('mobile');

        $this->assertSame(1, $target->tokens()->count());

        $this->actingAs($admin)->putJson(route('settings.users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'employee',
            'is_active' => false,
            'whatsapp_session_ids' => [],
        ])->assertOk();

        $this->assertSame(0, $target->fresh()->tokens()->count());
    }
}
