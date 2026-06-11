<?php

declare(strict_types=1);

namespace Tests\Feature\Landing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class LandingSitemapTest extends TestCase
{
    use RefreshDatabase;

    private function landingHost(): string
    {
        return config('tenancy.root_domain', 'accel.kz');
    }

    public function test_sitemap_is_available_and_lists_landing_pages(): void
    {
        $host = $this->landingHost();
        URL::forceRootUrl('https://'.$host);

        $response = $this->withServerVariables(['HTTP_HOST' => $host])
            ->get("https://{$host}/sitemap.xml");

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<loc>https://'.$host.'/</loc>', false)
            ->assertSee('<loc>https://'.$host.'/calculator</loc>', false)
            ->assertSee('<loc>https://'.$host.'/faq</loc>', false)
            ->assertSee('hreflang="kk"', false)
            ->assertSee('hreflang="x-default"', false);
    }
}
