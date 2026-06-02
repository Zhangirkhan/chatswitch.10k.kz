import type { MessageKey } from '@/i18n/types';

/** Badge classes for invoice status in Super Admin. */
export function invoiceStatusBadgeClass(status: string): string {
    const base = 'ui-badge';
    const map: Record<string, string> = {
        draft: `${base} ui-badge--neutral`,
        issued: `${base} ui-badge--warn`,
        paid: `${base} ui-badge--success`,
        void: `${base} ui-badge--admin`,
    };

    return map[status] ?? `${base} ui-badge--neutral`;
}

const INVOICE_STATUS_KEYS: Record<string, MessageKey> = {
    draft: 'superAdmin.invoice.status.draft',
    issued: 'superAdmin.invoice.status.issued',
    paid: 'superAdmin.invoice.status.paid',
    void: 'superAdmin.invoice.status.void',
};

const PAYMENT_METHOD_KEYS: Record<string, MessageKey> = {
    bank_transfer: 'superAdmin.invoice.paymentMethod.bank_transfer',
    kaspi: 'superAdmin.invoice.paymentMethod.kaspi',
    cash: 'superAdmin.invoice.paymentMethod.cash',
    other: 'superAdmin.invoice.paymentMethod.other',
};

export function invoiceStatusLabel(
    status: string,
    t: (key: MessageKey | string) => string,
): string {
    const key = INVOICE_STATUS_KEYS[status];
    return key ? t(key) : status;
}

export function paymentMethodLabel(
    method: string,
    t: (key: MessageKey | string) => string,
): string {
    const key = PAYMENT_METHOD_KEYS[method];
    return key ? t(key) : method;
}

export function invoiceStatusLabelMap(
    t: (key: MessageKey | string) => string,
): Record<string, string> {
    return Object.fromEntries(
        Object.entries(INVOICE_STATUS_KEYS).map(([status, key]) => [status, t(key)]),
    );
}

export function paymentMethodLabelMap(
    t: (key: MessageKey | string) => string,
): Record<string, string> {
    return Object.fromEntries(
        Object.entries(PAYMENT_METHOD_KEYS).map(([method, key]) => [method, t(key)]),
    );
}
