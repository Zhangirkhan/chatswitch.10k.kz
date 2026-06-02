import type { MessageCatalog } from '../types';

export const ru: MessageCatalog = {
    nav: {
        chats: 'Чаты',
        clients: 'Клиенты',
        broadcasts: 'Рассылки',
        aiChat: 'ИИ чат',
        analytics: 'Аналитика диалогов',
        calendar: 'Календарь',
        calendarToday: 'Записей сегодня: {count}',
        funnels: 'Воронки',
        profile: 'Профиль и настройки',
    },
    whatsapp: {
        status: {
            connected: 'Подключён',
            qrPending: 'Ожидание QR',
            connecting: 'Подключение…',
            disconnected: 'Отключён',
        },
    },
    settings: {
        chats: {
            title: 'Чаты',
        },
        interface: {
            language: 'Язык интерфейса',
            languageHint: 'Меняет подписи меню и общие элементы приложения на этом устройстве.',
        },
        theme: {
            light: 'Светлая',
            dark: 'Тёмная',
        },
    },
    common: {
        cancel: 'Отмена',
        save: 'Сохранить',
        close: 'Закрыть',
        done: 'Готово',
    },
};
