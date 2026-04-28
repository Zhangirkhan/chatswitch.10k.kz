import '../css/app.css';
import './bootstrap';
import { initTheme } from './composables/useTheme';
import { initChatBackground } from './composables/useChatBackground';
import { installKeyboardShortcuts } from './composables/useKeyboardShortcuts';

initTheme();
initChatBackground();
installKeyboardShortcuts();

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, DefineComponent, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

/** После деплоя браузер может держать старый entry и 404 на чанке страницы — Vite кидает vite:preloadError. */
window.addEventListener('vite:preloadError', (event: Event) => {
    event.preventDefault();
    const key = 'chatswitch.vite_preload_reload_at';
    const now = Date.now();
    const last = Number(sessionStorage.getItem(key) || '0');
    if (now - last > 4000) {
        sessionStorage.setItem(key, String(now));
        window.location.reload();
    }
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
