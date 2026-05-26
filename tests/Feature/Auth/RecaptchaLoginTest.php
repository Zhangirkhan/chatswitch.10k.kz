<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class RecaptchaLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('recaptcha.enabled', true);
        Config::set('recaptcha.site_key', 'test-site-key');
        Config::set('recaptcha.secret_key', 'test-secret-key');
        Config::set('recaptcha.version', 'v3');
        Config::set('recaptcha.min_score', 0.5);
    }

    public function test_login_requires_recaptcha_when_enabled(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('recaptcha_token');
        $this->assertGuest();
    }

    public function test_login_succeeds_with_valid_recaptcha_token(): void
    {
        Http::fake([
            'www.google.com/recaptcha/api/siteverify' => Http::response([
                'success' => true,
                'score' => 0.9,
                'action' => 'login',
            ]),
        ]);

        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'recaptcha_token' => 'valid-token',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_login_ignores_null_recaptcha_token_when_disabled(): void
    {
        Config::set('recaptcha.enabled', false);

        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'recaptcha_token' => null,
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_login_rejects_invalid_recaptcha_token(): void
    {
        Http::fake([
            'www.google.com/recaptcha/api/siteverify' => Http::response([
                'success' => false,
                'error-codes' => ['invalid-input-response'],
            ]),
        ]);

        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'recaptcha_token' => 'bad-token',
        ]);

        $response->assertSessionHasErrors('recaptcha_token');
        $this->assertGuest();
    }
}
