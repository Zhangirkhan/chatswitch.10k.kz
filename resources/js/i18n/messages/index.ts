import type { AppLocale, MessageCatalog } from '../types';
import { en } from './en';
import { kk } from './kk';
import { ru } from './ru';

const catalogs: Record<AppLocale, MessageCatalog> = {
    ru,
    kk,
    en,
};

export function messagesForLocale(locale: AppLocale): MessageCatalog {
    return catalogs[locale] ?? ru;
}

export { ru, kk, en };
