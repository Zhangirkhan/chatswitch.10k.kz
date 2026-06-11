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

    /** @return array{locale: string, title: string, description: string, og_image: string, og_url: string} */
    public static function meta(string $locale, ?string $url = null): array
    {
        if (! self::isSupported($locale)) {
            $locale = (string) config('landing.default_locale', 'kk');
        }

        /** @var array{title?: string, description?: string, og_image?: string} $configured */
        $configured = (array) config("landing.meta.{$locale}", []);
        /** @var array{title?: string, description?: string, og_image?: string} $fallback */
        $fallback = (array) config('landing.meta.kk', []);

        $title = (string) ($configured['title'] ?? $fallback['title'] ?? 'Accel');
        $description = (string) ($configured['description'] ?? $fallback['description'] ?? '');
        $imagePath = (string) ($configured['og_image'] ?? $fallback['og_image'] ?? '/icons/icon-512.png');

        return [
            'locale' => $locale,
            'title' => $title,
            'description' => $description,
            'og_image' => url($imagePath),
            'og_url' => $url ?? url('/'),
        ];
    }
}
