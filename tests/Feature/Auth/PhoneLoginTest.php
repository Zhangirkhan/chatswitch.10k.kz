<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PhoneLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_normalized_phone_number(): void
    {
        $user = User::factory()->create([
            'email' => null,
            'phone' => '+7 (747) 664-41-08',
            'password' => 'password',
        ]);

        $response = $this->post('/login', [
            'login' => '8 747 664 41 08',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_user_can_login_with_phone_from_phones_array(): void
    {
        $user = User::factory()->create([
            'email' => 'worker@example.test',
            'phone' => null,
            'phones' => ['77001112233'],
            'password' => 'password',
        ]);

        $response = $this->post('/login', [
            'login' => '+7 700 111 22 33',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_user_can_still_login_with_email(): void
    {
        $user = User::factory()->create([
            'email' => 'worker@example.test',
            'phone' => '77001112233',
            'password' => 'password',
        ]);

        $response = $this->post('/login', [
            'login' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_legacy_email_field_still_works(): void
    {
        $user = User::factory()->create([
            'password' => 'password',
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_phone_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => null,
            'phone' => '77001112233',
            'password' => 'password',
        ]);

        $this->post('/login', [
            'login' => '+7 700 111 22 33',
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }
}
