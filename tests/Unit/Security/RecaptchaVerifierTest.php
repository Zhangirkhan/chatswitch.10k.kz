<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Services\Security\RecaptchaVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class RecaptchaVerifierTest extends TestCase
{
    use RefreshDatabase;
    public function test_verify_succeeds_for_v3_with_sufficient_score(): void
    {
        Config::set('recaptcha.enabled', true);
        Config::set('recaptcha.site_key', 'site');
        Config::set('recaptcha.secret_key', 'secret');
        Config::set('recaptcha.version', 'v3');
        Config::set('recaptcha.min_score', 0.5);

        Http::fake([
            'www.google.com/recaptcha/api/siteverify' => Http::response([
                'success' => true,
                'score' => 0.9,
                'action' => 'login',
            ]),
        ]);

        $this->assertTrue(app(RecaptchaVerifier::class)->verify('token-abc', '127.0.0.1', 'login'));
    }

    public function test_verify_fails_when_score_below_threshold(): void
    {
        Config::set('recaptcha.enabled', true);
        Config::set('recaptcha.site_key', 'site');
        Config::set('recaptcha.secret_key', 'secret');
        Config::set('recaptcha.version', 'v3');
        Config::set('recaptcha.min_score', 0.7);

        Http::fake([
            'www.google.com/recaptcha/api/siteverify' => Http::response([
                'success' => true,
                'score' => 0.2,
                'action' => 'login',
            ]),
        ]);

        $this->assertFalse(app(RecaptchaVerifier::class)->verify('token-abc', '127.0.0.1', 'login'));
    }
}
