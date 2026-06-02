<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class DemoWhatsappSessionSimulationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_demo_connections_page_forces_connected_status_despite_qr_from_service(): void
    {
        Http::fake();

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create([
            'session_name' => 'demo-main',
            'display_name' => 'Главный WhatsApp',
            'phone_number' => '+77001000001',
            'status' => 'qr_pending',
        ]);

        $this->actingAs($admin)
            ->get(route('settings.connections'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Connections')
                ->has('sessions', 1)
                ->where('sessions.0.id', $session->id)
                ->where('sessions.0.status', 'connected')
                ->where('whatsappServiceReachable', true));

        $this->assertDatabaseHas('whatsapp_sessions', [
            'id' => $session->id,
            'status' => 'connected',
        ]);

        Http::assertNothingSent();
    }

    public function test_demo_status_endpoint_returns_simulated_connected_payload(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create([
            'session_name' => 'demo-sales',
            'status' => 'qr_pending',
        ]);

        $this->actingAs($admin)
            ->getJson(route('settings.connections.status', $session))
            ->assertOk()
            ->assertJsonPath('isReady', true)
            ->assertJsonPath('hasQR', false)
            ->assertJsonPath('session.status', 'connected');

        Http::assertNothingSent();
    }
}
