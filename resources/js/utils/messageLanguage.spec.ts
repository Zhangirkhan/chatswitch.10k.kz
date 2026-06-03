import { describe, expect, it } from 'vitest';
import { messageMatchesTargetLanguage, messageNeedsTranslation, resolveOutgoingTargetLanguage } from './messageLanguage';

describe('messageLanguage', () => {
    it('detects Russian text for ru target', () => {
        expect(messageMatchesTargetLanguage('Привет, как дела?', 'ru')).toBe(true);
        expect(messageNeedsTranslation('Привет, как дела?', 'ru')).toBe(false);
    });

    it('suggests translation for English when target is ru', () => {
        expect(messageNeedsTranslation('Hello, please send the invoice', 'ru')).toBe(true);
    });

    it('detects Kazakh-specific letters', () => {
        expect(messageMatchesTargetLanguage('Сәлем, қалайсыз?', 'kk')).toBe(true);
        expect(messageNeedsTranslation('Сәлем, қалайсыз?', 'kk')).toBe(false);
    });

    it('suggests translation for Russian when target is kk', () => {
        expect(messageNeedsTranslation('Добрый день, отправьте документ', 'kk')).toBe(true);
    });

    it('detects English for en target', () => {
        expect(messageMatchesTargetLanguage('Thanks for your order', 'en')).toBe(true);
        expect(messageNeedsTranslation('Thanks for your order', 'en')).toBe(false);
    });

    it('suggests translation for Russian when target is en', () => {
        expect(messageNeedsTranslation('Спасибо за заказ', 'en')).toBe(true);
    });

    it('ignores very short samples', () => {
        expect(messageNeedsTranslation('Hi', 'ru')).toBe(false);
        expect(messageNeedsTranslation('OK', 'en')).toBe(false);
    });

    it('strips WhatsApp markup before detection', () => {
        expect(messageNeedsTranslation('*Manager*\nHello team', 'ru')).toBe(true);
    });

    it('resolves outgoing target from client language', () => {
        expect(resolveOutgoingTargetLanguage('Здравствуйте, цена 5000', 'kk')).toBe('kk');
        expect(resolveOutgoingTargetLanguage('Сәлеметсіз бе', 'kk')).toBeNull();
    });

    it('falls back to kazakh when client language unknown and draft is russian', () => {
        expect(resolveOutgoingTargetLanguage('Добрый день, цена 5000', null)).toBe('kk');
    });
});
