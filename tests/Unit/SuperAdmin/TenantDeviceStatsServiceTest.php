<?php

declare(strict_types=1);

namespace Tests\Unit\SuperAdmin;

use App\Models\Company;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\WhatsappSession;
use App\Services\SuperAdmin\TenantDeviceStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class TenantDeviceStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    private TenantDeviceStatsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TenantDeviceStatsService;
    }

    public function test_for_company_aggregates_devices_sessions_and_users(): void
    {
        $company = Company::query()->create([
            'name' => 'Stats Co',
            'slug' => 'stats-co',
            'phone' => '+77001112233',
            'is_active' => true,
        ]);

        $activeWithDevice = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $activeWithoutDevice = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        User::factory()->create(['company_id' => $company->id, 'is_active' => false]);

        UserDevice::query()->create([
            'company_id' => $company->id,
            'user_id' => $activeWithDevice->id,
            'platform' => 'android',
            'fcm_token' => 'android-token-'.str_repeat('a', 40),
        ]);
        UserDevice::query()->create([
            'company_id' => $company->id,
            'user_id' => $activeWithDevice->id,
            'platform' => 'ios',
            'fcm_token' => 'ios-token-'.str_repeat('b', 40),
        ]);

        $recentToken = $activeWithDevice->createToken('mobile');
        PersonalAccessToken::query()
            ->whereKey($recentToken->accessToken->id)
            ->update(['last_used_at' => now()->subDays(2)]);

        $staleToken = $activeWithoutDevice->createToken('mobile');
        PersonalAccessToken::query()
            ->whereKey($staleToken->accessToken->id)
            ->update(['last_used_at' => now()->subDays(45)]);

        WhatsappSession::factory()->create([
            'company_id' => $company->id,
            'status' => 'connected',
        ]);
        WhatsappSession::factory()->create([
            'company_id' => $company->id,
            'status' => 'disconnected',
        ]);

        DB::table('sessions')->insert([
            'id' => Str::random(40),
            'user_id' => $activeWithDevice->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);
        DB::table('sessions')->insert([
            'id' => Str::random(40),
            'user_id' => $activeWithoutDevice->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);

        $stats = $this->service->forCompany($company);

        $this->assertSame(2, $stats['active_users']);
        $this->assertSame(2, $stats['mobile_devices']['total']);
        $this->assertSame(1, $stats['mobile_devices']['android']);
        $this->assertSame(1, $stats['mobile_devices']['ios']);
        $this->assertSame(1, $stats['mobile_devices']['users_with_device']);
        $this->assertSame(1, $stats['mobile_devices']['users_without_device']);
        $this->assertSame(2, $stats['mobile_sessions']['total_tokens']);
        $this->assertSame(1, $stats['mobile_sessions']['active_30d']);
        $this->assertSame(1, $stats['mobile_sessions']['users_with_active_token']);
        $this->assertSame(2, $stats['whatsapp_sessions']['total']);
        $this->assertSame(1, $stats['whatsapp_sessions']['connected']);
        $this->assertSame(2, $stats['web_sessions']['active_now']);
        $this->assertSame(2, $stats['web_sessions']['users_online']);
    }

    public function test_for_platform_aggregates_across_companies(): void
    {
        $companyA = Company::query()->create([
            'name' => 'Alpha',
            'slug' => 'alpha-stats',
            'phone' => '+77001110001',
            'is_active' => true,
        ]);
        $companyB = Company::query()->create([
            'name' => 'Beta',
            'slug' => 'beta-stats',
            'phone' => '+77001110002',
            'is_active' => true,
        ]);

        $userA = User::factory()->create(['company_id' => $companyA->id, 'is_active' => true]);
        User::factory()->create(['company_id' => $companyB->id, 'is_active' => true]);

        UserDevice::query()->create([
            'company_id' => $companyA->id,
            'user_id' => $userA->id,
            'platform' => 'android',
            'fcm_token' => 'platform-token-'.str_repeat('c', 40),
        ]);

        $token = $userA->createToken('mobile');
        PersonalAccessToken::query()
            ->whereKey($token->accessToken->id)
            ->update(['last_used_at' => now()->subDay()]);

        WhatsappSession::factory()->create([
            'company_id' => $companyA->id,
            'status' => 'ready',
        ]);

        DB::table('sessions')->insert([
            'id' => Str::random(40),
            'user_id' => $userA->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);

        $stats = $this->service->forPlatform(Company::query()->whereIn('id', [$companyA->id, $companyB->id]));

        $this->assertSame(2, $stats['active_users']);
        $this->assertSame(1, $stats['mobile_devices_total']);
        $this->assertSame(1, $stats['mobile_sessions_active_30d']);
        $this->assertSame(1, $stats['whatsapp_connected']);
        $this->assertSame(1, $stats['web_sessions_active']);
        $this->assertSame(1, $stats['tenants_with_mobile']);
    }

    public function test_for_platform_returns_zeros_when_no_companies(): void
    {
        $stats = $this->service->forPlatform(Company::query()->whereRaw('1 = 0'));

        $this->assertSame(0, $stats['active_users']);
        $this->assertSame(0, $stats['mobile_devices_total']);
        $this->assertSame(0, $stats['mobile_sessions_active_30d']);
        $this->assertSame(0, $stats['whatsapp_connected']);
        $this->assertSame(0, $stats['web_sessions_active']);
        $this->assertSame(0, $stats['tenants_with_mobile']);
    }
}
