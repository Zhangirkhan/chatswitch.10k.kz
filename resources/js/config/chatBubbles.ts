import type { Theme } from '@/composables/useTheme';

export type BubbleColors = {
    in: string;
    out: string;
    text: string;
    tailShadow: string;
};

export interface BubblePreset {
    id: string;
    label: string;
    description: string;
    light: BubbleColors;
    dark: BubbleColors;
}

/** «По умолчанию» — цвета из [data-theme] в app.css (без override). */
export const DEFAULT_BUBBLE_PRESET_ID = 'default';

export const bubblePresets: BubblePreset[] = [
    {
        id: DEFAULT_BUBBLE_PRESET_ID,
        label: 'По умолчанию',
        description: 'Как в WhatsApp: светлые исходящие в светлой теме',
        light: {
            in: '#FFFFFF',
            out: '#D9FDD3',
            text: '#111B21',
            tailShadow: 'rgba(11, 20, 26, 0.08)',
        },
        dark: {
            in: '#202C33',
            out: '#005C4B',
            text: '#E9EDEF',
            tailShadow: 'rgba(0, 0, 0, 0.26)',
        },
    },
    {
        id: 'brand',
        label: 'Брендовый',
        description: 'Больше фирменного зелёного в исходящих',
        light: {
            in: '#FFFFFF',
            out: 'color-mix(in srgb, var(--brand-accent) 28%, #D9FDD3 72%)',
            text: '#111B21',
            tailShadow: 'rgba(11, 20, 26, 0.1)',
        },
        dark: {
            in: '#1F2C34',
            out: 'color-mix(in srgb, var(--brand-accent) 55%, #003B32 45%)',
            text: '#E9EDEF',
            tailShadow: 'rgba(0, 0, 0, 0.28)',
        },
    },
    {
        id: 'soft',
        label: 'Мягкий',
        description: 'Пастельные пузыри, меньше контраста',
        light: {
            in: '#FFFFFF',
            out: '#E8F5E3',
            text: '#111B21',
            tailShadow: 'rgba(11, 20, 26, 0.06)',
        },
        dark: {
            in: '#2A3942',
            out: '#1A4D42',
            text: '#E9EDEF',
            tailShadow: 'rgba(0, 0, 0, 0.22)',
        },
    },
    {
        id: 'contrast',
        label: 'Контраст',
        description: 'Ярче исходящие, чётче входящие',
        light: {
            in: '#FFFFFF',
            out: '#C5F0BC',
            text: '#0B141A',
            tailShadow: 'rgba(11, 20, 26, 0.12)',
        },
        dark: {
            in: '#233138',
            out: '#0B6B55',
            text: '#FFFFFF',
            tailShadow: 'rgba(0, 0, 0, 0.32)',
        },
    },
];

const STORAGE_KEY = 'accel.chat-bubbles';

export function getStoredBubblePresetId(): string {
    if (typeof window === 'undefined') {
        return DEFAULT_BUBBLE_PRESET_ID;
    }

    return localStorage.getItem(STORAGE_KEY) || DEFAULT_BUBBLE_PRESET_ID;
}

export function storeBubblePresetId(id: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    localStorage.setItem(STORAGE_KEY, id);
}

export function findBubblePreset(id: string): BubblePreset {
    return bubblePresets.find((preset) => preset.id === id) ?? bubblePresets[0];
}

/**
 * Пишет CSS-переменные пузырьков на <html>. preset «default» снимает override — работают токены темы.
 */
export function applyBubblePreset(preset: BubblePreset, theme: Theme): void {
    if (typeof document === 'undefined') {
        return;
    }

    const root = document.documentElement;
    const colors = theme === 'light' ? preset.light : preset.dark;

    root.dataset.chatBubbles = preset.id;

    if (preset.id === DEFAULT_BUBBLE_PRESET_ID) {
        root.style.removeProperty('--wa-bubble-in');
        root.style.removeProperty('--wa-bubble-out');
        root.style.removeProperty('--wa-bubble-text');
        root.style.removeProperty('--wa-bubble-tail-shadow');
        root.style.removeProperty('--wa-bubble-quote-bg');

        return;
    }

    root.style.setProperty('--wa-bubble-in', colors.in);
    root.style.setProperty('--wa-bubble-out', colors.out);
    root.style.setProperty('--wa-bubble-text', colors.text);
    root.style.setProperty('--wa-bubble-tail-shadow', colors.tailShadow);
    root.style.setProperty(
        '--wa-bubble-quote-bg',
        theme === 'light'
            ? 'color-mix(in srgb, #000 6%, var(--wa-bubble-in))'
            : 'color-mix(in srgb, #fff 8%, var(--wa-bubble-in))',
    );
}

/** Мини-превью для настроек: два пузыря in/out. */
export function bubblePresetPreview(preset: BubblePreset, theme: Theme): { in: string; out: string } {
    const colors = theme === 'light' ? preset.light : preset.dark;

    return { in: colors.in, out: colors.out };
}
