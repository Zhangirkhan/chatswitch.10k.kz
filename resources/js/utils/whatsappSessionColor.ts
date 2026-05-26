/** Палитра обводок по умолчанию (зелёный, жёлтый, синий и т.д.). */
export const WHATSAPP_SESSION_RING_PALETTE = [
    '#01b964',
    '#f5c518',
    '#3b82f6',
    '#f97316',
    '#a855f7',
    '#ec4899',
    '#14b8a6',
    '#ef4444',
] as const;

export function normalizeHexColor(raw: string | null | undefined): string | null {
    const c = (raw ?? '').trim();
    if (!c) {
        return null;
    }
    const m = /^#?([0-9a-f]{6})$/i.exec(c);

    return m ? `#${m[1]!.toLowerCase()}` : null;
}

export function whatsappSessionRingColor(
    session: { id: number; display_color?: string | null } | null | undefined,
): string | null {
    if (!session) {
        return null;
    }

    const fromDb = normalizeHexColor(session.display_color);
    if (fromDb) {
        return fromDb;
    }

    const idx = Math.abs(session.id) % WHATSAPP_SESSION_RING_PALETTE.length;

    return WHATSAPP_SESSION_RING_PALETTE[idx]!;
}
