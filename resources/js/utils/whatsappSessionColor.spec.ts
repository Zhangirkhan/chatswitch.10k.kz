import { describe, expect, it } from 'vitest';
import {
    WHATSAPP_SESSION_RING_PALETTE,
    normalizeHexColor,
    whatsappSessionRingColor,
} from './whatsappSessionColor';

describe('whatsappSessionColor', () => {
    describe('normalizeHexColor', () => {
        it('accepts 6-digit hex with or without hash', () => {
            expect(normalizeHexColor('#01B964')).toBe('#01b964');
            expect(normalizeHexColor('3b82f6')).toBe('#3b82f6');
        });

        it('rejects invalid values', () => {
            expect(normalizeHexColor('')).toBeNull();
            expect(normalizeHexColor('red')).toBeNull();
            expect(normalizeHexColor('#abc')).toBeNull();
        });
    });

    describe('whatsappSessionRingColor', () => {
        it('prefers display_color from session', () => {
            expect(whatsappSessionRingColor({ id: 1, display_color: 'EC4899' })).toBe('#ec4899');
        });

        it('falls back to palette by session id', () => {
            expect(whatsappSessionRingColor({ id: 2 })).toBe(WHATSAPP_SESSION_RING_PALETTE[2]);
            expect(whatsappSessionRingColor({ id: 10 })).toBe(WHATSAPP_SESSION_RING_PALETTE[2]);
        });

        it('returns null for missing session', () => {
            expect(whatsappSessionRingColor(null)).toBeNull();
        });
    });
});
