<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Jobs\ReinitializeWhatsappSessionJob;
use App\Mail\WhatsappSessionLogoutAlertMail;
use App\Models\WhatsappSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class WhatsappWebhookDisconnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp.webhook_secret', 'test-secret');
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_disconnected_webhook_persists_reason_and_dispatches_reinit_job(): void
    {
        Bus::fake();

        $session = WhatsappSession::factory()->create([
            'session_name' => 'wa-client',
            'status' => 'connected',
            'desired_state' => WhatsappSession::DESIRED_ACTIVE,
        ]);

        $payload = [
            'event' => 'disconnected',
            'data' => [
                'session' => 'wa-client',
                'companyId' => $session->company_id,
                'reason' => 'LOGOUT',
            ],
        ];

        $response = $this->signedWebhook($payload);

        $response->assertOk();

        $session->refresh();
        $this->assertSame('disconnected', $session->status);
        $this->assertSame('LOGOUT', $session->last_disconnect_reason);
        $this->assertNotNull($session->disconnected_at);

        Bus::assertDispatched(ReinitializeWhatsappSessionJob::class, function (ReinitializeWhatsappSessionJob $job) use ($session): bool {
            return $job->whatsappSessionId === $session->id;
        });
    }

    public function test_logout_webhook_sends_immediate_alert(): void
    {
        Bus::fake();
        Mail::fake();

        config([
            'accel.whatsapp_alerts.enabled' => true,
            'accel.whatsapp_alerts.logout_enabled' => true,
            'accel.whatsapp_alerts.ops_emails' => ['ops@accel.kz'],
        ]);

        $session = WhatsappSession::factory()->create([
            'session_name' => 'wa-logout',
            'status' => 'connected',
            'desired_state' => WhatsappSession::DESIRED_ACTIVE,
        ]);

        $payload = [
            'event' => 'disconnected',
            'data' => [
                'session' => 'wa-logout',
                'companyId' => $session->company_id,
                'reason' => 'LOGOUT',
            ],
        ];

        $this->signedWebhook($payload)->assertOk();

        Mail::assertSent(WhatsappSessionLogoutAlertMail::class);
    }

    public function test_non_logout_disconnect_does_not_send_logout_alert(): void
    {
        Bus::fake();
        Mail::fake();

        config([
            'accel.whatsapp_alerts.enabled' => true,
            'accel.whatsapp_alerts.logout_enabled' => true,
        ]);

        $session = WhatsappSession::factory()->create([
            'session_name' => 'wa-nologout',
            'status' => 'connected',
            'desired_state' => WhatsappSession::DESIRED_ACTIVE,
        ]);

        $payload = [
            'event' => 'disconnected',
            'data' => [
                'session' => 'wa-nologout',
                'companyId' => $session->company_id,
                'reason' => 'NAVIGATION',
            ],
        ];

        $this->signedWebhook($payload)->assertOk();

        Mail::assertNotSent(WhatsappSessionLogoutAlertMail::class);
    }

    public function test_qr_generated_after_connected_sets_qr_required_at(): void
    {
        $session = WhatsappSession::factory()->create([
            'session_name' => 'wa-qr',
            'status' => 'connected',
            'connected_at' => now()->subHour(),
        ]);

        $payload = [
            'event' => 'qr_generated',
            'data' => [
                'session' => 'wa-qr',
                'companyId' => $session->company_id,
            ],
        ];

        $this->signedWebhook($payload)->assertOk();

        $session->refresh();
        $this->assertSame('qr_pending', $session->status);
        $this->assertNotNull($session->qr_required_at);
        $this->assertNotNull($session->disconnected_at);
    }

    public function test_auth_failure_persists_message_and_broadcasts_disconnected_status(): void
    {
        Bus::fake();

        $session = WhatsappSession::factory()->create([
            'session_name' => 'wa-auth',
            'status' => 'connected',
            'desired_state' => WhatsappSession::DESIRED_ACTIVE,
        ]);

        $payload = [
            'event' => 'auth_failure',
            'data' => [
                'session' => 'wa-auth',
                'companyId' => $session->company_id,
                'message' => 'Invalid session',
            ],
        ];

        $this->signedWebhook($payload)->assertOk();

        $session->refresh();
        $this->assertSame('disconnected', $session->status);
        $this->assertSame('Invalid session', $session->last_auth_failure_message);

        Bus::assertDispatched(ReinitializeWhatsappSessionJob::class);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $body, 'test-secret');

        return $this->call(
            'POST',
            '/api/whatsapp/webhook',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_SIGNATURE' => $signature],
            $body,
        );
    }
}
