<?php

declare(strict_types=1);

namespace Tests\Feature\Landing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class LandingFaqPageTest extends TestCase
{
    use RefreshDatabase;

    private function landingHost(): string
    {
        return config('tenancy.root_domain', 'accel.kz');
    }

    public function test_faq_page_renders_on_root_domain(): void
    {
        $host = $this->landingHost();
        URL::forceRootUrl('https://'.$host);

        $this->withoutVite()
            ->withServerVariables(['HTTP_HOST' => $host])
            ->get("https://{$host}/faq")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Landing/Faq')
                ->has('rootDomain')
                ->has('androidApkUrl'));
    }

    public function test_faq_page_includes_faq_structured_data(): void
    {
        $host = $this->landingHost();
        URL::forceRootUrl('https://'.$host);

        $this->withoutVite()
            ->withServerVariables(['HTTP_HOST' => $host])
            ->get("https://{$host}/faq")
            ->assertOk()
            ->assertSee('application/ld+json', false)
            ->assertSee('"@type":"FAQPage"', false);
    }
}
