<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

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
