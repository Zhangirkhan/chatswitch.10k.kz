/** Маска телефона РК: +7 (747) 664-41-08 */
export function maskKzPhoneInput(raw: string): string {
    let digits = raw.replace(/\D/g, '');

    if (digits.startsWith('8')) {
        digits = '7' + digits.slice(1);
    }
    if (digits.length > 0 && !digits.startsWith('7')) {
        digits = `7${digits}`;
    }

    digits = digits.slice(0, 11);

    if (digits.length === 0) {
        return '';
    }

    const rest = digits.slice(1);
    let out = '+7';

    if (rest.length > 0) {
        out += ` (${rest.slice(0, 3)}`;
    }
    if (rest.length >= 3) {
        out += `) ${rest.slice(3, 6)}`;
    }
    if (rest.length >= 6) {
        out += `-${rest.slice(6, 8)}`;
    }
    if (rest.length >= 8) {
        out += `-${rest.slice(8, 10)}`;
    }

    return out;
}

/** БИН (Казахстан): 12 цифр, отображение группами по 4. */
export function maskBinInput(raw: string): string {
    const digits = raw.replace(/\D/g, '').slice(0, 12);

    if (digits.length === 0) {
        return '';
    }

    return digits.replace(/(\d{4})(?=\d)/g, '$1 ').trim();
}

/** Только цифры БИН для отправки на сервер. */
export function binDigitsOnly(masked: string): string {
    return masked.replace(/\D/g, '').slice(0, 12);
}

/** Поддомен тенанта: латиница, цифры, дефис. */
export function sanitizeTenantSlugInput(raw: string): string {
    return raw
        .toLowerCase()
        .replace(/[^a-z0-9-]/g, '')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '')
        .slice(0, 32);
}
