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
        connections: {
            title: string;
            subtitle: string;
            addConnection: string;
            creating: string;
            bootstrapping: string;
            serviceUnavailable: string;
            serviceUnavailableAction: string;
            limitsCount: string;
            limitsServer: string;
            limitsExhausted: string;
            emptyTitle: string;
            emptyHint: string;
            createFirst: string;
            multiSessionsTitle: string;
            multiSessionsHint: string;
            colorLabelMulti: string;
            colorLabelSingle: string;
            displayNamePlaceholder: string;
            pickRingColor: string;
            presetColors: string;
            saving: string;
            confirmLogoutTitle: string;
            confirmRemoveTitle: string;
            confirmLogoutDescription: string;
            confirmRemoveDescription: string;
            confirmLogout: string;
            confirmRemove: string;
            errorLogout: string;
            errorRemove: string;
            errorGeneric: string;
            errorCreate: string;
            errorInitialize: string;
            errorQr: string;
            errorStatus: string;
            errorVerify: string;
            errorDisplayNameRequired: string;
            errorSaveName: string;
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
    | 'settings.connections.title'
    | 'settings.connections.subtitle'
    | 'settings.connections.addConnection'
    | 'settings.connections.creating'
    | 'settings.connections.bootstrapping'
    | 'settings.connections.serviceUnavailable'
    | 'settings.connections.serviceUnavailableAction'
    | 'settings.connections.limitsCount'
    | 'settings.connections.limitsServer'
    | 'settings.connections.limitsExhausted'
    | 'settings.connections.emptyTitle'
    | 'settings.connections.emptyHint'
    | 'settings.connections.createFirst'
    | 'settings.connections.multiSessionsTitle'
    | 'settings.connections.multiSessionsHint'
    | 'settings.connections.colorLabelMulti'
    | 'settings.connections.colorLabelSingle'
    | 'settings.connections.displayNamePlaceholder'
    | 'settings.connections.pickRingColor'
    | 'settings.connections.presetColors'
    | 'settings.connections.saving'
    | 'settings.connections.confirmLogoutTitle'
    | 'settings.connections.confirmRemoveTitle'
    | 'settings.connections.confirmLogoutDescription'
    | 'settings.connections.confirmRemoveDescription'
    | 'settings.connections.confirmLogout'
    | 'settings.connections.confirmRemove'
    | 'settings.connections.errorLogout'
    | 'settings.connections.errorRemove'
    | 'settings.connections.errorGeneric'
    | 'settings.connections.errorCreate'
    | 'settings.connections.errorInitialize'
    | 'settings.connections.errorQr'
    | 'settings.connections.errorStatus'
    | 'settings.connections.errorVerify'
    | 'settings.connections.errorDisplayNameRequired'
    | 'settings.connections.errorSaveName'
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
