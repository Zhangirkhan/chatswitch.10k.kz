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

export const invoiceStatusLabels: Record<string, string> = {
    draft: 'Черновик',
    issued: 'Выставлен',
    paid: 'Оплачен',
    void: 'Аннулирован',
};

export const paymentMethodLabels: Record<string, string> = {
    bank_transfer: 'Банковский перевод',
    kaspi: 'Kaspi',
    cash: 'Наличные',
    other: 'Другое',
};
