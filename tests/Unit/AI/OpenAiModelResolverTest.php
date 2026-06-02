<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Services\AI\OpenAiModelResolver;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OpenAiModelResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_tenants_use_openai_model_from_config(): void
    {
        config(['services.openai.model' => 'gpt-5.5']);

        $company = app(TenantContext::class)->company();
        $this->assertNotNull($company);

        $resolver = app(OpenAiModelResolver::class);

        $this->assertSame('gpt-5.5', $resolver->chatModel(null));
        $this->assertSame('gpt-5.5', $resolver->chatModel($company->id));
    }

    public function test_demo_gets_higher_token_limit_and_timeout(): void
    {
        config([
            'services.openai.model' => 'gpt-5.5',
            'services.openai.demo_max_tokens_multiplier' => 2,
            'services.openai.demo_max_tokens_cap' => 4096,
            'services.openai.timeout' => 90,
            'services.openai.demo_timeout' => 120,
        ]);

        $demo = app(TenantContext::class)->company();
        $this->assertNotNull($demo);

        $client = $this->createTenantCompany(['slug' => 'acme', 'name' => 'Acme']);

        $resolver = app(OpenAiModelResolver::class);

        $this->assertSame('gpt-5.5', $resolver->chatModel($client->id));
        $this->assertSame(700, $resolver->maxTokens($client->id, 700));
        $this->assertSame(90, $resolver->requestTimeout($client->id));

        $this->assertSame(1400, $resolver->maxTokens($demo->id, 700));
        $this->assertSame(120, $resolver->requestTimeout($demo->id));
    }
}
