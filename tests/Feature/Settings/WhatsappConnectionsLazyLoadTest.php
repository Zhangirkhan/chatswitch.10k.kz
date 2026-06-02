<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class WhatsappConnectionsLazyLoadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('administrator');
    }

    public function test_connections_index_does_not_call_whatsapp_service_for_non_demo_tenant(): void
    {
        Http::fake();

        $this->createTenantCompany(['slug' => 'acme-corp', 'name' => 'Acme Corp']);

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        WhatsappSession::factory()->create([
            'session_name' => 'wa-main',
            'status' => 'connected',
        ]);

        $this->actingAs($admin)
            ->get(route('settings.connections'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Connections')
                ->where('whatsappServiceReachable', null)
                ->where('sessions.0.status', 'connected'));

        Http::assertNothingSent();
    }

    public function test_connections_bootstrap_reconciles_sessions_when_service_is_reachable(): void
    {
        Http::fake([
            '127.0.0.1:3050/health' => Http::response(['status' => 'ok'], 200),
            '127.0.0.1:3050/api/sessions/wa-main/status' => Http::response([
                'success' => true,
                'sessionName' => 'wa-main',
                'isReady' => true,
                'isInitializing' => false,
                'hasQR' => false,
            ], 200),
        ]);

        $this->createTenantCompany(['slug' => 'acme-corp', 'name' => 'Acme Corp']);

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $session = WhatsappSession::factory()->create([
            'session_name' => 'wa-main',
            'status' => 'qr_pending',
            'desired_state' => WhatsappSession::DESIRED_ACTIVE,
        ]);

        $this->actingAs($admin)
            ->getJson(route('settings.connections.bootstrap'))
            ->assertOk()
            ->assertJsonPath('whatsappServiceReachable', true)
            ->assertJsonPath('sessions.0.id', $session->id)
            ->assertJsonPath('sessions.0.status', 'connected');

        $this->assertDatabaseHas('whatsapp_sessions', [
            'id' => $session->id,
            'status' => 'connected',
        ]);

        Http::assertSentCount(2);
    }
}
