<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Публичная регистрация в приложении отключена (пользователи создаются администратором).
 */
final class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_routes_are_not_exposed(): void
    {
        $this->get('/register')->assertRedirect(route('login'));
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertStatus(405);
    }
}
