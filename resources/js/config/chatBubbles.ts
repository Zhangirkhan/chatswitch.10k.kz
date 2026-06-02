import type { Theme } from '@/composables/useTheme';

/** Цвета ленты сообщений: входящие / исходящие, текст, акцент (цитаты, ссылки). */
export type MessageStyleColors = {
    in: string;
    out: string;
    textIn: string;
    textOut: string;
    accent: string;
    /** Акцент всего интерфейса (кнопки, вкладки, бейджи). По умолчанию = accent. */
    systemAccent?: string;
    systemAccentHover?: string;
    tailShadow: string;
    quoteBgIn?: string;
    quoteBgOut?: string;
    quoteAuthorIn?: string;
    quoteAuthorOut?: string;
    quoteTextIn?: string;
    quoteTextOut?: string;
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

/** Нейтральный вторичный текст цитаты на светлом фоне (без синевы). */
const QUOTE_MUTED_LIGHT = '#5E686F';
/** Вторичный текст цитаты на тёмном / насыщенном пузыре. */
const QUOTE_MUTED_ON_DARK = '#D8DEE3';

export const messageStylePresets: MessageStylePreset[] = [
    {
        id: 'whatsapp',
        label: 'Green',
        description: 'Classic WhatsApp',
        light: {
            in: '#FFFFFF',
            out: '#D9FDD3',
            textIn: '#111B21',
            textOut: '#111B21',
            accent: '#008069',
            systemAccent: '#01B964',
            systemAccentHover: '#08D878',
            tailShadow: 'rgba(11, 20, 26, 0.07)',
            quoteBgIn: '#F0F2F5',
            quoteBgOut: '#C8EBC4',
            quoteAuthorIn: '#008069',
            quoteAuthorOut: '#008069',
            quoteTextIn: QUOTE_MUTED_LIGHT,
            quoteTextOut: QUOTE_MUTED_LIGHT,
        },
        dark: {
            in: '#202C33',
            out: '#005C4B',
            textIn: '#E9EDEF',
            textOut: '#E9EDEF',
            accent: '#53BDAE',
            systemAccent: '#01B964',
            systemAccentHover: '#08D878',
            tailShadow: 'rgba(0, 0, 0, 0.24)',
            quoteBgIn: '#2A3942',
            quoteBgOut: '#0A4A3D',
            quoteAuthorIn: '#53BDAE',
            quoteAuthorOut: '#53BDAE',
            quoteTextIn: QUOTE_MUTED_ON_DARK,
            quoteTextOut: QUOTE_MUTED_ON_DARK,
        },
    },
    {
        id: 'blue',
        label: 'Blue',
        description: 'Telegram blue',
        light: {
            in: '#FFFFFF',
            out: '#3390EC',
            textIn: '#111B21',
            textOut: '#FFFFFF',
            accent: '#2481CC',
            systemAccent: '#3390EC',
            tailShadow: 'rgba(13, 60, 120, 0.12)',
            quoteBgIn: '#F0F4F8',
            quoteBgOut: '#2B7BC4',
            quoteAuthorIn: '#2481CC',
            quoteAuthorOut: '#FFFFFF',
            quoteTextIn: QUOTE_MUTED_LIGHT,
            quoteTextOut: '#E8F4FC',
        },
        dark: {
            in: '#212121',
            out: '#3390EC',
            textIn: '#ECECEC',
            textOut: '#FFFFFF',
            accent: '#6AB3F0',
            systemAccent: '#3390EC',
            tailShadow: 'rgba(0, 0, 0, 0.24)',
            quoteBgIn: '#2C2C2C',
            quoteBgOut: '#2878C2',
            quoteAuthorIn: '#6AB3F0',
            quoteAuthorOut: '#FFFFFF',
            quoteTextIn: QUOTE_MUTED_ON_DARK,
            quoteTextOut: '#E8F4FC',
        },
    },
    {
        id: 'graphite',
        label: 'Graphite',
        description: 'Neutral gray',
        light: {
            in: '#F1F1F1',
            out: '#2C2C2C',
            textIn: '#000000',
            textOut: '#FFFFFF',
            accent: '#D1103A',
            systemAccent: '#2C2C2C',
            tailShadow: 'rgba(0, 0, 0, 0.14)',
            quoteBgIn: '#E4E4E4',
            quoteBgOut: '#3D3D3D',
            quoteAuthorIn: '#D1103A',
            quoteAuthorOut: '#FFFFFF',
            quoteTextIn: '#1A1A1A',
            quoteTextOut: '#FFFFFF',
        },
        dark: {
            in: '#2C2C2C',
            out: '#FFFFFF',
            textIn: '#FFFFFF',
            textOut: '#111B21',
            accent: '#E8E8E8',
            systemAccent: '#B0B0B0',
            tailShadow: 'rgba(0, 0, 0, 0.22)',
            quoteBgIn: '#3D3D3D',
            quoteBgOut: '#E4E4E4',
            quoteAuthorIn: '#FFFFFF',
            quoteAuthorOut: '#111B21',
            quoteTextIn: '#FFFFFF',
            quoteTextOut: '#111B21',
        },
    },
    {
        id: 'purple',
        label: 'Purple',
        description: 'Calm lavender',
        light: {
            in: '#FFFFFF',
            out: '#7C5CBF',
            textIn: '#111B21',
            textOut: '#FFFFFF',
            accent: '#6A4FA8',
            systemAccent: '#7C5CBF',
            tailShadow: 'rgba(60, 30, 100, 0.12)',
            quoteBgIn: '#F3F0F8',
            quoteBgOut: '#6B52AD',
            quoteAuthorIn: '#6A4FA8',
            quoteAuthorOut: '#FFFFFF',
            quoteTextIn: QUOTE_MUTED_LIGHT,
            quoteTextOut: '#EDE8F5',
        },
        dark: {
            in: '#2A2830',
            out: '#8E7BB8',
            textIn: '#E8E4EF',
            textOut: '#FFFFFF',
            accent: '#B8A8D8',
            systemAccent: '#8E7BB8',
            tailShadow: 'rgba(0, 0, 0, 0.24)',
            quoteBgIn: '#35323D',
            quoteBgOut: '#7A6AA0',
            quoteAuthorIn: '#B8A8D8',
            quoteAuthorOut: '#FFFFFF',
            quoteTextIn: QUOTE_MUTED_ON_DARK,
            quoteTextOut: '#F0ECF8',
        },
    },
    {
        id: 'ocean',
        label: 'Teal',
        description: 'Muted sea tones',
        light: {
            in: '#FFFFFF',
            out: '#3D9B8F',
            textIn: '#111B21',
            textOut: '#FFFFFF',
            accent: '#2A7A70',
            systemAccent: '#3D9B8F',
            tailShadow: 'rgba(20, 80, 72, 0.12)',
            quoteBgIn: '#EFF5F4',
            quoteBgOut: '#358F84',
            quoteAuthorIn: '#2A7A70',
            quoteAuthorOut: '#FFFFFF',
            quoteTextIn: QUOTE_MUTED_LIGHT,
            quoteTextOut: '#E6F5F2',
        },
        dark: {
            in: '#252E2C',
            out: '#4F8A82',
            textIn: '#DDE8E6',
            textOut: '#FFFFFF',
            accent: '#7FBFB4',
            systemAccent: '#4F8A82',
            tailShadow: 'rgba(0, 0, 0, 0.24)',
            quoteBgIn: '#2F3A38',
            quoteBgOut: '#457A72',
            quoteAuthorIn: '#7FBFB4',
            quoteAuthorOut: '#FFFFFF',
            quoteTextIn: QUOTE_MUTED_ON_DARK,
            quoteTextOut: '#E8F5F2',
        },
    },
    {
        id: 'coral',
        label: 'Coral',
        description: 'Warm terracotta',
        light: {
            in: '#FFFFFF',
            out: '#D9725C',
            textIn: '#111B21',
            textOut: '#FFFFFF',
            accent: '#B85A48',
            systemAccent: '#D9725C',
            tailShadow: 'rgba(120, 50, 40, 0.12)',
            quoteBgIn: '#FAF0EE',
            quoteBgOut: '#C46552',
            quoteAuthorIn: '#B85A48',
            quoteAuthorOut: '#FFFFFF',
            quoteTextIn: QUOTE_MUTED_LIGHT,
            quoteTextOut: '#FDEEEA',
        },
        dark: {
            in: '#2E2826',
            out: '#B87A6A',
            textIn: '#EDE4E1',
            textOut: '#FFFFFF',
            accent: '#E0A090',
            systemAccent: '#B87A6A',
            tailShadow: 'rgba(0, 0, 0, 0.24)',
            quoteBgIn: '#3A322F',
            quoteBgOut: '#A66E5E',
            quoteAuthorIn: '#E0A090',
            quoteAuthorOut: '#FFFFFF',
            quoteTextIn: QUOTE_MUTED_ON_DARK,
            quoteTextOut: '#FDEEEA',
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

function quoteBodyColor(textColor: string, bubbleColor: string): string {
    if (bubbleLuminance(bubbleColor) < 0.42) {
        return `color-mix(in srgb, ${textColor} 88%, transparent)`;
    }

    return bubbleLuminance(textColor) > 0.55 ? QUOTE_MUTED_LIGHT : QUOTE_MUTED_ON_DARK;
}

function quoteBg(color: string, theme: Theme, kind: 'in' | 'out'): string {
    const darkBubble = bubbleLuminance(color) < 0.42;

    if (darkBubble) {
        const amount = kind === 'out' ? '14%' : '10%';

        return `color-mix(in srgb, #fff ${amount}, ${color})`;
    }

    const mix = theme === 'light' ? '#000' : '#fff';
    const amount = kind === 'in' ? '4%' : '7%';

    return `color-mix(in srgb, ${mix} ${amount}, ${color})`;
}

function systemAccentHoverColor(base: string, theme: Theme, explicit?: string): string {
    if (explicit) {
        return explicit;
    }

    return theme === 'light'
        ? `color-mix(in srgb, ${base} 82%, #000)`
        : `color-mix(in srgb, ${base} 78%, #fff)`;
}

/** Текст ссылок/лейблов на светлой теме — чуть темнее системного акцента. */
function systemChromaFg(base: string, theme: Theme): string {
    return theme === 'light'
        ? `color-mix(in srgb, ${base} 70%, #000)`
        : `color-mix(in srgb, ${base} 88%, #fff)`;
}

/**
 * Синхронизирует --brand-accent и производные (кнопки, вкладки, chroma, ui-*).
 * CSS-переменные с color-mix(..., var(--brand-accent)) пересчитываются автоматически.
 */
function applySystemAccent(colors: MessageStyleColors, theme: Theme, root: HTMLElement): void {
    const base = colors.systemAccent ?? colors.accent;
    const hover = systemAccentHoverColor(base, theme, colors.systemAccentHover);

    root.style.setProperty('--brand-accent', base);
    root.style.setProperty('--brand-accent-hover', hover);
    root.style.setProperty('--wa-chroma-accent-fg', systemChromaFg(base, theme));
}

function applyQuoteTextVars(colors: MessageStyleColors, root: HTMLElement): void {
    root.style.setProperty(
        '--wa-bubble-quote-author-in',
        colors.quoteAuthorIn ?? quoteAuthorColor(colors.in, colors.textIn, colors.accent),
    );
    root.style.setProperty(
        '--wa-bubble-quote-author-out',
        colors.quoteAuthorOut ?? quoteAuthorColor(colors.out, colors.textOut, colors.accent),
    );
    root.style.setProperty(
        '--wa-bubble-quote-text-in',
        colors.quoteTextIn ?? quoteBodyColor(colors.textIn, colors.in),
    );
    root.style.setProperty(
        '--wa-bubble-quote-text-out',
        colors.quoteTextOut ?? quoteBodyColor(colors.textOut, colors.out),
    );
}

/**
 * Пишет CSS-переменные ленты сообщений на <html>.
 */
export function applyMessageStyle(preset: MessageStylePreset, theme: Theme): void {
    if (typeof document === 'undefined') {
        return;
    }

    const root = document.documentElement;
    const colors = theme === 'light' ? preset.light : preset.dark;

    root.dataset.messageStyle = preset.id;
    root.dataset.chatBubbles = preset.id;

    root.style.setProperty('--wa-bubble-in', colors.in);
    root.style.setProperty('--wa-bubble-out', colors.out);
    root.style.setProperty('--wa-bubble-text-in', colors.textIn);
    root.style.setProperty('--wa-bubble-text-out', colors.textOut);
    root.style.setProperty('--wa-bubble-text', colors.textIn);
    root.style.setProperty('--wa-bubble-tail-shadow', colors.tailShadow);
    root.style.setProperty('--wa-message-accent', colors.accent);
    root.style.setProperty(
        '--wa-bubble-quote-bg-in',
        colors.quoteBgIn ?? quoteBg(colors.in, theme, 'in'),
    );
    root.style.setProperty(
        '--wa-bubble-quote-bg-out',
        colors.quoteBgOut ?? quoteBg(colors.out, theme, 'out'),
    );
    applyQuoteTextVars(colors, root);
    applySystemAccent(colors, theme, root);
}

/** @deprecated */
export const applyBubblePreset = applyMessageStyle;

export function messageStylePreview(preset: MessageStylePreset, theme: Theme): { in: string; out: string } {
    const colors = theme === 'light' ? preset.light : preset.dark;

    return { in: colors.in, out: colors.out };
}

/** @deprecated */
export const bubblePresetPreview = messageStylePreview;
