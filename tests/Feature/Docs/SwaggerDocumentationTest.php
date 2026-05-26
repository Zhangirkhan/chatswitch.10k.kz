<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SwaggerDocumentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'docs.api_username' => 'docs',
            'docs.api_password' => 'test-docs-secret',
        ]);
    }

    public function test_swagger_ui_requires_http_basic_password(): void
    {
        $this->get('/docs/api')->assertUnauthorized();

        $this->withBasicAuth('docs', 'wrong')
            ->get('/docs/api')
            ->assertUnauthorized();

        $this->withBasicAuth('docs', 'test-docs-secret')
            ->get('/docs/api')
            ->assertOk()
            ->assertSee('swagger-ui', false);
    }

    public function test_openapi_yaml_requires_http_basic_password(): void
    {
        $this->get('/docs/api/openapi.yaml')->assertUnauthorized();

        $this->withBasicAuth('docs', 'test-docs-secret')
            ->get('/docs/api/openapi.yaml')
            ->assertOk()
            ->assertHeader('content-type', 'application/yaml; charset=UTF-8')
            ->assertSee('openapi: 3.0.3', false);
    }

    public function test_legacy_mobile_v1_path_redirects(): void
    {
        $this->withBasicAuth('docs', 'test-docs-secret')
            ->get('/docs/api/mobile-v1')
            ->assertRedirect('/docs/api');
    }

    public function test_docs_disabled_when_password_not_configured(): void
    {
        config(['docs.api_password' => null]);

        $this->withBasicAuth('docs', 'test-docs-secret')
            ->get('/docs/api')
            ->assertStatus(503);
    }
}
