<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\User;
use App\Services\Auth\UserPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class PinAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_pin_on_tenant_host(): void
    {
        $company = Company::query()->create([
            'name' => 'PIN Co',
            'slug' => 'pin-co',
            'is_active' => true,
            'subscription_status' => 'trial',
        ]);
        $user = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
            'is_super_admin' => false,
        ]);

        app(UserPinService::class)->setPin($user, '482901');

        $host = 'pin-co.'.config('tenancy.root_domain', 'accel.kz');
        URL::forceRootUrl('https://'.$host);

        $response = $this->post('https://'.$host.'/login/pin', [
            'pin' => '482901',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_pin_does_not_authenticate(): void
    {
        $company = Company::query()->create([
            'name' => 'PIN Bad Co',
            'slug' => 'pin-bad-co',
            'is_active' => true,
            'subscription_status' => 'trial',
        ]);
        $user = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        app(UserPinService::class)->setPin($user, '111111');

        $host = 'pin-bad-co.'.config('tenancy.root_domain', 'accel.kz');
        URL::forceRootUrl('https://'.$host);

        $this->post('https://'.$host.'/login/pin', ['pin' => '999999']);

        $this->assertGuest();
    }

    public function test_duplicate_pin_in_company_is_rejected(): void
    {
        $company = Company::query()->create([
            'name' => 'Dup PIN Co',
            'slug' => 'dup-pin-co',
            'is_active' => true,
            'subscription_status' => 'trial',
        ]);
        $first = User::factory()->create(['company_id' => $company->id]);
        $second = User::factory()->create(['company_id' => $company->id]);

        app(UserPinService::class)->setPin($first, '246810');

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(UserPinService::class)->setPin($second, '246810');
    }
}
