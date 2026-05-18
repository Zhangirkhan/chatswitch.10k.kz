<?php

declare(strict_types=1);

namespace Tests\Unit\Knowledge;

use App\Models\Product;
use App\Services\Knowledge\KnowledgeCatalogAuditService;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class KnowledgeCatalogAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_detects_duplicate_sku_in_prompt(): void
    {
        TenantCompany::ensureExists();
        $companyId = TenantCompany::id();

        Product::create([
            'company_id' => $companyId,
            'name' => 'Диван A',
            'sku' => 'SKU-1',
            'include_in_prompt' => true,
            'is_active' => true,
        ]);
        Product::create([
            'company_id' => $companyId,
            'name' => 'Диван B',
            'sku' => 'SKU-1',
            'include_in_prompt' => true,
            'is_active' => true,
        ]);

        $result = app(KnowledgeCatalogAuditService::class)->audit($companyId);

        $keys = array_column($result['findings'], 'key');
        $this->assertContains('duplicate_product_sku', $keys);
        $this->assertGreaterThanOrEqual(1, $result['summary']['critical']);
    }
}
