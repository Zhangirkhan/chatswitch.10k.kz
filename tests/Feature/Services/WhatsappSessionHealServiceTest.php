<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\WhatsappSession;
use App\Services\WhatsappSessionHealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class WhatsappSessionHealServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_heal_skips_alive_session(): void
    {
        Http::fake([
            '127.0.0.1:3050/health' => Http::response(['status' => 'ok'], 200),
            '127.0.0.1:3050/api/sessions/wa-alive/verify' => Http::response([
                'alive' => true,
                'isInitializing' => false,
                'hasQR' => false,
            ], 200),
        ]);

        $session = WhatsappSession::factory()->create([
            'session_name' => 'wa-alive',
            'desired_state' => WhatsappSession::DESIRED_ACTIVE,
            'status' => 'connected',
        ]);

        $result = app(WhatsappSessionHealService::class)->healSession($session);

        $this->assertSame('skipped_alive', $result);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/destroy'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/initialize'));
    }

    public function test_heal_hard_resets_zombie_session_before_initialize(): void
    {
        Http::fake([
            '127.0.0.1:3050/health' => Http::response(['status' => 'ok'], 200),
            '127.0.0.1:3050/api/sessions/wa-zombie/verify' => Http::response([
                'alive' => false,
                'isReady' => true,
                'browserConnected' => false,
                'isInitializing' => false,
                'hasQR' => false,
                'lastError' => 'Attempted to use detached Frame',
                'reasoning' => ['browser_disconnected', 'last_error:Attempted to use detached Frame'],
            ], 200),
            '127.0.0.1:3050/api/sessions/wa-zombie/destroy' => Http::response(['success' => true], 200),
            '127.0.0.1:3050/api/sessions/wa-zombie/initialize' => Http::response(['success' => true], 200),
        ]);

        $session = WhatsappSession::factory()->create([
            'session_name' => 'wa-zombie',
            'desired_state' => WhatsappSession::DESIRED_ACTIVE,
            'status' => 'connected',
        ]);

        $result = app(WhatsappSessionHealService::class)->healSession($session);

        $this->assertSame('healed', $result);
        $session->refresh();
        $this->assertSame('connecting', $session->status);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/destroy'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/initialize'));
    }

    public function test_heal_initializes_dead_session_without_destroy_when_not_zombie(): void
    {
        Http::fake([
            '127.0.0.1:3050/health' => Http::response(['status' => 'ok'], 200),
            '127.0.0.1:3050/api/sessions/wa-dead/verify' => Http::response([
                'alive' => false,
                'isReady' => false,
                'browserConnected' => false,
                'isInitializing' => false,
                'hasQR' => false,
                'reasoning' => ['client_missing'],
            ], 200),
            '127.0.0.1:3050/api/sessions/wa-dead/initialize' => Http::response(['success' => true], 200),
        ]);

        $session = WhatsappSession::factory()->create([
            'session_name' => 'wa-dead',
            'desired_state' => WhatsappSession::DESIRED_ACTIVE,
            'status' => 'disconnected',
        ]);

        $result = app(WhatsappSessionHealService::class)->healSession($session);

        $this->assertSame('healed', $result);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/destroy'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/initialize'));
    }
}
