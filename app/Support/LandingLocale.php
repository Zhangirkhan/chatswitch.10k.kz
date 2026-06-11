<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

final class LandingLocale
{
    /** @return list<string> */
    public static function supported(): array
    {
        return array_values((array) config('landing.supported_locales', ['kk', 'ru', 'en']));
    }

    public static function resolve(Request $request): string
    {
        $query = $request->query('lang');
        if (is_string($query) && self::isSupported($query)) {
            return $query;
        }

        $cookieName = (string) config('landing.cookie_name', 'landing_locale');
        $cookie = $request->cookie($cookieName);
        if (is_string($cookie) && self::isSupported($cookie)) {
            return $cookie;
        }

        return (string) config('landing.default_locale', 'kk');
    }

    public static function isSupported(string $locale): bool
    {
        return in_array($locale, self::supported(), true);
    }

    public static function resolvePage(Request $request): string
    {
        $routeName = $request->route()?->getName();

        return match ($routeName) {
            'landing.calculator' => 'calculator',
            'landing.not-found' => 'not_found',
            default => 'home',
        };
    }

    public static function baseUrl(): string
    {
        $configured = (string) config('app.url', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $domain = (string) config('tenancy.root_domain', 'accel.kz');

        return 'https://'.$domain;
    }

    public static function pagePath(string $page): string
    {
        /** @var array{path?: string} $configured */
        $configured = (array) config("landing.pages.{$page}", []);

        return (string) ($configured['path'] ?? '/');
    }

    public static function pageUrl(string $page, ?string $locale = null): string
    {
        $path = self::pagePath($page);
        $url = self::baseUrl().($path === '/' ? '/' : $path);

        if ($locale !== null && self::isSupported($locale)) {
            $default = (string) config('landing.default_locale', 'kk');
            if ($locale !== $default) {
                $separator = str_contains($url, '?') ? '&' : '?';

                return $url.$separator.'lang='.$locale;
            }
        }

        return $url;
    }

    /** @return list<array{hreflang: string, href: string}> */
    public static function alternates(string $page): array
    {
        $alternates = [];

        foreach (self::supported() as $locale) {
            $alternates[] = [
                'hreflang' => $locale,
                'href' => self::pageUrl($page, $locale),
            ];
        }

        $alternates[] = [
            'hreflang' => 'x-default',
            'href' => self::pageUrl($page, (string) config('landing.default_locale', 'kk')),
        ];

        return $alternates;
    }

    public static function ogLocale(string $locale): string
    {
        /** @var array<string, string> $map */
        $map = (array) config('landing.og_locale_map', []);

        return $map[$locale] ?? $locale;
    }

    /** @return list<string> */
    public static function ogLocaleAlternates(string $locale): array
    {
        return array_values(array_filter(
            array_map(
                static fn (string $supported): string => self::ogLocale($supported),
                self::supported(),
            ),
            static fn (string $ogLocale): bool => $ogLocale !== self::ogLocale($locale),
        ));
    }

    /**
     * @return array{
     *     locale: string,
     *     page: string,
     *     title: string,
     *     description: string,
     *     og_image: string,
     *     og_image_width: int,
     *     og_image_height: int,
     *     og_url: string,
     *     canonical_url: string,
     *     og_locale: string,
     *     og_locale_alternate: list<string>,
     *     alternates: list<array{hreflang: string, href: string}>,
     *     robots: string|null
     * }
     */
    public static function meta(string $locale, string $page, ?string $url = null): array
    {
        if (! self::isSupported($locale)) {
            $locale = (string) config('landing.default_locale', 'kk');
        }

        if (! in_array($page, ['home', 'calculator', 'not_found'], true)) {
            $page = 'home';
        }

        /** @var array{title?: string, description?: string, og_image?: string} $configured */
        $configured = (array) config("landing.pages.{$page}.{$locale}", []);
        if ($page === 'home' && $configured === []) {
            $configured = (array) config("landing.meta.{$locale}", []);
        }
        /** @var array{title?: string, description?: string, og_image?: string} $fallback */
        $fallback = (array) config('landing.pages.home.kk', config('landing.meta.kk', []));

        $title = (string) ($configured['title'] ?? $fallback['title'] ?? 'Accel');
        $description = (string) ($configured['description'] ?? $fallback['description'] ?? '');
        $imagePath = (string) ($configured['og_image'] ?? $fallback['og_image'] ?? '/icons/icon-512.png');
        $canonicalUrl = self::pageUrl($page === 'not_found' ? 'home' : $page, $locale);

        return [
            'locale' => $locale,
            'page' => $page,
            'title' => $title,
            'description' => $description,
            'og_image' => url($imagePath),
            'og_image_width' => (int) config('landing.og_image_width', 1200),
            'og_image_height' => (int) config('landing.og_image_height', 630),
            'og_url' => $url ?? $canonicalUrl,
            'canonical_url' => $canonicalUrl,
            'og_locale' => self::ogLocale($locale),
            'og_locale_alternate' => self::ogLocaleAlternates($locale),
            'alternates' => self::alternates($page === 'not_found' ? 'home' : $page),
            'robots' => $page === 'not_found' ? 'noindex, follow' : null,
        ];
    }
}
