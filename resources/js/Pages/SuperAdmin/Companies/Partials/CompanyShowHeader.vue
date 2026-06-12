<script setup lang="ts">
import SuperAdminKpiGrid, { type SuperAdminKpiItem } from '@/Components/SuperAdmin/SuperAdminKpiGrid.vue';
import { useRegisterSuperAdminPageChrome } from '@/composables/useSuperAdminPageChrome';
import { useI18n } from '@/composables/useI18n';
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import { subscriptionStatusBadgeClass } from '@/utils/superAdminSubscriptionBadge';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    company: {
        id: number;
        name: string;
        slug: string;
        is_active: boolean;
        subscription_status: string;
        trial_ends_at: string | null;
        current_period_ends_at: string | null;
    };
    tenantUrl: string;
    canImpersonate: boolean;
    impersonateBlockedReason?: string | null;
    canPopulateSandbox?: boolean;
    canClearSandboxData?: boolean;
    canDelete?: boolean;
    rootDomain?: string;
    billingSummary: {
        mrr_kzt: number;
        next_payment_at: string | null;
        overdue_invoices: number;
        trial_days_left: number | null;
        revenue_sparkline: Array<{ label: string; amount_kzt: number }>;
    };
    trialInfo: string | null;
    statusLabels: Record<string, string>;
}>();

const emit = defineEmits<{
    toggle: [];
    delete: [];
}>();

const { t } = useI18n();

const topbarSubtitle = computed(() =>
    props.trialInfo
    ?? t('superAdmin.companies.header.openTenant', { slug: props.company.slug, domain: props.rootDomain ?? 'accel.kz' }),
);

const statusLabel = computed(
    () => props.statusLabels[props.company.subscription_status] ?? props.company.subscription_status,
);

const titleBadge = computed(() => ({
    text: statusLabel.value,
    className: subscriptionStatusBadgeClass(props.company.subscription_status),
}));

useRegisterSuperAdminPageChrome(() => ({
    eyebrow: t('superAdmin.layout.nav.companies'),
    title: props.company.name,
    subtitle: topbarSubtitle.value,
    accentGroup: 'operations',
    titleBadge: titleBadge.value,
}));

const maxSpark = computed(() =>
    Math.max(1, ...props.billingSummary.revenue_sparkline.map((p) => p.amount_kzt)),
);

const billingKpis = computed((): SuperAdminKpiItem[] => [
    {
        label: 'MRR',
        value: `${props.billingSummary.mrr_kzt.toLocaleString('ru-RU')} ₸`,
        tone: 'billing',
    },
    {
        label: t('superAdmin.companies.header.nextPayment'),
        value: formatDate(props.billingSummary.next_payment_at),
        hint: props.billingSummary.trial_days_left !== null
            ? t('superAdmin.companies.header.trialDays', { days: props.billingSummary.trial_days_left })
            : undefined,
        tone: 'info',
    },
    {
        label: t('superAdmin.companies.header.unpaidInvoices'),
        value: props.billingSummary.overdue_invoices,
        tone: props.billingSummary.overdue_invoices > 0 ? 'danger' : 'default',
    },
]);

function formatDate(iso: string | null): string {
    if (!iso) return t('superAdmin.common.emDash');
    return new Date(iso).toLocaleDateString('ru-RU', { dateStyle: 'medium' });
}

const impersonating = ref(false);
const populating = ref(false);
const clearingSandbox = ref(false);
const showClearSandboxConfirm = ref(false);

function populateSandbox(): void {
    if (populating.value || !props.canPopulateSandbox) {
        return;
    }

    populating.value = true;
    router.post(
        `/companies/${props.company.id}/populate-sandbox`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                populating.value = false;
            },
            onError: () => {
                populating.value = false;
            },
        },
    );
}

function confirmClearSandbox(): void {
    if (clearingSandbox.value || !props.canClearSandboxData) {
        return;
    }

    clearingSandbox.value = true;
    router.delete(
        `/companies/${props.company.id}/sandbox-data`,
        {
            preserveScroll: true,
            onFinish: () => {
                clearingSandbox.value = false;
                showClearSandboxConfirm.value = false;
            },
            onError: () => {
                clearingSandbox.value = false;
            },
        },
    );
}

function impersonate(): void {
    if (impersonating.value || !props.canImpersonate) {
        return;
    }

    impersonating.value = true;
    router.post(
        `/companies/${props.company.id}/impersonate`,
        {},
        {
            preserveState: false,
            preserveScroll: false,
            onFinish: () => {
                impersonating.value = false;
            },
            onError: () => {
                impersonating.value = false;
            },
        },
    );
}
</script>

<template>
    <Teleport to="#sa-topbar-actions">
        <div class="ui-super-admin-topbar-chrome__actions">
            <button
                type="button"
                class="ui-btn ui-btn--secondary inline-flex items-center gap-3"
                @click="emit('toggle')"
            >
                <span
                    class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full"
                    :class="company.is_active ? 'bg-ui-accent' : 'bg-ui-surface-muted'"
                >
                    <span
                        class="inline-block h-4 w-4 transform rounded-full bg-white shadow"
                        :class="company.is_active ? 'translate-x-4' : 'translate-x-1'"
                    ></span>
                </span>
                {{ company.is_active ? t('superAdmin.companies.header.tenantEnabled') : t('superAdmin.companies.header.tenantDisabled') }}
            </button>
        </div>
    </Teleport>

    <header
        class="ui-super-admin-page-header ui-super-admin-page-header--mobile ui-super-admin-page-header--operations ui-super-admin-company-header lg:hidden"
    >
        <div class="ui-super-admin-page-header__intro min-w-0 flex-1">
            <p class="ui-super-admin-page-header__eyebrow">{{ t('superAdmin.layout.nav.companies') }}</p>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="ui-super-admin-page-header__title !text-xl sm:!text-2xl">{{ company.name }}</h1>
                <span :class="subscriptionStatusBadgeClass(company.subscription_status)">
                    {{ statusLabels[company.subscription_status] ?? company.subscription_status }}
                </span>
            </div>
            <p v-if="trialInfo" class="mt-1 text-sm text-ui-accent">{{ trialInfo }}</p>
        </div>
        <div class="ui-super-admin-page-header__actions">
            <button
                type="button"
                class="ui-btn ui-btn--secondary inline-flex items-center gap-3"
                @click="emit('toggle')"
            >
                <span
                    class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full"
                    :class="company.is_active ? 'bg-ui-accent' : 'bg-ui-surface-muted'"
                >
                    <span
                        class="inline-block h-4 w-4 transform rounded-full bg-white shadow"
                        :class="company.is_active ? 'translate-x-4' : 'translate-x-1'"
                    ></span>
                </span>
                {{ company.is_active ? t('superAdmin.companies.header.tenantEnabled') : t('superAdmin.companies.header.tenantDisabled') }}
            </button>
        </div>
    </header>

    <div class="ui-super-admin-company-toolbar">
        <a
            :href="tenantUrl"
            target="_blank"
            rel="noopener noreferrer"
            class="text-sm text-ui-accent hover:text-ui-accent-hover hover:underline"
        >
            {{ t('superAdmin.companies.header.openTenant', { slug: company.slug, domain: rootDomain ?? 'accel.kz' }) }}
        </a>
        <button
            v-if="canPopulateSandbox"
            type="button"
            class="ui-btn ui-btn--secondary ui-btn--sm"
            :disabled="populating"
            @click="populateSandbox"
        >
            {{ populating ? t('superAdmin.companies.header.populating') : t('superAdmin.companies.header.populateSandbox') }}
        </button>
        <button
            v-if="canClearSandboxData"
            type="button"
            class="ui-btn ui-btn--danger-ghost ui-btn--sm"
            :disabled="clearingSandbox"
            @click="showClearSandboxConfirm = true"
        >
            {{ clearingSandbox ? t('superAdmin.companies.header.clearingSandbox') : t('superAdmin.companies.header.clearSandboxData') }}
        </button>
        <button
            type="button"
            class="ui-btn ui-btn--primary ui-btn--sm"
            :disabled="!canImpersonate || impersonating"
            :title="impersonateBlockedReason ?? undefined"
            @click="impersonate"
        >
            {{ impersonating ? t('superAdmin.companies.header.impersonating') : t('superAdmin.companies.header.impersonate') }}
        </button>
        <button
            v-if="canDelete"
            type="button"
            class="ui-btn ui-btn--danger-ghost ui-btn--sm"
            @click="emit('delete')"
        >
            {{ t('superAdmin.companies.header.deleteCompany') }}
        </button>
    </div>

    <p v-if="!canImpersonate && impersonateBlockedReason" class="mb-4 text-xs text-ui-text-muted">
        {{ impersonateBlockedReason }}
    </p>

    <SuperAdminKpiGrid :items="billingKpis" class="!mb-5" />

    <div class="ui-panel mb-6 px-4 py-3">
        <div class="mb-2 text-xs text-ui-text-muted">{{ t('superAdmin.companies.header.paymentsSparkline') }}</div>
        <div class="flex h-10 items-end gap-1">
            <div
                v-for="p in billingSummary.revenue_sparkline"
                :key="p.label"
                class="min-h-[4px] flex-1 rounded-t bg-ui-accent/50 transition-all"
                :style="{ height: `${Math.max(12, (p.amount_kzt / maxSpark) * 100)}%` }"
                :title="t('superAdmin.companies.header.sparklineTooltip', { label: p.label, amount: p.amount_kzt.toLocaleString('ru-RU') })"
            />
        </div>
    </div>

    <DangerConfirmModal
        :open="showClearSandboxConfirm"
        :title="t('superAdmin.companies.header.clearSandboxModalTitle')"
        :description="t('superAdmin.companies.header.clearSandboxModalDescription')"
        :confirm-label="t('superAdmin.companies.header.clearSandboxModalConfirm')"
        confirm-variant="danger"
        :busy="clearingSandbox"
        @close="showClearSandboxConfirm = false"
        @confirm="confirmClearSandbox"
    />
</template>
