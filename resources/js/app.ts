import '../css/app.css';
import '../css/wa-chat-composer.css';
import '../css/wa-message-bubbles.css';
import './bootstrap';
import { initTheme } from './composables/useTheme';
import { initI18n } from './composables/useI18n';
import { initLandingLocale } from './composables/useLandingLocale';
import { initChatBackground } from './composables/useChatBackground';
import { initChatBubbles } from './composables/useChatBubbles';
import { installKeyboardShortcuts } from './composables/useKeyboardShortcuts';
import { useConnectionStatus } from './composables/useConnectionStatus';
import { registerSW } from 'virtual:pwa-register';

/** Service Worker — авто-обновление каждые 60 мин без перезагрузки страницы. */
registerSW({
    onNeedRefresh() {
        // Когда выходит новая версия SW — тихо применяем при следующем открытии
    },
    onOfflineReady() {
        console.info('[PWA] Приложение готово к работе офлайн.');
    },
    immediate: true,
});

initTheme();
initChatBubbles();
initChatBackground();
installKeyboardShortcuts();
useConnectionStatus();

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, DefineComponent, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

const PAGE_SCROLLABLE_PREFIXES = ['Landing/'];

function syncPageScroll(component: string): void {
    const isLanding = component.startsWith('Landing/');
    document.documentElement.classList.toggle('page-scrollable', isLanding);
    document.documentElement.classList.toggle('page-landing', isLanding);
}

router.on('navigate', (event) => {
    syncPageScroll(event.detail.page.component);
});

/** После деплоя браузер может держать старый entry и 404 на чанке страницы — Vite кидает vite:preloadError. */
/** 419: сессия/CSRF истекли — полная перезагрузка даёт новые cookie и meta. */
document.addEventListener(
    'inertia:invalid',
    (event: Event) => {
        const e = event as CustomEvent<{ response?: { status?: number } }>;
        if (e.detail?.response?.status === 419) {
            e.preventDefault();
            window.location.reload();
        }
    },
    { passive: false },
);

window.addEventListener('vite:preloadError', (event: Event) => {
    event.preventDefault();
    const key = 'accel.vite_preload_reload_at';
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
        syncPageScroll(props.initialPage.component);
        if (props.initialPage.component.startsWith('Landing/')) {
            initLandingLocale(String((props.initialPage.props as { appLocale?: string }).appLocale ?? 'kk'));
        } else {
            initI18n(String((props.initialPage.props as { appLocale?: string }).appLocale ?? 'ru'));
        }

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
