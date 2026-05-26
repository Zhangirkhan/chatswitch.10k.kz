export const auditActionLabels: Record<string, string> = {
    'invoice.issued': 'Выставлен счёт',
    'invoice.status_changed': 'Изменён статус счёта',
    'invoice.payment_recorded': 'Записана оплата',
    'invoice.email_sent': 'Счёт отправлен по email',
    'user.created': 'Создан пользователь',
    'user.updated': 'Обновлён пользователь',
    'user.password_reset': 'Сброшен пароль',
    'company.created': 'Создана компания',
    'company.deleted': 'Удалена компания',
    'company.modules_updated': 'Обновлены модули',
    'company.updated': 'Обновлена компания',
    'company.tenant_toggled': 'Переключён тенант',
    'subscription.plan_changed': 'Сменён тариф',
    'subscription.activated': 'Подписка активирована',
    'subscription.canceled': 'Подписка отменена',
    'subscription.record_updated': 'Запись подписки изменена',
    'signup_request.approved': 'Заявка одобрена',
    'signup_request.rejected': 'Заявка отклонена',
    'tenant.welcome_email_sent': 'Отправлено приветственное письмо',
    'tenant.rejection_email_sent': 'Отправлено уведомление об отклонении',
    'impersonation.end': 'Выход из тенанта',
};

export function auditActionLabel(action: string): string {
    return auditActionLabels[action] ?? action;
}

export function auditMetaSummary(meta: Record<string, unknown> | null): string {
    if (!meta || Object.keys(meta).length === 0) {
        return '';
    }

    const parts: string[] = [];

    if (meta.company_name) {
        parts.push(String(meta.company_name));
    }
    if (meta.slug) {
        parts.push(String(meta.slug));
    }
    if (meta.signup_request_id) {
        parts.push(`заявка #${meta.signup_request_id}`);
    }
    if (meta.contact_email) {
        parts.push(String(meta.contact_email));
    }
    if (meta.number) {
        parts.push(String(meta.number));
    }
    if (meta.recipient) {
        parts.push(String(meta.recipient));
    }
    if (meta.email) {
        parts.push(String(meta.email));
    }
    if (meta.target_user_email) {
        parts.push(String(meta.target_user_email));
    }
    if (meta.plan_name) {
        parts.push(String(meta.plan_name));
    }
    if (meta.months) {
        parts.push(`${meta.months} мес.`);
    }
    if (meta.restart_trial) {
        parts.push('новый триал');
    }
    if (meta.from && meta.to) {
        parts.push(`${meta.from} → ${meta.to}`);
    }
    if (meta.amount_cents) {
        parts.push(`${Math.round(Number(meta.amount_cents) / 100)} ₸`);
    }
    if (meta.subscription_activated) {
        parts.push('подписка активирована');
    }
    if (meta.is_active === true) {
        parts.push('включён');
    }
    if (meta.is_active === false) {
        parts.push('отключён');
    }
    if (Array.isArray(meta.changes) && meta.changes.length > 0) {
        parts.push(meta.changes.map(String).join(', '));
    }

    return parts.join(' · ');
}
