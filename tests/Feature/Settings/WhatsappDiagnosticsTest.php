<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class WhatsappDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $r) {
            Role::findOrCreate($r);
        }
    }

    public function test_manager_cannot_view_diagnostics(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $session = WhatsappSession::factory()->create();

        $this->actingAs($manager)
            ->getJson(route('settings.connections.diagnostics', $session))
            ->assertForbidden();
    }

    public function test_admin_receives_diagnostics_json(): void
    {
        Http::fake([
            '127.0.0.1:3050/health' => Http::response(['status' => 'ok', 'uptime' => 12.5], 200),
            '127.0.0.1:3050/api/sessions/*' => Http::response([
                'success' => true,
                'sessionName' => 'wa-test',
                'isReady' => false,
                'isInitializing' => false,
                'hasQR' => false,
                'lastError' => null,
            ], 200),
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('administrator');
        $session = WhatsappSession::factory()->create(['session_name' => 'wa-test']);

        $response = $this->actingAs($admin)->getJson(route('settings.connections.diagnostics', $session));

        $response->assertOk();
        $response->assertJsonPath('session.session_name', 'wa-test');
        $response->assertJsonPath('whatsapp_service.reachable', true);
        $response->assertJsonStructure([
            'session' => [
                'id',
                'session_name',
                'chats_count',
                'messages_count',
            ],
            'whatsapp_service' => [
                'reachable',
                'health_latency_ms',
                'session_status_latency_ms',
                'node_status',
            ],
        ]);
    }
}
