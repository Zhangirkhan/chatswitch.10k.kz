import { describe, expect, it } from 'vitest';
import { formatPhone, isPlausibleInboundSenderPhone, normalizePhone } from './phone';

describe('phone utils', () => {
    describe('normalizePhone', () => {
        it('strips formatting and whatsapp suffix', () => {
            expect(normalizePhone('+7 (747) 664-41-08')).toBe('77476644108');
            expect(normalizePhone('77476644108@c.us')).toBe('77476644108');
        });

        it('converts leading 8 and 10-digit local numbers to country code 7', () => {
            expect(normalizePhone('8 7476644108')).toBe('77476644108');
            expect(normalizePhone('7476644108')).toBe('77476644108');
        });

        it('returns empty string for blank input', () => {
            expect(normalizePhone('')).toBe('');
            expect(normalizePhone(null)).toBe('');
        });
    });

    describe('formatPhone', () => {
        it('matches normalizePhone output', () => {
            expect(formatPhone('+7 700 111 22 33')).toBe('77001112233');
        });
    });

    describe('isPlausibleInboundSenderPhone', () => {
        it('accepts plausible E.164-like numbers', () => {
            expect(isPlausibleInboundSenderPhone('77001112233')).toBe(true);
            expect(isPlausibleInboundSenderPhone('+1 202 555 0101')).toBe(true);
        });

        it('rejects lid-style internal ids', () => {
            expect(isPlausibleInboundSenderPhone('123456789')).toBe(false);
            expect(isPlausibleInboundSenderPhone('123')).toBe(false);
        });
    });
});
