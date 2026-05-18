<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Product;
use App\Models\User;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class KnowledgeCatalogAuditApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('administrator');
        TenantCompany::ensureExists();
    }

    public function test_admin_can_fetch_catalog_audit(): void
    {
        $companyId = TenantCompany::id();
        Product::create([
            'company_id' => $companyId,
            'name' => 'Стол',
            'include_in_prompt' => true,
            'is_active' => true,
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $response = $this->actingAs($admin)->getJson(
            '/settings/knowledge/catalog-audit?company_id='.$companyId,
        );

        $response->assertOk();
        $response->assertJsonStructure(['findings', 'summary']);
    }
}
