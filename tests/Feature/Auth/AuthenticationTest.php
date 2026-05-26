<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect(route('login', ['tenant' => config('tenancy.fallback_slug', 'demo')], absolute: false));
    }

    public function test_authenticated_super_admin_is_redirected_from_login_to_dashboard(): void
    {
        $user = User::factory()->create([
            'is_super_admin' => true,
            'company_id' => null,
        ]);

        $adminHost = config('tenancy.admin_subdomain', 'app').'.'.config('tenancy.root_domain', 'accel.kz');
        $this->withServerVariables(['HTTP_HOST' => $adminHost]);
        URL::forceRootUrl('https://'.$adminHost);

        $response = $this->actingAs($user)->get('https://'.$adminHost.'/login');

        $response->assertRedirect(route('super.dashboard', absolute: false));
    }
}
