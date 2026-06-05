<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class OnboardingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_administrator_can_open_onboarding_page(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)
            ->get(route('settings.onboarding'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Onboarding')
                ->has('steps', 9)
                ->has('readiness'));
    }

    public function test_manager_cannot_open_onboarding_page(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->actingAs($manager)
            ->get(route('settings.onboarding'))
            ->assertForbidden();
    }
}
