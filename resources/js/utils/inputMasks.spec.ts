import { describe, expect, it } from 'vitest';
import { binDigitsOnly, maskBinInput, maskKzPhoneInput, sanitizeTenantSlugInput } from './inputMasks';

describe('inputMasks', () => {
    describe('maskKzPhoneInput', () => {
        it('formats full KZ numbers', () => {
            expect(maskKzPhoneInput('87476644108')).toBe('+7 (747) 664-41-08');
        });

        it('normalizes leading 8 to country code 7', () => {
            expect(maskKzPhoneInput('8')).toBe('+7');
        });
    });

    describe('maskBinInput', () => {
        it('groups digits in fours and caps length at 12', () => {
            expect(maskBinInput('1234567890123456')).toBe('1234 5678 9012');
        });
    });

    describe('binDigitsOnly', () => {
        it('extracts digits from masked BIN', () => {
            expect(binDigitsOnly('1234 5678 9012')).toBe('123456789012');
        });
    });

    describe('sanitizeTenantSlugInput', () => {
        it('lowercases and strips invalid characters', () => {
            expect(sanitizeTenantSlugInput(' My_Company! ')).toBe('mycompany');
            expect(sanitizeTenantSlugInput('demo--test')).toBe('demo-test');
        });
    });
});
