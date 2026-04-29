/**
 * Приводит номер к единому формату (только цифры, с кодом страны).
 * Пример: "+7 (747) 664-41-08" → "77476644108"
 *         "8 7476644108"       → "77476644108"
 *         "77476644108@c.us"   → "77476644108"
 */
export function normalizePhone(input: string | null | undefined): string {
    if (!input) return '';
    const raw = String(input).split('@')[0];
    const digits = raw.replace(/\D+/g, '');
    if (!digits) return '';

    if (digits.length === 11 && digits.startsWith('8')) {
        return '7' + digits.slice(1);
    }
    if (digits.length === 10) {
        return '7' + digits;
    }
    return digits;
}

/**
 * Алиас для отображения — возвращает тот же унифицированный формат.
 */
export function formatPhone(input: string | null | undefined): string {
    return normalizePhone(input);
}

/**
 * Участники группы с @lid в WhatsApp часто приходят с «номером» — десятичным внутренним id,
 * а не E.164. Такие строки нельзя показывать как телефон и использовать для ЛС.
 */
export function isPlausibleInboundSenderPhone(input: string | null | undefined): boolean {
    const x = String(input || '').replace(/\D/g, '');
    if (x.length < 10 || x.length > 15) {
        return false;
    }
    if (x.startsWith('1')) {
        return x.length === 11;
    }
    if (x.startsWith('7')) {
        return x.length >= 10 && x.length <= 12;
    }
    return true;
}
