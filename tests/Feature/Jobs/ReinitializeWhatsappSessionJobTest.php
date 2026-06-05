<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ReinitializeWhatsappSessionJob;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ReinitializeWhatsappSessionJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_job_reinitializes_dead_session(): void
    {
        Http::fake([
            '127.0.0.1:3050/health' => Http::response(['status' => 'ok'], 200),
            '127.0.0.1:3050/api/sessions/wa-job/verify' => Http::response([
                'alive' => false,
                'isInitializing' => false,
                'hasQR' => false,
            ], 200),
            '127.0.0.1:3050/api/sessions/wa-job/initialize' => Http::response(['success' => true], 200),
        ]);

        $session = WhatsappSession::factory()->create([
            'session_name' => 'wa-job',
            'desired_state' => WhatsappSession::DESIRED_ACTIVE,
            'status' => 'disconnected',
        ]);

        $job = new ReinitializeWhatsappSessionJob($session->id);
        $this->app->call([$job, 'handle']);

        $session->refresh();
        $this->assertSame('connecting', $session->status);

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/api/sessions/wa-job/initialize');
        });
    }

    public function test_job_noops_when_session_missing(): void
    {
        Http::fake();

        $job = new ReinitializeWhatsappSessionJob(99999);
        $this->app->call([$job, 'handle']);

        Http::assertNothingSent();
    }
}
