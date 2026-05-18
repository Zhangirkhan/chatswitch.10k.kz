<?php

declare(strict_types=1);

namespace Tests\Feature\Funnel;

use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\FunnelStageType;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class FunnelStageTypeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }

        SystemSetting::setValue('module_funnels', 'on');
        TenantCompany::ensureExists();
    }

    public function test_admin_can_store_stage_with_explicit_stage_type(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $funnel = Funnel::query()->create([
            'company_id' => TenantCompany::id(),
            'name' => 'Продажи',
            'description' => null,
            'color' => '#25d366',
            'is_active' => true,
            'position' => 0,
        ]);

        $response = $this->actingAs($admin, 'web')
            ->postJson(route('settings.funnels.stages.store', $funnel), [
                'name' => 'Оплата по счёту',
                'color' => '#eab308',
                'stage_type' => FunnelStageType::PAYMENT,
            ])
            ->assertOk();

        $response->assertJsonPath('stage.stage_type', FunnelStageType::PAYMENT);

        $this->assertDatabaseHas('funnel_stages', [
            'funnel_id' => $funnel->id,
            'name' => 'Оплата по счёту',
            'stage_type' => FunnelStageType::PAYMENT,
        ]);
    }

    public function test_stage_type_is_guessed_from_name_when_missing(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $funnel = Funnel::query()->create([
            'company_id' => TenantCompany::id(),
            'name' => 'Продажи',
            'description' => null,
            'color' => '#25d366',
            'is_active' => true,
            'position' => 0,
        ]);

        $this->actingAs($admin, 'web')
            ->postJson(route('settings.funnels.stages.store', $funnel), [
                'name' => 'Доставка назначена',
                'color' => '#22c55e',
            ])
            ->assertOk();

        $stage = FunnelStage::query()->where('funnel_id', $funnel->id)->first();
        $this->assertNotNull($stage);
        $this->assertSame(FunnelStageType::DELIVERY, $stage->stage_type);
    }
}
