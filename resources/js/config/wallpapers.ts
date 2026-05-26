import type { Theme } from '@/composables/useTheme';

/**
 * Wallpaper definition. One of these is selected and applied by writing CSS
 * variables / a `data-chat-bg` attribute on the <html> element. The `.chat-bg`
 * selector in app.css + the theme-aware selectors below pick up the values.
 */
export interface Wallpaper {
    id: string;
    label: string;
    kind: 'default' | 'solid' | 'pattern';
    lightColor?: string;
    darkColor?: string;
    lightImage?: string;
    darkImage?: string;
    overlay?: {
        light: string;
        dark: string;
    };
    tileSize?: string;
}

function svgDataUri(svg: string): string {
    return `data:image/svg+xml;utf8,${encodeURIComponent(svg)}`;
}

const dotsPattern = (stroke: string): string =>
    svgDataUri(
        `<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40"><circle cx="4" cy="4" r="1.6" fill="${stroke}"/><circle cx="24" cy="24" r="1.6" fill="${stroke}"/></svg>`,
    );

const linesPattern = (stroke: string): string =>
    svgDataUri(
        `<svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" viewBox="0 0 60 60"><g stroke="${stroke}" stroke-width="1" fill="none" stroke-linecap="round"><path d="M0 30 Q15 15 30 30 T60 30"/><path d="M0 45 Q15 30 30 45 T60 45"/></g></svg>`,
    );

const trianglesPattern = (stroke: string): string =>
    svgDataUri(
        `<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 48 48"><g fill="${stroke}" opacity="0.65"><polygon points="8,8 16,8 12,16"/><polygon points="32,24 40,24 36,32"/><polygon points="20,36 28,36 24,44"/></g></svg>`,
    );

export const wallpapers: Wallpaper[] = [
    {
        id: 'default',
        label: 'По умолчанию',
        kind: 'default',
    },
    {
        id: 'plain',
        label: 'Без узора',
        kind: 'solid',
        lightColor: '#efeae2',
        darkColor: '#161717',
    },
    {
        id: 'mint',
        label: 'Мятный',
        kind: 'solid',
        lightColor: '#d9f2e0',
        darkColor: '#0e2b23',
    },
    {
        id: 'ocean',
        label: 'Океан',
        kind: 'solid',
        lightColor: '#d5e7f2',
        darkColor: '#0f1d2b',
    },
    {
        id: 'sand',
        label: 'Песок',
        kind: 'solid',
        lightColor: '#f2ead5',
        darkColor: '#24201a',
    },
    {
        id: 'dots',
        label: 'Точки',
        kind: 'pattern',
        lightColor: '#efeae2',
        darkColor: '#161717',
        lightImage: dotsPattern('%23b5a994'),
        darkImage: dotsPattern('%23243240'),
        tileSize: '40px',
    },
    {
        id: 'waves',
        label: 'Волны',
        kind: 'pattern',
        lightColor: '#d8e9f2',
        darkColor: '#0d1c2b',
        lightImage: linesPattern('%238ab4d0'),
        darkImage: linesPattern('%231d3a52'),
        tileSize: '60px',
    },
    {
        id: 'triangles',
        label: 'Треугольники',
        kind: 'pattern',
        lightColor: '#ede5d9',
        darkColor: '#1a1410',
        lightImage: trianglesPattern('%23a89179'),
        darkImage: trianglesPattern('%23342a22'),
        tileSize: '48px',
    },
];

const STORAGE_KEY = 'accel.wallpaper';

export function getStoredWallpaperId(): string {
    if (typeof window === 'undefined') return 'default';
    return localStorage.getItem(STORAGE_KEY) || 'default';
}

export function storeWallpaperId(id: string): void {
    if (typeof window === 'undefined') return;
    localStorage.setItem(STORAGE_KEY, id);
}

export function findWallpaper(id: string): Wallpaper {
    return wallpapers.find((w) => w.id === id) || wallpapers[0];
}

/**
 * Writes all CSS variables / data attributes on <html> that the `.chat-bg`
 * rules read. Called on boot and when the user picks a new wallpaper.
 */
export function applyWallpaper(wallpaper: Wallpaper, theme: Theme): void {
    if (typeof document === 'undefined') return;
    const root = document.documentElement;

    if (wallpaper.kind === 'default') {
        delete root.dataset.chatBg;
        root.style.removeProperty('--wa-chat-bg-image');
        root.style.removeProperty('--wa-chat-bg-overlay');
        root.style.removeProperty('--wa-chat-bg-size');
        root.style.removeProperty('--wa-chat-bg-color');
        return;
    }

    const color = theme === 'light' ? wallpaper.lightColor : wallpaper.darkColor;
    if (color) {
        root.style.setProperty('--wa-chat-bg-color', color);
    }

    if (wallpaper.kind === 'solid') {
        root.dataset.chatBg = 'solid';
        root.style.removeProperty('--wa-chat-bg-image');
        root.style.removeProperty('--wa-chat-bg-overlay');
        root.style.removeProperty('--wa-chat-bg-size');
        return;
    }

    const image = theme === 'light' ? wallpaper.lightImage : wallpaper.darkImage;
    if (image) {
        root.dataset.chatBg = 'pattern';
        root.style.setProperty('--wa-chat-bg-image', `url("${image}")`);
        root.style.setProperty('--wa-chat-bg-size', wallpaper.tileSize || '60px');
        root.style.setProperty(
            '--wa-chat-bg-overlay',
            wallpaper.overlay?.[theme] || 'transparent',
        );
    }
}

/** Returns a CSS `background` shorthand used for the picker thumbnail preview. */
export function wallpaperPreview(wallpaper: Wallpaper, theme: Theme): string {
    if (wallpaper.kind === 'default') {
        return theme === 'light' ? '#efeae2' : '#161717';
    }

    const color = theme === 'light' ? wallpaper.lightColor : wallpaper.darkColor;

    if (wallpaper.kind === 'solid') {
        return color || '#ccc';
    }

    const image = theme === 'light' ? wallpaper.lightImage : wallpaper.darkImage;
    return `${color || '#ccc'} url("${image}") repeat`;
}
