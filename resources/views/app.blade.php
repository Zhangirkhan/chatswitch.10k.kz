<!DOCTYPE html>
<html lang="{{ isset($landingMeta) ? $landingMeta['locale'] : str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @isset($landingMeta)
            <meta name="description" content="{{ $landingMeta['description'] }}">
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="Accel">
            <meta property="og:title" content="{{ $landingMeta['title'] }}">
            <meta property="og:description" content="{{ $landingMeta['description'] }}">
            <meta property="og:image" content="{{ $landingMeta['og_image'] }}">
            <meta property="og:url" content="{{ $landingMeta['og_url'] }}">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:title" content="{{ $landingMeta['title'] }}">
            <meta name="twitter:description" content="{{ $landingMeta['description'] }}">
            <meta name="twitter:image" content="{{ $landingMeta['og_image'] }}">
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

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

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
        @inertia
    </body>
</html>
