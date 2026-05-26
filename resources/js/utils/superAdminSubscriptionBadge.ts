/** Badge classes for company subscription status in Super Admin. */
export function subscriptionStatusBadgeClass(status: string): string {
    const base = 'ui-badge';
    const map: Record<string, string> = {
        trial: `${base} ui-badge--manager`,
        active: `${base} ui-badge--success`,
        past_due: `${base} ui-badge--warn`,
        suspended: `${base} ui-badge--admin`,
        canceled: `${base} ui-badge--neutral`,
    };

    return map[status] ?? `${base} ui-badge--neutral`;
}
