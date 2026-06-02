import path from 'path';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    resolve: {
        alias: {
            '@': path.resolve('resources/js'),
        },
    },
    build: {
        rollupOptions: {
            output: {
                // Тяжёлые библиотеки выносим в отдельные кэшируемые чанки, чтобы
                // они не дублировались между страницами и кэшировались браузером.
                manualChunks(id) {
                    if (!id.includes('node_modules')) {
                        return undefined;
                    }
                    if (id.includes('chart.js') || id.includes('vue-chartjs')) {
                        return 'vendor-charts';
                    }
                    if (id.includes('@tiptap') || id.includes('prosemirror')) {
                        return 'vendor-editor';
                    }
                    // mermaid намеренно НЕ группируем: он грузится лениво и сам
                    // бьётся на мелкие чанки (>2 МБ единым файлом ломает precache PWA).
                    return undefined;
                },
            },
        },
    },
    plugins: [
        laravel({
            input: [
                'resources/js/app.ts',
                'resources/js/Pages/AiChat/Index.vue',
                'resources/js/Pages/Landing/Home.vue',
                'resources/js/Pages/Landing/Calculator.vue',
            ],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        VitePWA({
            // Service worker живёт в public/sw.js, чтобы scope был /
            filename: 'sw.js',
            registerType: 'autoUpdate',

            // Стратегия: injectManifest даёт нам полный контроль над SW
            // Используем generateSW для простоты
            strategies: 'generateSW',

            // Не перехватывать навигацию — Inertia сама управляет SPA-роутингом
            injectManifest: {
                injectionPoint: undefined,
            },

            workbox: {
                // Precache — статические ассеты Vite
                globPatterns: ['**/*.{js,css,woff2}'],
                globDirectory: 'public/build',
                swDest: 'public/sw.js',

                // Навигационные запросы (HTML страницы) — всегда с сети
                // чтобы Inertia/Laravel работали корректно
                navigateFallback: null,

                runtimeCaching: [
                    // Шрифты Google/Bunny
                    {
                        urlPattern: /^https:\/\/fonts\.(bunny|gstatic|googleapis)\.com\/.*/i,
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'accel-fonts',
                            expiration: { maxEntries: 20, maxAgeSeconds: 60 * 60 * 24 * 365 },
                            cacheableResponse: { statuses: [0, 200] },
                        },
                    },
                    // Изображения (аватары, медиа) — StaleWhileRevalidate
                    {
                        urlPattern: /\/storage\/.*\.(png|jpg|jpeg|gif|webp|svg)/i,
                        handler: 'StaleWhileRevalidate',
                        options: {
                            cacheName: 'accel-media',
                            expiration: { maxEntries: 200, maxAgeSeconds: 60 * 60 * 24 * 7 },
                        },
                    },
                    // Иконки приложения
                    {
                        urlPattern: /\/icons\/.*\.png$/i,
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'accel-icons',
                            expiration: { maxEntries: 10, maxAgeSeconds: 60 * 60 * 24 * 30 },
                        },
                    },
                ],
            },

            manifest: {
                name: 'Accel',
                short_name: 'Accel',
                description: 'Multi-WhatsApp Management — чаты, задачи, аналитика',
                start_url: '/chats',
                scope: '/',
                display: 'standalone',
                orientation: 'portrait-primary',
                background_color: '#111b21',
                theme_color: '#25d366',
                lang: 'ru',
                icons: [
                    {
                        src: '/icons/icon-192.png',
                        sizes: '192x192',
                        type: 'image/png',
                        purpose: 'any',
                    },
                    {
                        src: '/icons/icon-512.png',
                        sizes: '512x512',
                        type: 'image/png',
                        purpose: 'any',
                    },
                    {
                        src: '/icons/icon-512-maskable.png',
                        sizes: '512x512',
                        type: 'image/png',
                        purpose: 'maskable',
                    },
                ],
                shortcuts: [
                    {
                        name: 'Чаты',
                        short_name: 'Чаты',
                        url: '/chats',
                        icons: [{ src: '/icons/icon-192.png', sizes: '192x192' }],
                    },
                    {
                        name: 'Организация',
                        short_name: 'Задачи',
                        url: '/organization',
                        icons: [{ src: '/icons/icon-192.png', sizes: '192x192' }],
                    },
                    {
                        name: 'Аналитика',
                        short_name: 'Аналитика',
                        url: '/analytics',
                        icons: [{ src: '/icons/icon-192.png', sizes: '192x192' }],
                    },
                ],
                categories: ['business', 'productivity'],
            },
        }),
    ],
});
