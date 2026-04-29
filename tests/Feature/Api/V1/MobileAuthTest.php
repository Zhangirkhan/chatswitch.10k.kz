<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class MobileAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_login_returns_token_and_user(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('my-password'),
        ]);
        $user->assignRole('employee');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'my-password',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'token_type',
                'user' => ['id', 'email', 'roles'],
            ]);
        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('Bearer', $response->json('token_type'));
    }

    public function test_login_invalid_credentials_returns_validation_error(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct'),
        ]);
        $user->assignRole('employee');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_inactive_user_returns_403(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret'),
            'is_active' => false,
        ]);
        $user->assignRole('employee');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret',
        ])
            ->assertForbidden()
            ->assertJsonFragment([
                'message' => 'Ваш аккаунт деактивирован.',
            ]);
    }
}
