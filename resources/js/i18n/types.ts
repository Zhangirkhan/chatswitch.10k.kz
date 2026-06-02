export const APP_LOCALES = ['ru', 'kk', 'en'] as const;

export type AppLocale = (typeof APP_LOCALES)[number];

export function isAppLocale(value: string): value is AppLocale {
    return (APP_LOCALES as readonly string[]).includes(value);
}

export interface MessageCatalog {
    nav: {
        chats: string;
        clients: string;
        broadcasts: string;
        aiChat: string;
        analytics: string;
        calendar: string;
        calendarToday: string;
        funnels: string;
        profile: string;
    };
    whatsapp: {
        status: {
            connected: string;
            qrPending: string;
            connecting: string;
            disconnected: string;
        };
    };
    settings: {
        chats: {
            title: string;
        };
        interface: {
            language: string;
            languageHint: string;
        };
        theme: {
            light: string;
            dark: string;
        };
    };
    common: {
        cancel: string;
        save: string;
        close: string;
        done: string;
    };
}

export type MessageKey =
    | 'nav.chats'
    | 'nav.clients'
    | 'nav.broadcasts'
    | 'nav.aiChat'
    | 'nav.analytics'
    | 'nav.calendar'
    | 'nav.calendarToday'
    | 'nav.funnels'
    | 'nav.profile'
    | 'whatsapp.status.connected'
    | 'whatsapp.status.qrPending'
    | 'whatsapp.status.connecting'
    | 'whatsapp.status.disconnected'
    | 'settings.chats.title'
    | 'settings.interface.language'
    | 'settings.interface.languageHint'
    | 'settings.theme.light'
    | 'settings.theme.dark'
    | 'common.cancel'
    | 'common.save'
    | 'common.close'
    | 'common.done';

export interface LocaleOption {
    value: AppLocale;
    label: string;
    flag: string;
}

export const LOCALE_OPTIONS: LocaleOption[] = [
    { value: 'ru', label: 'Русский', flag: '🇷🇺' },
    { value: 'kk', label: 'Қазақша', flag: '🇰🇿' },
    { value: 'en', label: 'English', flag: '🇬🇧' },
];
