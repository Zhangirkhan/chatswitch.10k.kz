import type { MessageCatalog } from '../types';

export const en: MessageCatalog = {
    nav: {
        chats: 'Chats',
        clients: 'Clients',
        broadcasts: 'Broadcasts',
        aiChat: 'AI chat',
        analytics: 'Dialog analytics',
        calendar: 'Calendar',
        calendarToday: 'Events today: {count}',
        funnels: 'Funnels',
        profile: 'Profile and settings',
    },
    whatsapp: {
        status: {
            connected: 'Connected',
            qrPending: 'Waiting for QR',
            connecting: 'Connecting…',
            disconnected: 'Disconnected',
        },
    },
    settings: {
        chats: {
            title: 'Chats',
        },
        interface: {
            language: 'Interface language',
            languageHint: 'Changes menu labels and shared UI text on this device.',
        },
        theme: {
            light: 'Light',
            dark: 'Dark',
        },
    },
    common: {
        cancel: 'Cancel',
        save: 'Save',
        close: 'Close',
        done: 'Done',
    },
};
