<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use Tests\TestCase;

final class SwaggerDocumentationTest extends TestCase
{
    public function test_swagger_ui_page_is_available(): void
    {
        $this->get('/docs/api')
            ->assertOk()
            ->assertSee('swagger-ui', false);
    }

    public function test_openapi_yaml_is_available(): void
    {
        $this->get('/docs/api/openapi.yaml')
            ->assertOk()
            ->assertHeader('content-type', 'application/yaml; charset=UTF-8')
            ->assertSee('openapi: 3.0.3', false);
    }

    public function test_legacy_mobile_v1_path_redirects(): void
    {
        $this->get('/docs/api/mobile-v1')
            ->assertRedirect('/docs/api');
    }
}
