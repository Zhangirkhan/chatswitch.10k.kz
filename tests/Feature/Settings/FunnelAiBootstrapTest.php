<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Department;
use App\Models\Funnel;
use App\Models\FunnelAiScenario;
use App\Models\FunnelStageAiRule;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class FunnelAiBootstrapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['funnel.enforce_settings_readiness_gate' => false]);

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }

        SystemSetting::setValue('module_funnels', 'on');
        TenantCompany::ensureExists();
    }

    public function test_store_funnel_with_stages_creates_ai_scenario_and_rules(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $department = Department::query()->create([
            'name' => 'Продажи',
            'description' => null,
            'parent_id' => null,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->postJson(route('settings.funnels.store'), [
            'name' => 'Тестовая воронка',
            'description' => 'Описание',
            'color' => '#01b964',
            'is_active' => true,
            'stages' => [
                ['name' => 'Первичный запрос', 'color' => '#fbbf24'],
                ['name' => 'Замер', 'color' => '#f59e0b'],
                ['name' => 'Закрыто успешно', 'color' => '#15803d'],
            ],
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $funnelId = (int) $response->json('funnel.id');
        $this->assertDatabaseHas('funnel_ai_scenarios', [
            'funnel_id' => $funnelId,
            'fallback_department_id' => $department->id,
        ]);

        $scenario = FunnelAiScenario::query()->where('funnel_id', $funnelId)->first();
        $this->assertNotNull($scenario);
        $this->assertFalse($scenario->enabled);

        $rulesCount = FunnelStageAiRule::query()->where('funnel_id', $funnelId)->count();
        $this->assertSame(3, $rulesCount);

        $finalRule = FunnelStageAiRule::query()
            ->where('funnel_id', $funnelId)
            ->whereHas('stage', fn ($q) => $q->where('name', 'Закрыто успешно'))
            ->first();

        $this->assertNotNull($finalRule);
        $this->assertSame('Финальный этап. Поблагодарить клиента и не продолжать активные касания без нового вопроса.', $finalRule->goal);
    }

    public function test_store_stage_creates_ai_rule(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        Department::query()->create([
            'name' => 'Продажи',
            'description' => null,
            'parent_id' => null,
            'is_active' => true,
        ]);

        $funnel = Funnel::query()->create([
            'company_id' => TenantCompany::id(),
            'name' => 'Тестовая воронка',
            'color' => '#01b964',
            'position' => 0,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->postJson(route('settings.funnels.stages.store', $funnel), [
            'name' => 'Оплата',
            'color' => '#eab308',
            'stage_type' => 'payment',
            'is_active' => true,
        ]);

        $response->assertOk();

        $stageId = (int) $response->json('stage.id');

        $this->assertDatabaseHas('funnel_stage_ai_rules', [
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stageId,
        ]);

        $rule = FunnelStageAiRule::query()->where('funnel_stage_id', $stageId)->first();
        $this->assertNotNull($rule);
        $this->assertStringContainsString('оплат', mb_strtolower($rule->goal));
    }
}
