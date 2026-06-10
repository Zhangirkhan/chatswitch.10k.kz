import { router } from '@inertiajs/vue3';
import { onUnmounted, toValue, watch, type MaybeRefOrGetter } from 'vue';

const FAVICON_LINK_ID = 'accel-unread-favicon';
const BUBBLE = '#048B4F';
const TEXT = '#FFFFFF';
const LOGICAL = 128;
const SUPERSAMPLE = 4;

let cachedDefaultHref: string | null = null;
let unreadReloadTimer: ReturnType<typeof setTimeout> | number | null = null;

function resolveDefaultFaviconHref(): string {
    if (typeof window === 'undefined') {
        return '';
    }
    if (cachedDefaultHref !== null) {
        return cachedDefaultHref;
    }
    const links = document.querySelectorAll<HTMLLinkElement>('link[rel="icon"], link[rel="shortcut icon"]');
    for (const link of links) {
        if (link.id === FAVICON_LINK_ID) {
            continue;
        }
        if (link.href) {
            cachedDefaultHref = link.href;
            return cachedDefaultHref;
        }
    }
    cachedDefaultHref = new URL('/icons/icon-192.png', window.location.origin).href;
    return cachedDefaultHref;
}

function getOrCreateFaviconLink(): HTMLLinkElement {
    let el = document.getElementById(FAVICON_LINK_ID) as HTMLLinkElement | null;
    if (!el) {
        el = document.createElement('link');
        el.id = FAVICON_LINK_ID;
        el.rel = 'icon';
        el.type = 'image/png';
        document.head.appendChild(el);
    }
    el.setAttribute('sizes', `${LOGICAL}x${LOGICAL}`);
    return el;
}

function fontForLabel(label: string): string {
    if (label.length >= 3) {
        return '700 46px system-ui, -apple-system, "Segoe UI", sans-serif';
    }
    if (label.length === 2) {
        return '700 64px system-ui, -apple-system, "Segoe UI", sans-serif';
    }
    return '700 74px system-ui, -apple-system, "Segoe UI", sans-serif';
}

/**
 * Иконка вкладки: crisp vector bubble + динамическое число.
 */
export function applyUnreadChatsFavicon(count: number): void {
    if (typeof document === 'undefined' || typeof window === 'undefined') {
        return;
    }

    const n = Math.max(0, Math.floor(Number(count) || 0));
    const link = getOrCreateFaviconLink();

    if (n <= 0) {
        link.href = resolveDefaultFaviconHref();
        return;
    }

    const canvas = document.createElement('canvas');
    const px = Math.round(LOGICAL * SUPERSAMPLE);
    canvas.width = px;
    canvas.height = px;

    const ctx = canvas.getContext('2d');
    if (!ctx) {
        return;
    }

    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.scale(SUPERSAMPLE, SUPERSAMPLE);
    ctx.clearRect(0, 0, LOGICAL, LOGICAL);

    // Вектор вместо растянутого PNG: острые и чистые края даже после downscale вкладкой.
    ctx.fillStyle = BUBBLE;
    ctx.beginPath();
    ctx.arc(64, 58, 55, 0, Math.PI * 2);
    ctx.fill();
    ctx.beginPath();
    ctx.moveTo(18, 89);
    ctx.lineTo(7, 125);
    ctx.lineTo(52, 107);
    ctx.closePath();
    ctx.fill();

    const label = n > 99 ? '99+' : String(n);
    const textCx = 64.1;
    const textCy = 54.5;

    ctx.fillStyle = TEXT;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.font = fontForLabel(label);
    ctx.fillText(label, textCx, textCy);

    link.href = canvas.toDataURL('image/png');
}

export function scheduleUnreadChatsPropsReload(): void {
    if (typeof window === 'undefined') {
        return;
    }
    if (unreadReloadTimer !== null) {
        window.clearTimeout(unreadReloadTimer as number);
    }
    unreadReloadTimer = window.setTimeout(() => {
        unreadReloadTimer = null;
        try {
            router.reload({ only: ['unreadChatsCount'] });
        } catch {
            /* ignore */
        }
    }, 450);
}

export function useUnreadFavicon(count: MaybeRefOrGetter<number>): void {
    watch(
        () => toValue(count),
        (v) => applyUnreadChatsFavicon(v),
        { immediate: true },
    );

    onUnmounted(() => {
        applyUnreadChatsFavicon(0);
    });
}
