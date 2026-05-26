<script setup lang="ts">
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
}>();

const maxSpark = computed(() =>
    Math.max(1, ...props.billingSummary.revenue_sparkline.map((p) => p.amount_kzt)),
);

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('ru-RU', { dateStyle: 'medium' });
}

const impersonating = ref(false);

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
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-bold sm:text-2xl">{{ company.name }}</h1>
                <span :class="subscriptionStatusBadgeClass(company.subscription_status)">
                    {{ statusLabels[company.subscription_status] ?? company.subscription_status }}
                </span>
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <a
                    :href="tenantUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-sm text-ui-accent hover:text-ui-accent-hover hover:underline"
                >
                    {{ company.slug }}.{{ rootDomain ?? 'accel.kz' }} — открыть
                </a>
                <button
                    type="button"
                    class="ui-btn ui-btn--primary ui-btn--sm"
                    :disabled="!canImpersonate || impersonating"
                    :title="impersonateBlockedReason ?? undefined"
                    @click="impersonate"
                >
                    {{ impersonating ? 'Открываем…' : 'Войти как админ' }}
                </button>
            </div>
            <p v-if="!canImpersonate && impersonateBlockedReason" class="mt-1 text-xs text-ui-text-muted">
                {{ impersonateBlockedReason }}
            </p>
            <p v-if="trialInfo" class="mt-1 text-sm text-ui-accent">{{ trialInfo }}</p>
        </div>
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
            {{ company.is_active ? 'Тенант включён' : 'Тенант отключён' }}
        </button>
    </div>

    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="ui-panel px-4 py-3">
            <div class="text-xs text-ui-text-muted">MRR</div>
            <div class="mt-0.5 text-xl font-semibold tabular-nums">
                {{ billingSummary.mrr_kzt.toLocaleString('ru-RU') }} ₸
            </div>
        </div>
        <div class="ui-panel px-4 py-3">
            <div class="text-xs text-ui-text-muted">Следующий платёж</div>
            <div class="mt-0.5 text-sm font-medium">
                {{ formatDate(billingSummary.next_payment_at) }}
            </div>
            <p
                v-if="billingSummary.trial_days_left !== null"
                class="mt-0.5 text-xs text-ui-accent"
            >
                Триал: {{ billingSummary.trial_days_left }} дн.
            </p>
        </div>
        <div class="ui-panel px-4 py-3" :class="billingSummary.overdue_invoices > 0 ? 'ring-1 ring-ui-danger/40' : ''">
            <div class="text-xs text-ui-text-muted">Неоплаченные счета</div>
            <div
                class="mt-0.5 text-xl font-semibold"
                :class="billingSummary.overdue_invoices > 0 ? 'text-ui-danger' : ''"
            >
                {{ billingSummary.overdue_invoices }}
            </div>
        </div>
        <div class="ui-panel px-4 py-3">
            <div class="mb-2 text-xs text-ui-text-muted">Оплаты за 6 мес.</div>
            <div class="flex h-10 items-end gap-1">
                <div
                    v-for="p in billingSummary.revenue_sparkline"
                    :key="p.label"
                    class="min-h-[4px] flex-1 rounded-t bg-ui-accent/50 transition-all"
                    :style="{ height: `${Math.max(12, (p.amount_kzt / maxSpark) * 100)}%` }"
                    :title="`${p.label}: ${p.amount_kzt.toLocaleString('ru-RU')} ₸`"
                />
            </div>
        </div>
    </div>
</template>
