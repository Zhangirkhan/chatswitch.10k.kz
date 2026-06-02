import { describe, expect, it } from 'vitest';
import { messagesForLocale } from './messages';
import { translate } from './translate';

describe('translate', () => {
    it('resolves nested keys from catalog', () => {
        const text = translate(messagesForLocale('ru'), 'nav.chats');

        expect(text).toBe('Чаты');
    });

    it('interpolates placeholders', () => {
        const text = translate(messagesForLocale('en'), 'nav.calendarToday', { count: 3 });

        expect(text).toBe('Events today: 3');
    });

    it('falls back to key when missing', () => {
        expect(translate(messagesForLocale('ru'), 'missing.key')).toBe('missing.key');
    });

    it('returns localized nav labels for kk', () => {
        expect(translate(messagesForLocale('kk'), 'nav.clients')).toBe('Клиенттер');
    });
});
