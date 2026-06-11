<?php

declare(strict_types=1);

namespace Tests\Feature\Landing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class LandingMetaTest extends TestCase
{
    use RefreshDatabase;

    private function landingHost(): string
    {
        return config('tenancy.root_domain', 'accel.kz');
    }

    public function test_home_page_renders_kazakh_og_meta_by_default(): void
    {
        $host = $this->landingHost();
        URL::forceRootUrl('https://'.$host);

        $meta = config('landing.pages.home.kk');
        $description = $meta['description'];

        $this->withoutVite()
            ->withServerVariables(['HTTP_HOST' => $host])
            ->get("https://{$host}/")
            ->assertOk()
            ->assertSee('<html lang="kk"', false)
            ->assertSee('<meta name="description" content="'.$description.'"', false)
            ->assertSee('<meta property="og:title" content="'.$meta['title'].'"', false)
            ->assertSee('<meta property="og:description" content="'.$description.'"', false)
            ->assertSee('<link rel="canonical" href="https://'.$host.'/"', false)
            ->assertSee('hreflang="kk"', false)
            ->assertSee('hreflang="ru"', false)
            ->assertSee('hreflang="en"', false)
            ->assertSee('hreflang="x-default"', false)
            ->assertSee('<meta property="og:locale" content="kk_KZ"', false)
            ->assertSee('<meta property="og:image:width" content="1200"', false)
            ->assertSee('<meta property="og:image:height" content="630"', false)
            ->assertSee('application/ld+json', false)
            ->assertSee('"@type":"Organization"', false);
    }

    public function test_lang_query_switches_og_meta_to_english(): void
    {
        $host = $this->landingHost();
        URL::forceRootUrl('https://'.$host);

        $meta = config('landing.pages.home.en');
        $description = $meta['description'];

        $this->withoutVite()
            ->withServerVariables(['HTTP_HOST' => $host])
            ->get("https://{$host}/?lang=en")
            ->assertOk()
            ->assertSee('<html lang="en"', false)
            ->assertSee('<meta name="description" content="'.$description.'"', false)
            ->assertSee('<meta property="og:title" content="'.$meta['title'].'"', false)
            ->assertSee('<meta property="og:locale" content="en_US"', false);
    }

    public function test_lang_query_sets_landing_locale_cookie(): void
    {
        $host = $this->landingHost();
        URL::forceRootUrl('https://'.$host);

        $cookieName = (string) config('landing.cookie_name', 'landing_locale');

        $this->withoutVite()
            ->withServerVariables(['HTTP_HOST' => $host])
            ->get("https://{$host}/?lang=ru")
            ->assertOk()
            ->assertCookie($cookieName, 'ru');
    }

    public function test_landing_locale_cookie_is_used_for_meta(): void
    {
        $host = $this->landingHost();
        URL::forceRootUrl('https://'.$host);

        $cookieName = (string) config('landing.cookie_name', 'landing_locale');
        $description = config('landing.pages.home.ru.description');

        $this->withoutVite()
            ->withServerVariables(['HTTP_HOST' => $host])
            ->withCookie($cookieName, 'ru')
            ->get("https://{$host}/")
            ->assertOk()
            ->assertSee('<html lang="ru"', false)
            ->assertSee('<meta name="description" content="'.$description.'"', false);
    }

    public function test_calculator_page_has_page_specific_meta(): void
    {
        $host = $this->landingHost();
        URL::forceRootUrl('https://'.$host);

        $meta = config('landing.pages.calculator.kk');

        $this->withoutVite()
            ->withServerVariables(['HTTP_HOST' => $host])
            ->get("https://{$host}/calculator")
            ->assertOk()
            ->assertSee('<meta name="description" content="'.$meta['description'].'"', false)
            ->assertSee('<link rel="canonical" href="https://'.$host.'/calculator"', false);
    }

    public function test_not_found_page_has_noindex_robots(): void
    {
        $host = $this->landingHost();
        URL::forceRootUrl('https://'.$host);

        $this->withoutVite()
            ->withServerVariables(['HTTP_HOST' => $host])
            ->get("https://{$host}/404")
            ->assertNotFound()
            ->assertSee('<meta name="robots" content="noindex, follow"', false);
    }
}
