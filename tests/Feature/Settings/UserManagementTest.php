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
}
