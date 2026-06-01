<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Company;
use App\Models\CompanyPromotion;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\FunnelStageAiRule;
use App\Models\User;
use App\Services\Promotion\CompanyPromotionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class PromotionAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_admin_can_create_and_list_promotion(): void
    {
        $company = $this->createTenantCompany(['name' => 'Acme']);
        $admin = User::factory()->create(['company_id' => $company->id]);
        $admin->assignRole('administrator');

        $response = $this->actingAs($admin)->postJson('/settings/promotions', [
            'name' => 'Скидка 10%',
            'discount_type' => CompanyPromotion::TYPE_PERCENT,
            'percent' => 10,
            'valid_until' => now()->addMonth()->format('Y-m-d'),
            'conditions' => 'На первый заказ',
            'is_active' => true,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('company_promotions', [
            'company_id' => $company->id,
            'name' => 'Скидка 10%',
            'percent' => 10,
        ]);

        $page = $this->withoutVite()->actingAs($admin)->get('/settings/promotions');
        $page->assertOk();
        $page->assertInertia(fn ($assert) => $assert
            ->component('Settings/Promotions')
            ->has('promotions', 1));
    }

    public function test_admin_can_create_bogo_promotion(): void
    {
        $company = $this->createTenantCompany(['name' => 'Acme']);
        $admin = User::factory()->create(['company_id' => $company->id]);
        $admin->assignRole('administrator');

        $response = $this->actingAs($admin)->postJson('/settings/promotions', [
            'name' => '1+1 на консультацию',
            'discount_type' => CompanyPromotion::TYPE_BOGO,
            'buy_quantity' => 1,
            'get_quantity' => 1,
            'conditions' => 'Вторая консультация бесплатно',
            'is_active' => true,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('company_promotions', [
            'company_id' => $company->id,
            'name' => '1+1 на консультацию',
            'discount_type' => CompanyPromotion::TYPE_BOGO,
            'buy_quantity' => 1,
            'get_quantity' => 1,
        ]);
    }

    public function test_admin_can_disable_ai_promotions_globally(): void
    {
        $company = $this->createTenantCompany(['name' => 'Acme']);
        $admin = User::factory()->create(['company_id' => $company->id]);
        $admin->assignRole('administrator');

        CompanyPromotion::query()->create([
            'company_id' => $company->id,
            'name' => 'Скидка 10%',
            'discount_type' => CompanyPromotion::TYPE_PERCENT,
            'percent' => 10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->putJson('/settings/promotions-settings', [
            'ai_promotions_enabled' => false,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'ai_promotions_enabled' => false,
        ]);

        $catalog = app(CompanyPromotionCatalog::class);
        $this->assertSame([], $catalog->promptItemsForCompany($company->id));
    }

    public function test_catalog_uses_all_active_promotions_by_default_for_rule(): void
    {
        $company = $this->createTenantCompany(['name' => 'Acme']);

        $funnel = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'Продажи',
            'description' => null,
            'color' => '#25d366',
            'is_active' => true,
            'position' => 0,
        ]);
        $stage = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'КП',
            'color' => '#3b82f6',
            'position' => 0,
            'is_active' => true,
        ]);

        $promoA = CompanyPromotion::query()->create([
            'company_id' => $company->id,
            'name' => 'Скидка 10%',
            'discount_type' => CompanyPromotion::TYPE_PERCENT,
            'percent' => 10,
            'is_active' => true,
        ]);
        $promoB = CompanyPromotion::query()->create([
            'company_id' => $company->id,
            'name' => '1+1',
            'discount_type' => CompanyPromotion::TYPE_BOGO,
            'buy_quantity' => 1,
            'get_quantity' => 1,
            'is_active' => true,
        ]);

        $rule = FunnelStageAiRule::query()->create([
            'company_id' => $company->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stage->id,
            'goal' => 'Дожим',
            'follow_up_use_promotions' => true,
            'follow_up_promotion_ids' => [],
        ]);

        $items = app(CompanyPromotionCatalog::class)->promptItemsForRule($rule);
        $ids = array_column($items, 'id');

        $this->assertContains((string) $promoA->id, $ids);
        $this->assertContains((string) $promoB->id, $ids);
    }

    public function test_manager_cannot_access_promotions_admin(): void
    {
        $company = $this->createTenantCompany();
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole('manager');

        $this->actingAs($manager)->get('/settings/promotions')->assertForbidden();
    }
}
