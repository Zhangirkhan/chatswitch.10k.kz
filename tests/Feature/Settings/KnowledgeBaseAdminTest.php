<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class KnowledgeBaseAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
    }

    public function test_admin_can_fetch_knowledge_prompt_preview(): void
    {
        $company = Company::create(['name' => 'Acme']);
        Product::create([
            'company_id' => $company->id,
            'name' => 'Test slippers',
            'price' => 1000,
            'include_in_prompt' => true,
            'is_active' => true,
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $response = $this->actingAs($admin)->getJson('/settings/knowledge/prompt-preview?company_id='.$company->id);

        $response->assertOk();
        $response->assertJsonPath('counts.products', 1);
        $response->assertJsonStructure(['text', 'truncated', 'counts', 'hint']);
        $this->assertStringContainsString('Test slippers', (string) $response->json('text'));
        $this->assertStringContainsString('База знаний компании', (string) $response->json('text'));
    }

    public function test_manager_cannot_fetch_prompt_preview(): void
    {
        $company = Company::create(['name' => 'Acme']);
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->actingAs($manager)
            ->getJson('/settings/knowledge/prompt-preview?company_id='.$company->id)
            ->assertForbidden();
    }

    public function test_admin_can_bulk_toggle_product_prompt_flag(): void
    {
        $company = Company::create(['name' => 'Acme']);
        $p1 = Product::create([
            'company_id' => $company->id,
            'name' => 'A',
            'include_in_prompt' => true,
            'is_active' => true,
        ]);
        $p2 = Product::create([
            'company_id' => $company->id,
            'name' => 'B',
            'include_in_prompt' => true,
            'is_active' => true,
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $response = $this->actingAs($admin)->postJson('/settings/knowledge/products/bulk-prompt', [
            'ids' => [$p1->id, $p2->id],
            'include_in_prompt' => false,
        ]);

        $response->assertOk();
        $this->assertFalse((bool) Product::query()->find($p1->id)?->include_in_prompt);
        $this->assertFalse((bool) Product::query()->find($p2->id)?->include_in_prompt);
        $response->assertJsonCount(2, 'items');
    }

    public function test_store_product_writes_knowledge_audit_log(): void
    {
        $company = Company::create(['name' => 'Acme']);
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)->postJson(route('settings.knowledge.products.store'), [
            'company_id' => $company->id,
            'name' => 'Audit widget',
            'description' => 'Test',
            'price' => 100,
            'is_active' => true,
            'include_in_prompt' => true,
            'sort_order' => 0,
        ])->assertOk();

        $this->assertDatabaseHas('knowledge_audit_logs', [
            'company_id' => $company->id,
            'user_id' => $admin->id,
            'entity_type' => 'product',
            'action' => 'created',
        ]);
    }

    public function test_admin_can_list_knowledge_audit(): void
    {
        $company = Company::create(['name' => 'Acme']);
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->actingAs($admin)->postJson(route('settings.knowledge.products.store'), [
            'company_id' => $company->id,
            'name' => 'Listed product',
            'description' => 'Test',
            'price' => 50,
            'is_active' => true,
            'include_in_prompt' => true,
            'sort_order' => 0,
        ])->assertOk();

        $response = $this->actingAs($admin)->getJson('/settings/knowledge/audit?company_id='.$company->id.'&entity_type=product');

        $response->assertOk();
        $response->assertJsonStructure(['data', 'current_page', 'total']);
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }
}
