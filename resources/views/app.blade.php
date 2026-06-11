<!DOCTYPE html>
<html lang="{{ isset($landingMeta) ? $landingMeta['locale'] : str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @isset($landingMeta)
            <title>{{ $landingMeta['title'] }}</title>
            <meta name="description" content="{{ $landingMeta['description'] }}">
            @if(! empty($landingMeta['robots']))
                <meta name="robots" content="{{ $landingMeta['robots'] }}">
            @endif
            <link rel="canonical" href="{{ $landingMeta['canonical_url'] }}">
            @foreach($landingMeta['alternates'] as $alternate)
                <link rel="alternate" hreflang="{{ $alternate['hreflang'] }}" href="{{ $alternate['href'] }}">
            @endforeach
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="Accel">
            <meta property="og:title" content="{{ $landingMeta['title'] }}">
            <meta property="og:description" content="{{ $landingMeta['description'] }}">
            <meta property="og:image" content="{{ $landingMeta['og_image'] }}">
            <meta property="og:image:width" content="{{ $landingMeta['og_image_width'] }}">
            <meta property="og:image:height" content="{{ $landingMeta['og_image_height'] }}">
            <meta property="og:url" content="{{ $landingMeta['og_url'] }}">
            <meta property="og:locale" content="{{ $landingMeta['og_locale'] }}">
            @foreach($landingMeta['og_locale_alternate'] as $ogLocaleAlternate)
                <meta property="og:locale:alternate" content="{{ $ogLocaleAlternate }}">
            @endforeach
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:title" content="{{ $landingMeta['title'] }}">
            <meta name="twitter:description" content="{{ $landingMeta['description'] }}">
            <meta name="twitter:image" content="{{ $landingMeta['og_image'] }}">
            @if($googleVerification = config('landing.google_site_verification'))
                <meta name="google-site-verification" content="{{ $googleVerification }}">
            @endif
            @if($yandexVerification = config('landing.yandex_verification'))
                <meta name="yandex-verification" content="{{ $yandexVerification }}">
            @endif
            @if(! empty($landingStructuredData))
                @foreach($landingStructuredData as $graph)
                    <script type="application/ld+json">{!! json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
                @endforeach
            @endif
        @endisset

        <!-- PWA -->
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="Accel">
        <meta name="theme-color" content="#048B4F">
        <meta name="application-name" content="Accel">
        @php
            $faviconVersion = @filemtime(public_path('favicon.ico')) ?: '1';
        @endphp
        <link rel="manifest" href="/build/manifest.webmanifest">
        <link rel="icon" type="image/png" sizes="32x32" href="/favicon.ico?v={{ $faviconVersion }}">
        <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png?v={{ $faviconVersion }}">
        <link rel="icon" type="image/png" sizes="512x512" href="/icons/icon-512.png?v={{ $faviconVersion }}">
        <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png?v={{ $faviconVersion }}">
        <link rel="shortcut icon" href="/favicon.ico?v={{ $faviconVersion }}">

        @unless(isset($landingMeta))
            <title inertia>{{ config('app.name', 'Laravel') }}</title>
        @endunless

        <script>
            (function() {
                try {
                    var t = localStorage.getItem('accel.theme');
                    if (t !== 'light' && t !== 'dark') {
                        t = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
                    }
                    document.documentElement.dataset.theme = t;
                    document.documentElement.style.colorScheme = t;
                } catch (e) {
                    document.documentElement.dataset.theme = 'dark';
                }
            })();
        </script>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.ts', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @isset($landingMeta)
            @include('partials.landing-analytics')
        @endisset
        @inertia
    </body>
</html>
