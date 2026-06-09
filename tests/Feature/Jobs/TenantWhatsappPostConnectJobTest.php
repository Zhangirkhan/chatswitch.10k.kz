<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\TenantWhatsappPostConnectJob;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TenantWhatsappPostConnectJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_post_connect_syncs_inbound_when_session_alive(): void
    {
        Http::fake([
            '127.0.0.1:3050/health' => Http::response(['status' => 'ok'], 200),
            '127.0.0.1:3050/api/sessions/wa-post/verify' => Http::response([
                'alive' => true,
                'isReady' => true,
            ], 200),
            '127.0.0.1:3050/api/sessions/wa-post/sync-inbound' => Http::response(['synced' => 3], 200),
        ]);

        $session = WhatsappSession::factory()->create([
            'session_name' => 'wa-post',
            'desired_state' => WhatsappSession::DESIRED_ACTIVE,
            'status' => 'connecting',
        ]);

        $job = new TenantWhatsappPostConnectJob($session->id);
        $this->app->call([$job, 'handle']);

        $session->refresh();
        $this->assertSame('connected', $session->status);

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/sync-inbound');
        });
    }

    public function test_post_connect_heals_dead_session(): void
    {
        Http::fake([
            '127.0.0.1:3050/health' => Http::response(['status' => 'ok'], 200),
            '127.0.0.1:3050/api/sessions/wa-heal/verify' => Http::sequence()
                ->push(['alive' => false, 'isInitializing' => false, 'hasQR' => false], 200)
                ->push(['alive' => true, 'isReady' => true], 200),
            '127.0.0.1:3050/api/sessions/wa-heal/destroy' => Http::response(['success' => true], 200),
            '127.0.0.1:3050/api/sessions/wa-heal/initialize' => Http::response(['success' => true], 200),
            '127.0.0.1:3050/api/sessions/wa-heal/sync-inbound' => Http::response(['synced' => 1], 200),
        ]);

        $session = WhatsappSession::factory()->create([
            'session_name' => 'wa-heal',
            'desired_state' => WhatsappSession::DESIRED_ACTIVE,
            'status' => 'connecting',
        ]);

        $job = new TenantWhatsappPostConnectJob($session->id);
        $this->app->call([$job, 'handle']);

        $session->refresh();
        $this->assertSame('connected', $session->status);
    }
}
