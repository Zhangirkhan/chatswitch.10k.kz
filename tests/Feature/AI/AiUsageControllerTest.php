<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\AiUsageEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AiUsageControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_administrator_can_view_ai_usage_summary(): void
    {
        $user = User::factory()->create();
        $user->assignRole('administrator');

        AiUsageEvent::query()->create([
            'company_id' => $user->company_id,
            'scenario' => 'operator_dictation',
            'kind' => 'whisper',
            'model' => 'whisper-1',
            'audio_seconds' => 12,
        ]);

        $response = $this->actingAs($user)->getJson(route('settings.ai-usage', ['period' => 30]));

        $response->assertOk();
        $response->assertJsonPath('operator_dictation_seconds', 12);
        $response->assertJsonFragment(['scenario' => 'operator_dictation', 'kind' => 'whisper']);
    }

    public function test_non_administrator_cannot_view_ai_usage(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');

        $response = $this->actingAs($user)->getJson(route('settings.ai-usage'));

        $response->assertForbidden();
    }
}
