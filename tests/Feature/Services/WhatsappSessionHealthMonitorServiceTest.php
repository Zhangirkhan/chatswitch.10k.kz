<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Mail\WhatsappSessionDownAlertMail;
use App\Models\Company;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\Whatsapp\WhatsappSessionHealthMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class WhatsappSessionHealthMonitorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }

        config([
            'accel.whatsapp_alerts.enabled' => true,
            'accel.whatsapp_alerts.down_minutes' => 5,
            'accel.whatsapp_alerts.repeat_hours' => 24,
            'accel.whatsapp_alerts.ops_emails' => ['ops@accel.kz'],
            'accel.whatsapp_alerts.telegram_bot_token' => 'bot-token',
            'accel.whatsapp_alerts.telegram_chat_id' => '-100123',
        ]);
    }

    public function test_observe_clears_tracking_when_session_recovers(): void
    {
        $session = $this->createSession();
        Cache::put('whatsapp_session_down_since:'.$session->id, now()->subMinutes(10)->toIso8601String(), now()->addHour());

        $result = app(WhatsappSessionHealthMonitorService::class)->observe($session, [
            'alive' => true,
        ]);

        $this->assertSame('recovered', $result);
        $this->assertFalse(Cache::has('whatsapp_session_down_since:'.$session->id));
    }

    public function test_observe_sends_alert_after_down_threshold(): void
    {
        Mail::fake();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        Carbon::setTestNow('2026-06-08 12:00:00');

        $session = $this->createSession();
        Cache::put(
            'whatsapp_session_down_since:'.$session->id,
            now()->subMinutes(6)->toIso8601String(),
            now()->addHour(),
        );

        $result = app(WhatsappSessionHealthMonitorService::class)->observe($session, [
            'alive' => false,
            'isInitializing' => false,
            'hasQR' => false,
            'lastError' => 'detached Frame',
            'reasoning' => ['browser_disconnected'],
        ]);

        $this->assertSame('alert_sent', $result);
        Mail::assertSent(WhatsappSessionDownAlertMail::class, 2);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org'));
    }

    public function test_observe_waits_before_first_alert(): void
    {
        Mail::fake();

        Carbon::setTestNow('2026-06-08 12:00:00');

        $session = $this->createSession();

        $result = app(WhatsappSessionHealthMonitorService::class)->observe($session, [
            'alive' => false,
            'isInitializing' => false,
            'hasQR' => false,
        ]);

        $this->assertSame('tracking', $result);
        Mail::assertNothingSent();
        $this->assertTrue(Cache::has('whatsapp_session_down_since:'.$session->id));
    }

    public function test_observe_skips_when_qr_required(): void
    {
        Mail::fake();

        $session = $this->createSession();

        $result = app(WhatsappSessionHealthMonitorService::class)->observe($session, [
            'alive' => false,
            'hasQR' => true,
        ]);

        $this->assertSame('skipped', $result);
        Mail::assertNothingSent();
    }

    private function createSession(): WhatsappSession
    {
        $owner = User::factory()->create(['email' => 'owner@tenant.test']);
        $owner->assignRole('administrator');

        $company = Company::query()->create([
            'name' => 'Tenant Alert Co',
            'owner_user_id' => $owner->id,
            'slug' => 'tenant-alert',
        ]);

        $owner->forceFill(['company_id' => $company->id])->save();

        return WhatsappSession::factory()->create([
            'company_id' => $company->id,
            'session_name' => 'wa-alert',
            'display_name' => 'Основной номер',
            'phone_number' => '77001234567',
            'desired_state' => WhatsappSession::DESIRED_ACTIVE,
            'status' => 'connected',
        ]);
    }
}
