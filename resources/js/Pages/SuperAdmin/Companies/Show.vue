<script setup lang="ts">
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import UiPillNav from '@/Components/Ui/UiPillNav.vue';
import CompanyShowAuditPanel from '@/Pages/SuperAdmin/Companies/Partials/CompanyShowAuditPanel.vue';
import CompanyShowHeader from '@/Pages/SuperAdmin/Companies/Partials/CompanyShowHeader.vue';
import CompanyShowHealthPanel from '@/Pages/SuperAdmin/Companies/Partials/CompanyShowHealthPanel.vue';
import CompanyShowInvoicesPanel from '@/Pages/SuperAdmin/Companies/Partials/CompanyShowInvoicesPanel.vue';
import CompanyShowModulesPanel from '@/Pages/SuperAdmin/Companies/Partials/CompanyShowModulesPanel.vue';
import CompanyShowUsersPanel from '@/Pages/SuperAdmin/Companies/Partials/CompanyShowUsersPanel.vue';
import CompanyShowWhatsappPanel from '@/Pages/SuperAdmin/Companies/Partials/CompanyShowWhatsappPanel.vue';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { subscriptionStatusBadgeClass } from '@/utils/superAdminSubscriptionBadge';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/composables/useI18n';
import { computed, onMounted, ref, watch } from 'vue';

const { t } = useI18n();

type TabId = 'info' | 'modules' | 'subscription' | 'history' | 'invoices' | 'users' | 'whatsapp' | 'audit';

interface PaymentRow {
    id: number;
    amount_cents: number;
    method: string;
    external_ref: string | null;
    paid_at: string;
}

interface InvoiceRow {
    id: number;
    number: string;
    amount_cents: number;
    currency: string;
    status: string;
    issued_at: string | null;
    paid_at: string | null;
    notes: string | null;
    payments: PaymentRow[];
}

interface PlanOption {
    id: number;
    name: string;
    code: string;
    price_cents: number;
    trial_days: number;
}

interface SubscriptionRow {
    id: number;
    status: string;
    event: string | null;
    started_at: string | null;
    ends_at: string | null;
    trial_ends_at: string | null;
    ended_at: string | null;
    canceled_at: string | null;
    plan?: { name: string; code: string; price_cents: number } | null;
}

interface SuperAdminCompanyUser {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    phones: string[];
    is_active: boolean;
    is_owner: boolean;
    department_id: number | null;
    department: { id: number; name: string } | null;
    departments: Array<{ id: number; name: string }>;
    roles: Array<{ name: string }>;
    whatsapp_sessions: Array<{ id: number; session_name: string; display_name: string | null; status: string }>;
    created_at: string | null;
}

const props = defineProps<{
    company: {
        id: number;
        name: string;
        slug: string;
        email: string | null;
        website: string | null;
        description: string | null;
        is_active: boolean;
        subscription_status: string;
        plan_id: number | null;
        phone: string | null;
        trial_ends_at: string | null;
        current_period_ends_at: string | null;
        created_at: string;
        updated_at: string;
        plan?: { name: string; code: string; price_cents: number; trial_days: number } | null;
        owner?: { id: number; name: string; email: string; created_at: string } | null;
        subscriptions: SubscriptionRow[];
        users_count?: number;
        subscriptions_count?: number;
        invoices_count?: number;
        whatsapp_sessions_count?: number;
    };
    invoices: InvoiceRow[];
    billingSummary: {
        mrr_kzt: number;
        next_payment_at: string | null;
        overdue_invoices: number;
        trial_days_left: number | null;
        revenue_sparkline: Array<{ label: string; amount_kzt: number }>;
    };
    whatsappSessions: Array<{
        id: number;
        session_name: string;
        phone_number: string | null;
        display_name: string | null;
        status: string;
        desired_state: string;
        is_active: boolean;
        connected_at: string | null;
    }>;
    whatsappServiceReachable: boolean;
    whatsappMaxSessions: number;
    auditLogs: Array<{
        id: number;
        action: string;
        meta: Record<string, unknown> | null;
        created_at: string;
        actor?: { name: string; email: string } | null;
    }>;
    tenantUrl: string;
    canImpersonate: boolean;
    impersonateBlockedReason?: string | null;
    canDelete?: boolean;
    canPopulateSandbox?: boolean;
    canClearSandboxData?: boolean;
    plans: PlanOption[];
    billing: { trial_days: number; standard_price_label: string };
    companyUsers: SuperAdminCompanyUser[];
    companyDepartments: Array<{ id: number; name: string; parent_id: number | null; is_active: boolean }>;
    companyWhatsappSessions: Array<{ id: number; session_name: string; display_name: string | null; status: string }>;
    companyModules: Array<{ key: string; label: string; description: string; enabled: boolean }>;
    tenantHealth: {
        ok: boolean;
        slug: string;
        groups: Record<string, { ok: boolean; checks: Array<{ key: string; ok: boolean; severity: string; message: string }> }>;
    };
    provisioningVerify?: {
        status: 'pass' | 'fail';
        failures?: string[];
        checked_at?: string;
    } | null;
}>();

const defaultPlanPriceCents = computed(() => props.company.plan?.price_cents ?? 4_000_000);

const administratorUsers = computed(() =>
    props.companyUsers.filter(
        (u) => u.is_active && u.roles.some((r) => r.name === 'administrator'),
    ),
);

const ownerAssigning = ref(false);

function onOwnerSelectChange(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    if (value === '') {
        return;
    }

    assignOwner(Number(value));
}

function assignOwner(userId: number): void {
    if (ownerAssigning.value || props.company.owner?.id === userId) {
        return;
    }

    ownerAssigning.value = true;
    router.patch(
        `/companies/${props.company.id}/owner`,
        { user_id: userId },
        {
            preserveScroll: true,
            onFinish: () => {
                ownerAssigning.value = false;
            },
        },
    );
}

const page = usePage();
const rootDomain = computed(() => (page.props.rootDomain as string | undefined) ?? 'accel.kz');
const activeTab = ref<TabId>('info');

const tabs = computed((): { id: TabId; label: string; badge?: number }[] => [
    { id: 'info', label: t('superAdmin.companies.show.tabInfo') },
    { id: 'modules', label: t('superAdmin.companies.show.tabModules') },
    { id: 'subscription', label: t('superAdmin.companies.show.tabSubscription') },
    { id: 'history', label: t('superAdmin.companies.show.tabHistory') },
    { id: 'invoices', label: t('superAdmin.companies.show.tabInvoices'), badge: props.billingSummary.overdue_invoices || undefined },
    { id: 'users', label: t('superAdmin.companies.show.tabUsers'), badge: props.company.users_count ?? props.companyUsers.length },
    { id: 'whatsapp', label: 'WhatsApp', badge: props.whatsappSessions.length || undefined },
    { id: 'audit', label: t('superAdmin.companies.show.tabAudit') },
]);

function setTab(tab: TabId): void {
    activeTab.value = tab;
    router.get(
        `/companies/${props.company.id}`,
        { tab },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function initTabFromUrl(): void {
    const tab = new URLSearchParams(window.location.search).get('tab');
    if (
        tab === 'info'
        || tab === 'modules'
        || tab === 'subscription'
        || tab === 'history'
        || tab === 'invoices'
        || tab === 'users'
        || tab === 'whatsapp'
        || tab === 'audit'
    ) {
        activeTab.value = tab;
    }
}

onMounted(initTabFromUrl);

watch(
    () => page.url,
    () => initTabFromUrl(),
);

function quickToggle(): void {
    showToggleConfirm.value = true;
}

function confirmQuickToggle(): void {
    router.patch(`/companies/${props.company.id}/toggle-active`, {}, {
        preserveScroll: true,
        onFinish: () => {
            showToggleConfirm.value = false;
        },
    });
}

const toggleConfirmDescription = computed(() => {
    const domain = `${props.company.slug}.${rootDomain.value}`;
    return props.company.is_active
        ? t('superAdmin.companies.show.toggleDisableDescription', { domain })
        : t('superAdmin.companies.show.toggleEnableDescription', { domain });
});

function requestActivatePaid(): void {
    showActivateConfirm.value = true;
}

function confirmActivatePaid(): void {
    router.post(
        `/companies/${props.company.id}/subscriptions/activate`,
        { plan_id: props.company.plan_id, months: activateMonths.value },
        {
            preserveScroll: true,
            onFinish: () => {
                showActivateConfirm.value = false;
            },
        },
    );
}

function requestCancelSubscription(): void {
    showCancelConfirm.value = true;
}

function confirmCancelSubscription(): void {
    router.post(`/companies/${props.company.id}/subscriptions/cancel`, {}, {
        preserveScroll: true,
        onFinish: () => {
            showCancelConfirm.value = false;
        },
    });
}

function requestDelete(): void {
    showDeleteConfirm.value = true;
}

function confirmDelete(): void {
    deleting.value = true;
    router.delete(`/companies/${props.company.id}`, {
        onFinish: () => {
            deleting.value = false;
            showDeleteConfirm.value = false;
        },
    });
}

function formatPrice(cents: number): string {
    return new Intl.NumberFormat('ru-RU').format(Math.round(cents / 100)) + ' ₸';
}

function formatDate(iso: string | null): string {
    if (!iso) return t('superAdmin.common.emDash');
    return new Date(iso).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}

const statusLabels = computed(() => ({
    trial: t('superAdmin.subscriptionStatus.trial'),
    active: t('superAdmin.subscriptionStatus.active'),
    past_due: t('superAdmin.subscriptionStatus.pastDuePay'),
    suspended: t('superAdmin.subscriptionStatus.suspended'),
    canceled: t('superAdmin.subscriptionStatus.canceled'),
}));

const eventLabels = computed(() => ({
    trial_started: t('superAdmin.subscriptionEvent.trialStarted'),
    activated: t('superAdmin.subscriptionEvent.activated'),
    canceled: t('superAdmin.subscriptionEvent.canceled'),
    trial_expired: t('superAdmin.subscriptionEvent.trialExpired'),
}));

const trialInfo = computed(() => {
    if (props.company.subscription_status !== 'trial' || !props.company.trial_ends_at) {
        return null;
    }
    const end = new Date(props.company.trial_ends_at);
    const days = Math.max(0, Math.ceil((end.getTime() - Date.now()) / 86400000));
    return t('superAdmin.companies.show.trialRemaining', {
        days,
        date: formatDate(props.company.trial_ends_at),
    });
});

const form = useForm({
    name: props.company.name,
    phone: props.company.phone ?? '',
    is_active: props.company.is_active,
    subscription_status: props.company.subscription_status,
    plan_id: props.company.plan_id,
    trial_ends_at: props.company.trial_ends_at ? props.company.trial_ends_at.slice(0, 10) : '',
});

const activateMonths = ref(1);
const showToggleConfirm = ref(false);
const showActivateConfirm = ref(false);
const showCancelConfirm = ref(false);
const showDeleteConfirm = ref(false);
const deleting = ref(false);

const planForm = useForm({
    plan_id: props.company.plan_id ?? props.plans[0]?.id ?? null,
    restart_trial: false,
});

function saveCompany(): void {
    form.put(`/companies/${props.company.id}`, { preserveScroll: true });
}

function assignPlan(): void {
    planForm.post(`/companies/${props.company.id}/subscriptions`, { preserveScroll: true });
}
</script>

<template>
    <SuperAdminLayout>
        <Head :title="company.name" />

        <div class="mb-4">
            <Link href="/companies" class="text-sm text-ui-text-secondary hover:text-ui-accent">{{ t('superAdmin.companies.show.back') }}</Link>
        </div>

        <CompanyShowHeader
            :company="company"
            :tenant-url="tenantUrl"
            :can-impersonate="canImpersonate"
            :impersonate-blocked-reason="impersonateBlockedReason"
            :can-populate-sandbox="canPopulateSandbox"
            :can-clear-sandbox-data="canClearSandboxData"
            :can-delete="canDelete"
            :billing-summary="billingSummary"
            :trial-info="trialInfo"
            :status-labels="statusLabels"
            :root-domain="rootDomain"
            @toggle="quickToggle"
            @delete="requestDelete"
        />

        <UiPillNav class="mb-6 max-w-5xl flex-wrap">
            <button
                v-for="t in tabs"
                :key="t.id"
                type="button"
                class="ui-pill-nav__item"
                :class="{ 'is-active': activeTab === t.id }"
                @click="setTab(t.id)"
            >
                {{ t.label }}
                <span v-if="t.badge !== undefined && t.badge > 0" class="ui-pill-nav__badge">{{ t.badge }}</span>
            </button>
        </UiPillNav>

        <!-- О компании -->
        <div v-show="activeTab === 'info'" class="space-y-6">
            <CompanyShowHealthPanel
                :company-id="company.id"
                :tenant-health="tenantHealth"
                :provisioning-verify="provisioningVerify"
            />
            <section class="ui-settings-section">
                <h2 class="mb-4 text-base font-semibold">{{ t('superAdmin.companies.show.summaryTitle') }}</h2>
                <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-ui-text-muted">ID</dt>
                        <dd class="mt-0.5 font-mono text-sm text-ui-text">{{ company.id }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-ui-text-muted">{{ t('superAdmin.companies.show.fieldSubdomain') }}</dt>
                        <dd class="mt-0.5 font-mono text-sm text-ui-text">{{ company.slug }}.{{ rootDomain }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-ui-text-muted">{{ t('superAdmin.companies.show.fieldCreated') }}</dt>
                        <dd class="mt-0.5 text-sm text-ui-text">{{ formatDate(company.created_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-ui-text-muted">{{ t('superAdmin.companies.show.fieldUpdated') }}</dt>
                        <dd class="mt-0.5 text-sm text-ui-text">{{ formatDate(company.updated_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-ui-text-muted">{{ t('superAdmin.companies.show.fieldCurrentPlan') }}</dt>
                        <dd class="mt-0.5 text-sm text-ui-text">
                            {{ company.plan?.name ?? '—' }}
                            <span v-if="company.plan" class="text-ui-text-secondary">
                                {{ t('superAdmin.companies.show.planPerMonth', { code: company.plan.code, price: formatPrice(company.plan.price_cents) }) }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-ui-text-muted">{{ t('superAdmin.companies.show.fieldOwner') }}</dt>
                        <dd class="mt-0.5 text-sm text-ui-text">
                            <select
                                class="ui-select max-w-md"
                                :value="company.owner?.id ?? ''"
                                :disabled="ownerAssigning || administratorUsers.length === 0"
                                @change="onOwnerSelectChange"
                            >
                                <option value="">{{ t('superAdmin.companies.show.ownerNotAssigned') }}</option>
                                <option
                                    v-for="u in administratorUsers"
                                    :key="u.id"
                                    :value="u.id"
                                >
                                    {{ u.name }}{{ u.email ? ` · ${u.email}` : '' }}
                                </option>
                            </select>
                            <p v-if="administratorUsers.length === 0" class="mt-1 text-xs text-ui-text-muted">
                                {{ t('superAdmin.companies.show.ownerNoAdministrators') }}
                            </p>
                            <p v-else class="mt-1 text-xs text-ui-text-muted">
                                {{ t('superAdmin.companies.show.ownerSelectHint') }}
                            </p>
                        </dd>
                    </div>
                    <div v-if="company.email">
                        <dt class="text-xs font-medium uppercase tracking-wide text-ui-text-muted">{{ t('superAdmin.companies.show.fieldCompanyEmail') }}</dt>
                        <dd class="mt-0.5 text-sm text-ui-text">{{ company.email }}</dd>
                    </div>
                    <div v-if="company.website">
                        <dt class="text-xs font-medium uppercase tracking-wide text-ui-text-muted">{{ t('superAdmin.companies.show.fieldWebsite') }}</dt>
                        <dd class="mt-0.5 text-sm">
                            <a :href="company.website" target="_blank" rel="noopener noreferrer" class="text-ui-accent hover:underline">
                                {{ company.website }}
                            </a>
                        </dd>
                    </div>
                </dl>
                <p v-if="company.description" class="mt-4 border-t border-ui-border pt-4 text-sm text-ui-text-secondary">
                    {{ company.description }}
                </p>
            </section>

            <section class="ui-settings-section">
                <h2 class="mb-1 text-base font-semibold">{{ t('superAdmin.companies.show.profileTitle') }}</h2>
                <p class="mb-4 text-sm text-ui-text-secondary">{{ t('superAdmin.companies.show.profileHint') }}</p>
                <form class="max-w-xl space-y-4" @submit.prevent="saveCompany">
                    <label class="block text-sm">
                        <span class="text-ui-text-secondary">{{ t('superAdmin.companies.field.name') }}</span>
                        <input v-model="form.name" class="ui-input mt-1" required />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-ui-danger">{{ form.errors.name }}</p>
                    </label>
                    <label class="block text-sm">
                        <span class="text-ui-text-secondary">{{ t('superAdmin.companies.field.phone') }}</span>
                        <input v-model="form.phone" type="tel" class="ui-input mt-1" placeholder="+7 747 123 45 67" />
                        <p v-if="form.errors.phone" class="mt-1 text-xs text-ui-danger">{{ form.errors.phone }}</p>
                    </label>
                    <label class="block text-sm">
                        <span class="text-ui-text-secondary">{{ t('superAdmin.companies.show.fieldTrialEnds') }}</span>
                        <input v-model="form.trial_ends_at" type="date" class="ui-input mt-1" />
                        <p v-if="form.errors.trial_ends_at" class="mt-1 text-xs text-ui-danger">{{ form.errors.trial_ends_at }}</p>
                    </label>
                    <label class="flex items-center gap-2 text-sm text-ui-text-secondary">
                        <UiCheckbox v-model="form.is_active" size="sm" />
                        {{ t('superAdmin.companies.show.tenantActive') }}
                    </label>
                    <button type="submit" class="ui-btn ui-btn--primary" :disabled="form.processing">
                        {{ form.processing ? t('superAdmin.common.saving') : t('superAdmin.common.save') }}
                    </button>
                </form>
            </section>

            <section class="ui-settings-section">
                <h2 class="mb-4 text-base font-semibold">{{ t('superAdmin.companies.show.metricsTitle') }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="ui-panel px-4 py-3">
                        <div class="text-xs text-ui-text-muted">{{ t('superAdmin.companies.show.metricsUsers') }}</div>
                        <div class="mt-0.5 text-xl font-semibold">{{ company.users_count ?? companyUsers.length }}</div>
                    </div>
                    <div class="ui-panel px-4 py-3">
                        <div class="text-xs text-ui-text-muted">{{ t('superAdmin.companies.show.metricsSubscriptions') }}</div>
                        <div class="mt-0.5 text-xl font-semibold">{{ company.subscriptions_count ?? company.subscriptions.length }}</div>
                    </div>
                    <button
                        type="button"
                        class="ui-panel px-4 py-3 text-left transition-colors hover:bg-ui-surface-hover"
                        @click="setTab('invoices')"
                    >
                        <div class="text-xs text-ui-text-muted">{{ t('superAdmin.companies.show.metricsInvoices') }}</div>
                        <div class="mt-0.5 text-xl font-semibold">{{ company.invoices_count ?? invoices.length }}</div>
                        <span v-if="billingSummary.overdue_invoices > 0" class="mt-1 inline-block text-xs text-ui-accent">
                            {{ t('superAdmin.companies.show.metricsUnpaid', { count: billingSummary.overdue_invoices }) }}
                        </span>
                    </button>
                    <div class="ui-panel px-4 py-3">
                        <div class="text-xs text-ui-text-muted">{{ t('superAdmin.companies.show.metricsWhatsappSessions') }}</div>
                        <div class="mt-0.5 text-xl font-semibold">{{ company.whatsapp_sessions_count ?? 0 }}</div>
                    </div>
                </div>
            </section>
        </div>

        <CompanyShowModulesPanel
            v-show="activeTab === 'modules'"
            :company-id="company.id"
            :modules="companyModules"
        />

        <!-- Подписка -->
        <div v-show="activeTab === 'subscription'" class="space-y-6">
            <section class="ui-settings-section max-w-2xl">
                <h2 class="mb-3 text-base font-semibold">{{ t('superAdmin.companies.show.subscriptionCurrentTitle') }}</h2>
                <p class="mb-4 text-sm text-ui-text-secondary">
                    {{ t('superAdmin.companies.show.subscriptionCurrentHint', { priceLabel: billing.standard_price_label, days: billing.trial_days }) }}
                </p>
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <span :class="subscriptionStatusBadgeClass(company.subscription_status)">
                        {{ statusLabels[company.subscription_status as keyof typeof statusLabels] ?? company.subscription_status }}
                    </span>
                    <span v-if="company.plan" class="text-sm text-ui-text-secondary">
                        {{ t('superAdmin.companies.show.planOption', { name: company.plan.name, price: formatPrice(company.plan.price_cents) }) }}
                    </span>
                </div>
                <div class="mb-4 flex flex-wrap items-end gap-3">
                    <label v-if="company.subscription_status === 'trial' || company.subscription_status === 'past_due'" class="block text-sm text-ui-text-secondary">
                        {{ t('superAdmin.companies.show.subscriptionMonths') }}
                        <select v-model.number="activateMonths" class="ui-select mt-1">
                            <option v-for="m in 24" :key="m" :value="m">{{ m }}</option>
                        </select>
                    </label>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-if="company.subscription_status === 'trial' || company.subscription_status === 'past_due'"
                        type="button"
                        class="ui-btn ui-btn--primary ui-btn--sm"
                        @click="requestActivatePaid"
                    >
                        {{ t('superAdmin.companies.show.subscriptionPay', { months: activateMonths }) }}
                    </button>
                    <button
                        v-if="company.subscription_status !== 'canceled'"
                        type="button"
                        class="ui-btn ui-btn--danger-ghost ui-btn--sm"
                        @click="requestCancelSubscription"
                    >
                        {{ t('superAdmin.companies.show.subscriptionCancel') }}
                    </button>
                </div>
            </section>

            <section class="ui-settings-section max-w-2xl">
                <h2 class="mb-3 text-base font-semibold">{{ t('superAdmin.companies.show.changePlanTitle') }}</h2>
                <p class="mb-4 text-sm text-ui-text-secondary">{{ t('superAdmin.companies.show.changePlanHint') }}</p>
                <form class="space-y-3" @submit.prevent="assignPlan">
                    <label class="block text-sm text-ui-text-secondary">
                        {{ t('superAdmin.companies.field.plan') }}
                        <select v-model="planForm.plan_id" class="ui-select mt-1 w-full">
                            <option v-for="p in plans" :key="p.id" :value="p.id">
                                {{ t('superAdmin.companies.show.planOption', { name: p.name, price: formatPrice(p.price_cents) }) }}
                            </option>
                        </select>
                    </label>
                    <label class="flex items-center gap-2 text-sm text-ui-text-secondary">
                        <UiCheckbox v-model="planForm.restart_trial" size="sm" />
                        {{ t('superAdmin.companies.show.restartTrial') }}
                    </label>
                    <button type="submit" class="ui-btn ui-btn--secondary ui-btn--sm" :disabled="planForm.processing">
                        {{ t('superAdmin.companies.show.applyPlan') }}
                    </button>
                </form>
            </section>

            <section class="ui-settings-section max-w-2xl">
                <h2 class="mb-3 text-base font-semibold">{{ t('superAdmin.companies.show.manualTitle') }}</h2>
                <p class="mb-4 text-sm text-ui-text-secondary">{{ t('superAdmin.companies.show.manualHint') }}</p>
                <form class="space-y-3" @submit.prevent="saveCompany">
                    <label class="block text-sm">
                        <span class="text-ui-text-secondary">{{ t('superAdmin.companies.show.statusField') }}</span>
                        <select v-model="form.subscription_status" class="ui-select mt-1 w-full">
                            <option value="trial">{{ t('superAdmin.companies.show.statusTrial') }}</option>
                            <option value="active">{{ t('superAdmin.companies.show.statusActive') }}</option>
                            <option value="past_due">{{ t('superAdmin.companies.show.statusPastDue') }}</option>
                            <option value="suspended">{{ t('superAdmin.companies.show.statusSuspended') }}</option>
                            <option value="canceled">{{ t('superAdmin.companies.show.statusCanceled') }}</option>
                        </select>
                    </label>
                    <label class="block text-sm">
                        <span class="text-ui-text-secondary">{{ t('superAdmin.companies.show.planOnSave') }}</span>
                        <select v-model="form.plan_id" class="ui-select mt-1 w-full">
                            <option :value="null">{{ t('superAdmin.common.emDash') }}</option>
                            <option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                    </label>
                    <button type="submit" class="ui-btn ui-btn--primary ui-btn--sm" :disabled="form.processing">
                        {{ t('superAdmin.companies.show.saveStatusPlan') }}
                    </button>
                </form>
            </section>
        </div>

        <!-- История -->
        <div v-show="activeTab === 'history'">
            <div class="ui-panel overflow-hidden p-0">
                <div class="border-b border-ui-border px-4 py-3">
                    <h2 class="font-medium">{{ t('superAdmin.companies.show.historyTitle') }}</h2>
                    <p class="mt-0.5 text-sm text-ui-text-secondary">
                        {{ t('superAdmin.companies.show.historyCount', { count: company.subscriptions_count ?? company.subscriptions.length }) }}
                    </p>
                </div>
                <div class="ui-table-panel">
                    <table class="min-w-[720px] w-full text-left text-sm">
                        <thead>
                            <tr>
                                <th>{{ t('superAdmin.companies.show.historyPlan') }}</th>
                                <th>{{ t('superAdmin.companies.show.historyStatus') }}</th>
                                <th>{{ t('superAdmin.companies.show.historyEvent') }}</th>
                                <th>{{ t('superAdmin.companies.show.historyStart') }}</th>
                                <th>{{ t('superAdmin.companies.show.historyEnd') }}</th>
                                <th>{{ t('superAdmin.companies.show.historyClosed') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in company.subscriptions" :key="s.id">
                                <td class="!text-ui-text">{{ s.plan?.name ?? t('superAdmin.common.emDash') }}</td>
                                <td>
                                    <span class="inline-flex" :class="subscriptionStatusBadgeClass(s.status)">
                                        {{ statusLabels[s.status as keyof typeof statusLabels] ?? s.status }}
                                    </span>
                                </td>
                                <td>{{ s.event ? (eventLabels[s.event as keyof typeof eventLabels] ?? s.event) : t('superAdmin.common.emDash') }}</td>
                                <td>{{ formatDate(s.started_at) }}</td>
                                <td>
                                    <span v-if="s.trial_ends_at">{{ t('superAdmin.companies.show.historyTrialUntil', { date: formatDate(s.trial_ends_at) }) }}</span>
                                    <span v-else-if="s.ends_at">{{ t('superAdmin.companies.show.historyUntil', { date: formatDate(s.ends_at) }) }}</span>
                                    <span v-else>{{ t('superAdmin.common.emDash') }}</span>
                                </td>
                                <td class="!text-ui-text-muted">{{ formatDate(s.ended_at) }}</td>
                            </tr>
                            <tr v-if="company.subscriptions.length === 0">
                                <td colspan="6" class="!py-8 text-center !text-ui-text-muted">{{ t('superAdmin.companies.show.historyEmpty') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <CompanyShowInvoicesPanel
            v-show="activeTab === 'invoices'"
            :company-id="company.id"
            :invoices="invoices"
            :default-amount-cents="defaultPlanPriceCents"
        />

        <CompanyShowUsersPanel
            v-show="activeTab === 'users'"
            :company-id="company.id"
            :users="companyUsers"
            :departments="companyDepartments"
            :whatsapp-sessions="companyWhatsappSessions"
        />

        <CompanyShowWhatsappPanel
            v-show="activeTab === 'whatsapp'"
            :active="activeTab === 'whatsapp'"
            :company-id="company.id"
            :sessions="whatsappSessions"
            :whatsapp-service-reachable="whatsappServiceReachable"
            :max-sessions="whatsappMaxSessions"
        />

        <CompanyShowAuditPanel v-show="activeTab === 'audit'" :audit-logs="auditLogs" />

        <DangerConfirmModal
            :open="showToggleConfirm"
            :title="t('superAdmin.companies.show.toggleModalTitle')"
            :description="toggleConfirmDescription"
            :confirm-label="company.is_active ? t('superAdmin.companies.index.toggleDisable') : t('superAdmin.companies.index.toggleEnable')"
            confirm-variant="primary"
            @close="showToggleConfirm = false"
            @confirm="confirmQuickToggle"
        />
        <DangerConfirmModal
            :open="showActivateConfirm"
            :title="t('superAdmin.companies.show.activateModalTitle')"
            :description="t('superAdmin.companies.show.activateModalDescription', { months: activateMonths })"
            :confirm-label="t('superAdmin.companies.show.activateModalConfirm')"
            confirm-variant="primary"
            @close="showActivateConfirm = false"
            @confirm="confirmActivatePaid"
        />
        <DangerConfirmModal
            :open="showCancelConfirm"
            :title="t('superAdmin.companies.show.cancelModalTitle')"
            :description="t('superAdmin.companies.show.cancelModalDescription')"
            :confirm-label="t('superAdmin.companies.show.cancelModalConfirm')"
            @close="showCancelConfirm = false"
            @confirm="confirmCancelSubscription"
        />
        <DangerConfirmModal
            :open="showDeleteConfirm"
            :title="t('superAdmin.companies.show.deleteModalTitle')"
            :description="t('superAdmin.companies.show.deleteModalDescription', {
                name: company.name,
                slug: company.slug,
                domain: rootDomain,
            })"
            :confirm-label="t('superAdmin.companies.show.deleteModalConfirm')"
            confirm-variant="danger"
            :busy="deleting"
            @close="showDeleteConfirm = false"
            @confirm="confirmDelete"
        />
    </SuperAdminLayout>
</template>
