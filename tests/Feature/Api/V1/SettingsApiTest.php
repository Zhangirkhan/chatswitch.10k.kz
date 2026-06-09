<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $roleName) {
            Role::findOrCreate($roleName);
        }
    }

    public function test_settings_returns_modules_and_settings(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJsonStructure(['app_version', 'settings', 'modules'])
            ->assertJsonPath('app_version', '1.0.0');
    }
}
