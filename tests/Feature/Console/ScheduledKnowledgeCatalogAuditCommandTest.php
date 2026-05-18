<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Product;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ScheduledKnowledgeCatalogAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('administrator');
        TenantCompany::ensureExists();
    }

    public function test_scheduled_catalog_audit_command_exits_cleanly(): void
    {
        Config::set('logging.default', 'null');

        $companyId = TenantCompany::id();
        Product::create([
            'company_id' => $companyId,
            'name' => 'Стул',
            'include_in_prompt' => true,
            'is_active' => true,
        ]);

        $this->artisan('knowledge:catalog-audit', ['company_id' => $companyId])
            ->assertExitCode(0);
    }
}
