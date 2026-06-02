import { describe, expect, it } from 'vitest';
import { initialsFromName } from './initials';

describe('initialsFromName', () => {
    it('returns fallback for empty names', () => {
        expect(initialsFromName('')).toBe('?');
        expect(initialsFromName(null, 'U')).toBe('U');
    });

    it('uses first two letters for a single word', () => {
        expect(initialsFromName('Айгуль')).toBe('АЙ');
    });

    it('uses first letters of first two words', () => {
        expect(initialsFromName('Иван Петров')).toBe('ИП');
        expect(initialsFromName('demo@accel.kz')).toBe('DA');
    });
});
