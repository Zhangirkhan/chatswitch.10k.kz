<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ChatAiSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_admin_can_toggle_ai_via_mobile_api(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => false,
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/chats/{$chat->id}/ai", [
            'ai_enabled' => true,
            'ai_mode' => 'auto',
            'confirm_risky_enable' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $chat->id)
            ->assertJsonPath('data.ai_enabled', true)
            ->assertJsonPath('data.ai_mode', 'auto')
            ->assertJsonPath('requires_confirmation', false);

        $this->assertTrue($chat->fresh()->ai_enabled);
    }

    public function test_assigned_employee_can_toggle_ai(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => false,
        ]);

        ChatAssignment::create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'assigned_by' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/chats/{$chat->id}/ai", [
            'ai_enabled' => true,
            'confirm_risky_enable' => true,
        ])->assertOk();

        $this->assertTrue($chat->fresh()->ai_enabled);
    }

    public function test_unassigned_employee_cannot_toggle_ai(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/chats/{$chat->id}/ai", [
            'ai_enabled' => true,
        ])->assertForbidden();
    }
}
