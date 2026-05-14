<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AiQualityPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_administrator_can_open_ai_quality_settings_page(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)
            ->get(route('settings.ai-quality'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/AiQuality')
                ->has('failed_logs')
                ->has('problem_ratings'));
    }

    public function test_manager_cannot_open_ai_quality_page(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->actingAs($manager)
            ->get(route('settings.ai-quality'))
            ->assertForbidden();
    }
}
