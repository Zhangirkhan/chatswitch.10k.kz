import type { MessageKey } from '@/i18n/types';

const AUDIT_ACTION_KEYS: Record<string, MessageKey> = {
    'invoice.issued': 'superAdmin.audit.actions.invoiceIssued',
    'invoice.status_changed': 'superAdmin.audit.actions.invoiceStatusChanged',
    'invoice.payment_recorded': 'superAdmin.audit.actions.invoicePaymentRecorded',
    'invoice.email_sent': 'superAdmin.audit.actions.invoiceEmailSent',
    'user.created': 'superAdmin.audit.actions.userCreated',
    'user.updated': 'superAdmin.audit.actions.userUpdated',
    'user.password_reset': 'superAdmin.audit.actions.userPasswordReset',
    'company.created': 'superAdmin.audit.actions.companyCreated',
    'company.deleted': 'superAdmin.audit.actions.companyDeleted',
    'company.modules_updated': 'superAdmin.audit.actions.companyModulesUpdated',
    'company.updated': 'superAdmin.audit.actions.companyUpdated',
    'company.tenant_toggled': 'superAdmin.audit.actions.companyTenantToggled',
    'subscription.plan_changed': 'superAdmin.audit.actions.subscriptionPlanChanged',
    'subscription.activated': 'superAdmin.audit.actions.subscriptionActivated',
    'subscription.canceled': 'superAdmin.audit.actions.subscriptionCanceled',
    'subscription.record_updated': 'superAdmin.audit.actions.subscriptionRecordUpdated',
    'signup_request.approved': 'superAdmin.audit.actions.signupRequestApproved',
    'signup_request.rejected': 'superAdmin.audit.actions.signupRequestRejected',
    'tenant.welcome_email_sent': 'superAdmin.audit.actions.tenantWelcomeEmailSent',
    'tenant.rejection_email_sent': 'superAdmin.audit.actions.tenantRejectionEmailSent',
    'impersonation.end': 'superAdmin.audit.actions.impersonationEnd',
    'platform_banner.created': 'superAdmin.audit.actions.platformBannerCreated',
    'platform_banner.updated': 'superAdmin.audit.actions.platformBannerUpdated',
    'platform_banner.deleted': 'superAdmin.audit.actions.platformBannerDeleted',
};

export function auditActionLabel(
    action: string,
    t: (key: MessageKey | string, params?: Record<string, string | number>) => string,
): string {
    const key = AUDIT_ACTION_KEYS[action];
    return key ? t(key) : action;
}

export function auditMetaSummary(
    meta: Record<string, unknown> | null,
    t: (key: MessageKey | string, params?: Record<string, string | number>) => string,
): string {
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
        parts.push(t('superAdmin.audit.meta.signupRequest', { id: String(meta.signup_request_id) }));
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
        parts.push(t('superAdmin.audit.meta.months', { count: String(meta.months) }));
    }
    if (meta.restart_trial) {
        parts.push(t('superAdmin.audit.meta.newTrial'));
    }
    if (meta.from && meta.to) {
        parts.push(`${meta.from} → ${meta.to}`);
    }
    if (meta.amount_cents) {
        parts.push(`${Math.round(Number(meta.amount_cents) / 100)} ₸`);
    }
    if (meta.subscription_activated) {
        parts.push(t('superAdmin.audit.meta.subscriptionActivated'));
    }
    if (meta.is_active === true) {
        parts.push(t('superAdmin.audit.meta.enabled'));
    }
    if (meta.is_active === false) {
        parts.push(t('superAdmin.audit.meta.disabled'));
    }
    if (Array.isArray(meta.changes) && meta.changes.length > 0) {
        parts.push(meta.changes.map(String).join(', '));
    }

    return parts.join(' · ');
}
