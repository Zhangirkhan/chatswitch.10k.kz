<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Services\Auth\UserPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class MobilePinAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_pin_login_returns_token_and_user(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('employee');
        app(UserPinService::class)->setPin($user, '4829');

        $this->postJson('/api/v1/auth/login/pin', ['pin' => '4829'])
            ->assertOk()
            ->assertJsonStructure([
                'token',
                'token_type',
                'tenant' => ['id', 'slug', 'name'],
                'user' => ['id', 'email', 'roles'],
            ]);
    }

    public function test_invalid_pin_returns_validation_error(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        app(UserPinService::class)->setPin($user, '1111');

        $this->postJson('/api/v1/auth/login/pin', ['pin' => '9999'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pin']);
    }

    public function test_pin_login_inactive_user_returns_403(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        app(UserPinService::class)->setPin($user, '5555');

        $this->postJson('/api/v1/auth/login/pin', ['pin' => '5555'])
            ->assertForbidden()
            ->assertJsonFragment(['message' => 'Ваш аккаунт деактивирован.']);
    }
}
