import type { Theme } from '@/composables/useTheme';

/** Цвета ленты сообщений: входящие / исходящие, текст, акцент (цитаты, ссылки). */
export type MessageStyleColors = {
    in: string;
    out: string;
    textIn: string;
    textOut: string;
    accent: string;
    tailShadow: string;
};

export interface MessageStylePreset {
    id: string;
    label: string;
    description: string;
    light: MessageStyleColors;
    dark: MessageStyleColors;
}

/** @deprecated alias */
export type BubbleColors = MessageStyleColors;
/** @deprecated alias */
export type BubblePreset = MessageStylePreset;

export const DEFAULT_MESSAGE_STYLE_ID = 'whatsapp';

/** @deprecated */
export const DEFAULT_BUBBLE_PRESET_ID = DEFAULT_MESSAGE_STYLE_ID;

export const messageStylePresets: MessageStylePreset[] = [
    {
        id: 'whatsapp',
        label: 'Зелёный',
        description: 'Классика WhatsApp',
        light: {
            in: '#FFFFFF',
            out: '#B8EBB0',
            textIn: '#111B21',
            textOut: '#0B3D2E',
            accent: '#008069',
            tailShadow: 'rgba(11, 20, 26, 0.08)',
        },
        dark: {
            in: '#2A3942',
            out: '#1E4D40',
            textIn: '#D1D7DB',
            textOut: '#E9EDEF',
            accent: '#6BC49A',
            tailShadow: 'rgba(0, 0, 0, 0.22)',
        },
    },
    {
        id: 'blue',
        label: 'Синий',
        description: 'Насыщенный голубой, как в Telegram',
        light: {
            in: '#FFFFFF',
            out: '#9FD0FF',
            textIn: '#111B21',
            textOut: '#0A3D8F',
            accent: '#0077CC',
            tailShadow: 'rgba(11, 60, 120, 0.1)',
        },
        dark: {
            in: '#2E3640',
            out: '#4A6E8F',
            textIn: '#C5CDD4',
            textOut: '#E8F0F8',
            accent: '#8BB8E8',
            tailShadow: 'rgba(0, 0, 0, 0.22)',
        },
    },
    {
        id: 'graphite',
        label: 'Графит',
        description: 'Тёмные исходящие в светлой теме, белые — в тёмной',
        light: {
            in: '#E9E9E9',
            out: '#3F3F3F',
            textIn: '#111111',
            textOut: '#FFFFFF',
            accent: '#5A5A5A',
            tailShadow: 'rgba(0, 0, 0, 0.18)',
        },
        dark: {
            in: '#333333',
            out: '#FFFFFF',
            textIn: '#D6D6D6',
            textOut: '#111111',
            accent: '#B8B8B8',
            tailShadow: 'rgba(0, 0, 0, 0.2)',
        },
    },
    {
        id: 'purple',
        label: 'Фиолетовый',
        description: 'Яркая лаванда',
        light: {
            in: '#FFFFFF',
            out: '#D4B8FF',
            textIn: '#111B21',
            textOut: '#4A148C',
            accent: '#7B1FA2',
            tailShadow: 'rgba(74, 20, 140, 0.1)',
        },
        dark: {
            in: '#342F3D',
            out: '#7E6B9A',
            textIn: '#C8C0D4',
            textOut: '#EDE7F6',
            accent: '#B39DDB',
            tailShadow: 'rgba(0, 0, 0, 0.22)',
        },
    },
    {
        id: 'ocean',
        label: 'Бирюза',
        description: 'Насыщенная морская гамма',
        light: {
            in: '#FFFFFF',
            out: '#8FE8D8',
            textIn: '#111B21',
            textOut: '#004D40',
            accent: '#00796B',
            tailShadow: 'rgba(0, 77, 64, 0.1)',
        },
        dark: {
            in: '#2E3A38',
            out: '#5A857C',
            textIn: '#B8CCC8',
            textOut: '#E0F2F1',
            accent: '#80CBC4',
            tailShadow: 'rgba(0, 0, 0, 0.22)',
        },
    },
    {
        id: 'coral',
        label: 'Коралл',
        description: 'Тёплый персиковый',
        light: {
            in: '#FFFFFF',
            out: '#FFB89A',
            textIn: '#111B21',
            textOut: '#BF360C',
            accent: '#E64A19',
            tailShadow: 'rgba(191, 54, 12, 0.12)',
        },
        dark: {
            in: '#3A322E',
            out: '#A67B6E',
            textIn: '#D7C4BC',
            textOut: '#FFF0EB',
            accent: '#FFAB91',
            tailShadow: 'rgba(0, 0, 0, 0.22)',
        },
    },
];

/** @deprecated */
export const bubblePresets = messageStylePresets;

const STORAGE_KEY = 'accel.message-style';
const LEGACY_STORAGE_KEY = 'accel.chat-bubbles';

export function getStoredMessageStyleId(): string {
    if (typeof window === 'undefined') {
        return DEFAULT_MESSAGE_STYLE_ID;
    }

    const stored = localStorage.getItem(STORAGE_KEY) ?? localStorage.getItem(LEGACY_STORAGE_KEY);
    if (!stored) {
        return DEFAULT_MESSAGE_STYLE_ID;
    }

    if (stored === 'default') {
        return 'whatsapp';
    }

    if (messageStylePresets.some((p) => p.id === stored)) {
        return stored;
    }

    return DEFAULT_MESSAGE_STYLE_ID;
}

/** @deprecated */
export const getStoredBubblePresetId = getStoredMessageStyleId;

export function storeMessageStyleId(id: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    localStorage.setItem(STORAGE_KEY, id);
    localStorage.removeItem(LEGACY_STORAGE_KEY);
}

/** @deprecated */
export const storeBubblePresetId = storeMessageStyleId;

export function findMessageStyle(id: string): MessageStylePreset {
    const normalized = id === 'default' ? 'whatsapp' : id;

    return messageStylePresets.find((preset) => preset.id === normalized) ?? messageStylePresets[0];
}

/** @deprecated */
export const findBubblePreset = findMessageStyle;

function hexChannel(pair: string): number {
    return parseInt(pair, 16) / 255;
}

/** Относительная яркость 0…1 — для выбора затемнения или осветления цитаты. */
function bubbleLuminance(hex: string): number {
    const raw = hex.replace('#', '');
    if (raw.length !== 6) {
        return 0.5;
    }

    const r = hexChannel(raw.slice(0, 2));
    const g = hexChannel(raw.slice(2, 4));
    const b = hexChannel(raw.slice(4, 6));

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function quoteAuthorColor(bubbleColor: string, textColor: string, accentColor: string): string {
    if (bubbleLuminance(bubbleColor) < 0.42) {
        return textColor;
    }

    if (bubbleLuminance(accentColor) < 0.55) {
        return accentColor;
    }

    return textColor;
}

function quoteBodyColor(textColor: string): string {
    return `color-mix(in srgb, ${textColor} 70%, transparent)`;
}

function applyQuoteTextVars(colors: MessageStyleColors, root: HTMLElement): void {
    root.style.setProperty(
        '--wa-bubble-quote-author-in',
        quoteAuthorColor(colors.in, colors.textIn, colors.accent),
    );
    root.style.setProperty(
        '--wa-bubble-quote-author-out',
        quoteAuthorColor(colors.out, colors.textOut, colors.accent),
    );
    root.style.setProperty('--wa-bubble-quote-text-in', quoteBodyColor(colors.textIn));
    root.style.setProperty('--wa-bubble-quote-text-out', quoteBodyColor(colors.textOut));
}

function quoteBg(color: string, theme: Theme, kind: 'in' | 'out'): string {
    const darkBubble = bubbleLuminance(color) < 0.42;

    if (darkBubble) {
        const amount = kind === 'out' ? '18%' : '12%';

        return `color-mix(in srgb, #fff ${amount}, ${color})`;
    }

    const mix = theme === 'light' ? '#000' : '#fff';
    const amount = kind === 'in' ? '5%' : '9%';

    return `color-mix(in srgb, ${mix} ${amount}, ${color})`;
}

/**
 * Пишет CSS-переменные ленты сообщений на <html>.
 * id «whatsapp» без override — цвета из [data-theme] в app.css.
 */
export function applyMessageStyle(preset: MessageStylePreset, theme: Theme): void {
    if (typeof document === 'undefined') {
        return;
    }

    const root = document.documentElement;
    const colors = theme === 'light' ? preset.light : preset.dark;

    root.dataset.messageStyle = preset.id;
    root.dataset.chatBubbles = preset.id;

    if (preset.id === 'whatsapp') {
        root.style.removeProperty('--wa-bubble-in');
        root.style.removeProperty('--wa-bubble-out');
        root.style.removeProperty('--wa-bubble-text');
        root.style.removeProperty('--wa-bubble-text-in');
        root.style.removeProperty('--wa-bubble-text-out');
        root.style.removeProperty('--wa-bubble-tail-shadow');
        root.style.removeProperty('--wa-bubble-quote-bg-in');
        root.style.removeProperty('--wa-bubble-quote-bg-out');
        root.style.removeProperty('--wa-bubble-quote-author-in');
        root.style.removeProperty('--wa-bubble-quote-author-out');
        root.style.removeProperty('--wa-bubble-quote-text-in');
        root.style.removeProperty('--wa-bubble-quote-text-out');
        root.style.removeProperty('--wa-message-accent');

        return;
    }

    root.style.setProperty('--wa-bubble-in', colors.in);
    root.style.setProperty('--wa-bubble-out', colors.out);
    root.style.setProperty('--wa-bubble-text-in', colors.textIn);
    root.style.setProperty('--wa-bubble-text-out', colors.textOut);
    root.style.setProperty('--wa-bubble-text', colors.textIn);
    root.style.setProperty('--wa-bubble-tail-shadow', colors.tailShadow);
    root.style.setProperty('--wa-message-accent', colors.accent);
    root.style.setProperty('--wa-bubble-quote-bg-in', quoteBg(colors.in, theme, 'in'));
    root.style.setProperty('--wa-bubble-quote-bg-out', quoteBg(colors.out, theme, 'out'));
    applyQuoteTextVars(colors, root);
}

/** @deprecated */
export const applyBubblePreset = applyMessageStyle;

export function messageStylePreview(preset: MessageStylePreset, theme: Theme): { in: string; out: string } {
    const colors = theme === 'light' ? preset.light : preset.dark;

    return { in: colors.in, out: colors.out };
}

/** @deprecated */
export const bubblePresetPreview = messageStylePreview;
