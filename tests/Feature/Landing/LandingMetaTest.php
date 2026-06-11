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

        $description = config('landing.meta.kk.description');

        $this->withoutVite()
            ->withServerVariables(['HTTP_HOST' => $host])
            ->get("https://{$host}/")
            ->assertOk()
            ->assertSee('<html lang="kk"', false)
            ->assertSee('<meta name="description" content="'.$description.'"', false)
            ->assertSee('<meta property="og:title" content="'.config('landing.meta.kk.title').'"', false)
            ->assertSee('<meta property="og:description" content="'.$description.'"', false);
    }

    public function test_lang_query_switches_og_meta_to_english(): void
    {
        $host = $this->landingHost();
        URL::forceRootUrl('https://'.$host);

        $description = config('landing.meta.en.description');

        $this->withoutVite()
            ->withServerVariables(['HTTP_HOST' => $host])
            ->get("https://{$host}/?lang=en")
            ->assertOk()
            ->assertSee('<html lang="en"', false)
            ->assertSee('<meta name="description" content="'.$description.'"', false)
            ->assertSee('<meta property="og:title" content="'.config('landing.meta.en.title').'"', false);
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
        $description = config('landing.meta.ru.description');

        $this->withoutVite()
            ->withServerVariables(['HTTP_HOST' => $host])
            ->withCookie($cookieName, 'ru')
            ->get("https://{$host}/")
            ->assertOk()
            ->assertSee('<html lang="ru"', false)
            ->assertSee('<meta name="description" content="'.$description.'"', false);
    }
}
