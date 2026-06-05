<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class WhatsappSessionsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_bootstrap_returns_disconnect_metadata_and_status_hint(): void
    {
        Http::fake([
            '127.0.0.1:3050/health' => Http::response(['status' => 'ok'], 200),
            '127.0.0.1:3050/api/sessions/*' => Http::response([
                'success' => true,
                'isReady' => false,
                'hasQR' => true,
                'isInitializing' => false,
            ], 200),
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        WhatsappSession::factory()->create([
            'session_name' => 'wa-api',
            'status' => 'connected',
            'last_disconnect_reason' => 'LOGOUT',
            'qr_required_at' => now()->subMinutes(5),
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/whatsapp/sessions')
            ->assertOk()
            ->assertJsonPath('whatsappServiceReachable', true)
            ->assertJsonPath('sessions.0.session_name', 'wa-api')
            ->assertJsonPath('sessions.0.last_disconnect_reason', 'LOGOUT')
            ->assertJsonStructure([
                'sessions' => [[
                    'id',
                    'session_name',
                    'status',
                    'status_hint',
                    'last_disconnect_reason',
                    'qr_required_at',
                ]],
            ]);
    }
}
