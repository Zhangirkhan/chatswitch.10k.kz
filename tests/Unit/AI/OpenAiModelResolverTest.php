<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\Company;
use App\Services\AI\OpenAiModelResolver;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OpenAiModelResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_tenant_uses_gpt_5_5_model(): void
    {
        config([
            'services.openai.demo_slug' => 'demo',
            'services.openai.model' => 'gpt-4o-mini',
            'services.openai.demo_model' => 'gpt-5.5',
        ]);

        $company = app(TenantContext::class)->company();
        $this->assertNotNull($company);
        $this->assertSame('demo', $company->slug);

        $resolver = app(OpenAiModelResolver::class);

        $this->assertTrue($resolver->isDemo(null));
        $this->assertSame('gpt-5.5', $resolver->chatModel(null));
        $this->assertSame('gpt-5.5', $resolver->chatModel($company->id));
    }

    public function test_non_demo_tenant_keeps_default_model_and_token_limits(): void
    {
        config([
            'services.openai.demo_slug' => 'demo',
            'services.openai.model' => 'gpt-4o-mini',
            'services.openai.demo_model' => 'gpt-5.5',
            'services.openai.demo_max_tokens_multiplier' => 2,
            'services.openai.demo_max_tokens_cap' => 4096,
        ]);

        $demo = app(TenantContext::class)->company();
        $this->assertNotNull($demo);

        $client = $this->createTenantCompany(['slug' => 'acme', 'name' => 'Acme']);

        $resolver = app(OpenAiModelResolver::class);

        $this->assertFalse($resolver->isDemo($client->id));
        $this->assertSame('gpt-4o-mini', $resolver->chatModel($client->id));
        $this->assertSame(700, $resolver->maxTokens($client->id, 700));
        $this->assertSame(45, $resolver->requestTimeout($client->id));

        $this->assertTrue($resolver->isDemo($demo->id));
        $this->assertSame(1400, $resolver->maxTokens($demo->id, 700));
        $this->assertSame(90, $resolver->requestTimeout($demo->id));
    }
}
