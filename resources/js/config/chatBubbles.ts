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
        description: 'Классика WhatsApp — светлые исходящие',
        light: {
            in: '#FFFFFF',
            out: '#D9FDD3',
            textIn: '#111B21',
            textOut: '#111B21',
            accent: '#008069',
            tailShadow: 'rgba(11, 20, 26, 0.08)',
        },
        dark: {
            in: '#202C33',
            out: '#005C4B',
            textIn: '#E9EDEF',
            textOut: '#E9EDEF',
            accent: '#01B964',
            tailShadow: 'rgba(0, 0, 0, 0.26)',
        },
    },
    {
        id: 'blue',
        label: 'Синий',
        description: 'Голубые исходящие, как в Telegram',
        light: {
            in: '#FFFFFF',
            out: '#D6EAFF',
            textIn: '#111B21',
            textOut: '#0B3D91',
            accent: '#0088CC',
            tailShadow: 'rgba(11, 60, 120, 0.1)',
        },
        dark: {
            in: '#2A3038',
            out: '#1A5FA8',
            textIn: '#E9EDEF',
            textOut: '#FFFFFF',
            accent: '#5EB3F6',
            tailShadow: 'rgba(0, 0, 0, 0.28)',
        },
    },
    {
        id: 'graphite',
        label: 'Графит',
        description: 'Тёмные исходящие, нейтральные входящие',
        light: {
            in: '#FFFFFF',
            out: '#1F2937',
            textIn: '#111B21',
            textOut: '#F9FAFB',
            accent: '#4B5563',
            tailShadow: 'rgba(15, 23, 42, 0.2)',
        },
        dark: {
            in: '#374151',
            out: '#111827',
            textIn: '#F3F4F6',
            textOut: '#F9FAFB',
            accent: '#9CA3AF',
            tailShadow: 'rgba(0, 0, 0, 0.32)',
        },
    },
    {
        id: 'purple',
        label: 'Фиолетовый',
        description: 'Лавандовые исходящие',
        light: {
            in: '#FFFFFF',
            out: '#EDE7F6',
            textIn: '#111B21',
            textOut: '#4A148C',
            accent: '#7C4DFF',
            tailShadow: 'rgba(74, 20, 140, 0.1)',
        },
        dark: {
            in: '#2C2A33',
            out: '#5E35B1',
            textIn: '#E9EDEF',
            textOut: '#FFFFFF',
            accent: '#B388FF',
            tailShadow: 'rgba(0, 0, 0, 0.28)',
        },
    },
    {
        id: 'ocean',
        label: 'Бирюза',
        description: 'Морские оттенки',
        light: {
            in: '#FFFFFF',
            out: '#D5F5F0',
            textIn: '#111B21',
            textOut: '#004D40',
            accent: '#00897B',
            tailShadow: 'rgba(0, 77, 64, 0.1)',
        },
        dark: {
            in: '#263238',
            out: '#00695C',
            textIn: '#E0F2F1',
            textOut: '#FFFFFF',
            accent: '#4DB6AC',
            tailShadow: 'rgba(0, 0, 0, 0.28)',
        },
    },
    {
        id: 'coral',
        label: 'Коралл',
        description: 'Тёплые персиковые исходящие',
        light: {
            in: '#FFFFFF',
            out: '#FFE8E0',
            textIn: '#111B21',
            textOut: '#BF360C',
            accent: '#E64A19',
            tailShadow: 'rgba(191, 54, 12, 0.1)',
        },
        dark: {
            in: '#3E2723',
            out: '#D84315',
            textIn: '#FFCCBC',
            textOut: '#FFFFFF',
            accent: '#FF8A65',
            tailShadow: 'rgba(0, 0, 0, 0.28)',
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

function quoteBg(baseVar: string, theme: Theme, kind: 'in' | 'out'): string {
    const mix = theme === 'light' ? '#000' : '#fff';
    const amount = kind === 'in' ? '5%' : '9%';

    return `color-mix(in srgb, ${mix} ${amount}, ${baseVar})`;
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
    root.style.setProperty('--wa-bubble-quote-bg-in', quoteBg('var(--wa-bubble-in)', theme, 'in'));
    root.style.setProperty('--wa-bubble-quote-bg-out', quoteBg('var(--wa-bubble-out)', theme, 'out'));
}

/** @deprecated */
export const applyBubblePreset = applyMessageStyle;

export function messageStylePreview(preset: MessageStylePreset, theme: Theme): { in: string; out: string } {
    const colors = theme === 'light' ? preset.light : preset.dark;

    return { in: colors.in, out: colors.out };
}

/** @deprecated */
export const bubblePresetPreview = messageStylePreview;
