<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Product;
use App\Models\User;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class KnowledgeCatalogLlmAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('administrator');
        TenantCompany::ensureExists();
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');
    }

    public function test_catalog_audit_includes_llm_findings_when_requested(): void
    {
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'findings' => [
                                    [
                                        'severity' => 'warning',
                                        'title' => 'Противоречие по доставке',
                                        'description' => 'В правилах разные условия.',
                                        'action' => 'Сверьте правила доставки.',
                                    ],
                                ],
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ]),
        ]);

        $companyId = TenantCompany::id();
        Product::create([
            'company_id' => $companyId,
            'name' => 'Диван',
            'include_in_prompt' => true,
            'is_active' => true,
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $response = $this->actingAs($admin)->getJson(
            '/settings/knowledge/catalog-audit?company_id='.$companyId.'&llm=1',
        );

        $response->assertOk();
        $response->assertJsonPath('llm_used', true);
        $this->assertStringContainsString('Противоречие', (string) json_encode($response->json('findings'), JSON_UNESCAPED_UNICODE));
    }
}
