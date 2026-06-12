<script setup lang="ts">
import UiPillNav from '@/Components/Ui/UiPillNav.vue';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type Kpi = {
    key: string;
    label: string;
    percent: number | null;
    numerator: number;
    denominator: number;
    sufficient_data: boolean;
};

type LostReason = {
    reason: string;
    count: number;
    percent: number;
};

type WinRateGrade = {
    grade: string;
    won: number;
    total: number;
    percent: number | null;
};

type ObjectionRow = {
    label: string;
    frequency: number;
    win_rate: number | null;
};

type ObjectionResponseRow = {
    text: string;
    win_count?: number;
    loss_count?: number;
};

type CompanyRow = {
    company_id: number;
    company_name: string;
    company_slug: string;
    cohort_size: number;
    closed_deals: number;
    qualification_rate: number | null;
    budget_capture_rate: number | null;
    close_rate: number | null;
    meeting_booking_rate: number | null;
};

type CompanyOption = {
    id: number;
    name: string;
    slug: string;
};

const props = defineProps<{
    metrics: {
        period: { from: string; to: string };
        filters: { company_id: number | null; company_name: string | null };
        summary: { cohort_size: number; closed_deals: number; follow_ups_sent: number };
        kpis: Kpi[];
        lost_reasons: LostReason[];
        win_rate_by_grade: WinRateGrade[];
        objection_intelligence: {
            top_objections: ObjectionRow[];
            top_winning_responses: ObjectionResponseRow[];
            top_losing_responses: ObjectionResponseRow[];
        };
        by_company: CompanyRow[];
    };
    companies: CompanyOption[];
    filters: {
        period: string;
        company_id: number | null;
    };
}>();

const { t, locale } = useI18n();

const period = ref(props.filters.period || '30d');
const companyId = ref(props.filters.company_id ? String(props.filters.company_id) : '');

const periodOptions = computed(() => [
    { id: '7d', label: t('superAdmin.aiSales.period7d') },
    { id: '30d', label: t('superAdmin.aiSales.period30d') },
    { id: '90d', label: t('superAdmin.aiSales.period90d') },
]);

const pipelineKpis = computed(() =>
    props.metrics.kpis.filter((kpi) => ![
        'close_rate',
        'follow_up_response_rate',
        'nurture_response_rate',
        'funnel_follow_up_response_rate',
        'deferral_recovery_rate',
    ].includes(kpi.key)),
);

const outcomeKpis = computed(() =>
    props.metrics.kpis.filter((kpi) => [
        'close_rate',
        'follow_up_response_rate',
        'nurture_response_rate',
        'funnel_follow_up_response_rate',
        'deferral_recovery_rate',
    ].includes(kpi.key)),
);

const maxLostReasonPercent = computed(() => {
    const values = props.metrics.lost_reasons.map((row) => row.percent);
    return values.length > 0 ? Math.max(...values) : 100;
});

const dateLocale = computed(() => (locale.value === 'kk' ? 'kk-KZ' : locale.value === 'en' ? 'en-GB' : 'ru-RU'));

const periodLabel = computed(() => {
    try {
        const from = new Date(props.metrics.period.from);
        const to = new Date(props.metrics.period.to);
        const fmt = new Intl.DateTimeFormat(dateLocale.value, { dateStyle: 'medium' });
        return `${fmt.format(from)} — ${fmt.format(to)}`;
    } catch {
        return '';
    }
});

function kpiLabel(key: string, fallback: string): string {
    const map: Record<string, string> = {
        qualification_rate: t('superAdmin.aiSales.kpiQualification'),
        budget_capture_rate: t('superAdmin.aiSales.kpiBudget'),
        dm_capture_rate: t('superAdmin.aiSales.kpiDm'),
        proposal_rate: t('superAdmin.aiSales.kpiProposal'),
        meeting_booking_rate: t('superAdmin.aiSales.kpiMeeting'),
        close_rate: t('superAdmin.aiSales.kpiClose'),
        follow_up_response_rate: t('superAdmin.aiSales.kpiFollowUp'),
        requirements_capture_rate: t('superAdmin.aiSales.kpiRequirements'),
        timeline_capture_rate: t('superAdmin.aiSales.kpiTimeline'),
        nurture_response_rate: t('superAdmin.aiSales.kpiNurtureResponse'),
        funnel_follow_up_response_rate: t('superAdmin.aiSales.kpiFunnelFollowUp'),
        deferral_recovery_rate: t('superAdmin.aiSales.kpiDeferralRecovery'),
    };
    return map[key] ?? fallback;
}

function formatPercent(value: number | null): string {
    if (value === null) {
        return t('superAdmin.common.emDash');
    }
    return `${value.toFixed(1)}%`;
}

function applyFilters(): void {
    router.get(
        '/ai-sales',
        {
            period: period.value,
            company_id: companyId.value !== '' ? companyId.value : undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

</script>

<template>
    <SuperAdminLayout>
        <Head :title="t('superAdmin.aiSales.pageTitle')" />

        <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold">{{ t('superAdmin.aiSales.title') }}</h1>
                <p class="mt-1 text-sm text-ui-text-secondary">{{ t('superAdmin.aiSales.subtitle') }}</p>
                <p v-if="periodLabel" class="mt-1 text-xs text-ui-text-muted">{{ periodLabel }}</p>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <UiPillNav class="shrink-0">
                    <button
                        v-for="option in periodOptions"
                        :key="option.id"
                        type="button"
                        class="ui-pill-nav__item"
                        :class="{ 'is-active': period === option.id }"
                        @click="period = option.id; applyFilters()"
                    >
                        {{ option.label }}
                    </button>
                </UiPillNav>

                <select
                    v-model="companyId"
                    class="ui-input min-w-[220px]"
                    :aria-label="t('superAdmin.aiSales.companyFilter')"
                    @change="applyFilters()"
                >
                    <option value="">{{ t('superAdmin.aiSales.allCompanies') }}</option>
                    <option v-for="company in companies" :key="company.id" :value="String(company.id)">
                        {{ company.name }} ({{ company.slug }})
                    </option>
                </select>
            </div>
        </div>

        <p class="ui-alert mb-6 border-ui-border bg-ui-surface-soft text-sm text-ui-text-secondary">
            {{ t('superAdmin.aiSales.disclaimer') }}
        </p>

        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <div class="ui-panel p-4">
                <div class="text-sm text-ui-text-secondary">{{ t('superAdmin.aiSales.summaryCohort') }}</div>
                <div class="mt-1 text-3xl font-semibold">{{ metrics.summary.cohort_size }}</div>
            </div>
            <div class="ui-panel p-4">
                <div class="text-sm text-ui-text-secondary">{{ t('superAdmin.aiSales.summaryClosed') }}</div>
                <div class="mt-1 text-3xl font-semibold">{{ metrics.summary.closed_deals }}</div>
            </div>
            <div class="ui-panel p-4">
                <div class="text-sm text-ui-text-secondary">{{ t('superAdmin.aiSales.summaryFollowUps') }}</div>
                <div class="mt-1 text-3xl font-semibold">{{ metrics.summary.follow_ups_sent }}</div>
            </div>
        </div>

        <section class="mb-8">
            <h2 class="mb-3 text-lg font-semibold">{{ t('superAdmin.aiSales.pipelineTitle') }}</h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <div v-for="kpi in pipelineKpis" :key="kpi.key" class="ui-panel p-4">
                    <div class="text-sm text-ui-text-secondary">{{ kpiLabel(kpi.key, kpi.label) }}</div>
                    <div class="mt-1 text-3xl font-semibold">
                        <template v-if="kpi.sufficient_data">{{ formatPercent(kpi.percent) }}</template>
                        <span v-else class="text-base font-normal text-ui-text-muted">
                            {{ t('superAdmin.aiSales.insufficientData') }}
                        </span>
                    </div>
                    <p class="mt-2 text-xs text-ui-text-muted">
                        {{ kpi.numerator }} / {{ kpi.denominator }}
                    </p>
                </div>
            </div>
        </section>

        <section class="mb-8">
            <h2 class="mb-3 text-lg font-semibold">{{ t('superAdmin.aiSales.outcomesTitle') }}</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div v-for="kpi in outcomeKpis" :key="kpi.key" class="ui-panel p-4">
                    <div class="text-sm text-ui-text-secondary">{{ kpiLabel(kpi.key, kpi.label) }}</div>
                    <div class="mt-1 text-3xl font-semibold">
                        <template v-if="kpi.sufficient_data">{{ formatPercent(kpi.percent) }}</template>
                        <span v-else class="text-base font-normal text-ui-text-muted">
                            {{ t('superAdmin.aiSales.insufficientData') }}
                        </span>
                    </div>
                    <p class="mt-2 text-xs text-ui-text-muted">
                        {{ kpi.numerator }} / {{ kpi.denominator }}
                    </p>
                </div>
            </div>
        </section>

        <div class="mb-8 grid gap-6 lg:grid-cols-2">
            <section class="ui-panel overflow-hidden p-0">
                <div class="border-b border-ui-border px-4 py-3 font-medium">
                    {{ t('superAdmin.aiSales.lostReasonsTitle') }}
                </div>
                <div v-if="metrics.lost_reasons.length === 0" class="px-4 py-6 text-sm text-ui-text-muted">
                    {{ t('superAdmin.aiSales.noData') }}
                </div>
                <div v-else class="space-y-4 px-4 py-4">
                    <div v-for="row in metrics.lost_reasons" :key="row.reason">
                        <div class="mb-1 flex items-center justify-between gap-3 text-sm">
                            <span class="truncate">{{ row.reason }}</span>
                            <span class="shrink-0 text-ui-text-secondary">{{ row.count }} · {{ row.percent }}%</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-ui-surface-soft">
                            <div
                                class="h-full rounded-full bg-ui-accent"
                                :style="{ width: `${Math.max(4, (row.percent / maxLostReasonPercent) * 100)}%` }"
                            />
                        </div>
                    </div>
                </div>
            </section>

            <section class="ui-panel overflow-hidden p-0">
                <div class="border-b border-ui-border px-4 py-3 font-medium">
                    {{ t('superAdmin.aiSales.winByGradeTitle') }}
                </div>
                <div v-if="metrics.win_rate_by_grade.length === 0" class="px-4 py-6 text-sm text-ui-text-muted">
                    {{ t('superAdmin.aiSales.noData') }}
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-ui-border bg-ui-surface-soft text-left text-ui-text-secondary">
                            <tr>
                                <th class="px-4 py-2 font-medium">{{ t('superAdmin.aiSales.gradeColumn') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('superAdmin.aiSales.wonColumn') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('superAdmin.aiSales.totalColumn') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('superAdmin.aiSales.rateColumn') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ui-border">
                            <tr v-for="row in metrics.win_rate_by_grade" :key="row.grade">
                                <td class="px-4 py-2 font-medium">{{ row.grade }}</td>
                                <td class="px-4 py-2">{{ row.won }}</td>
                                <td class="px-4 py-2">{{ row.total }}</td>
                                <td class="px-4 py-2">{{ formatPercent(row.percent) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <section class="mb-8 ui-panel overflow-hidden p-0">
            <div class="border-b border-ui-border px-4 py-3 font-medium">
                {{ t('superAdmin.aiSales.objectionsTitle') }}
            </div>
            <div v-if="metrics.objection_intelligence.top_objections.length === 0" class="px-4 py-6 text-sm text-ui-text-muted">
                {{ t('superAdmin.aiSales.noData') }}
            </div>
            <div v-else class="grid gap-6 px-4 py-4 lg:grid-cols-3">
                <div>
                    <h3 class="mb-2 text-sm font-medium text-ui-text-secondary">{{ t('superAdmin.aiSales.topObjections') }}</h3>
                    <ul class="space-y-2 text-sm">
                        <li v-for="row in metrics.objection_intelligence.top_objections" :key="row.label">
                            <span class="font-medium">{{ row.label }}</span>
                            <span class="text-ui-text-muted"> — {{ row.frequency }}</span>
                            <span v-if="row.win_rate != null" class="text-ui-text-secondary"> · {{ row.win_rate }}%</span>
                        </li>
                    </ul>
                </div>
                <div>
                    <h3 class="mb-2 text-sm font-medium text-ui-text-secondary">{{ t('superAdmin.aiSales.topWinningResponses') }}</h3>
                    <ul class="space-y-2 text-sm">
                        <li v-for="(row, idx) in metrics.objection_intelligence.top_winning_responses" :key="`win-${idx}`">
                            {{ row.text }}
                            <span class="text-ui-text-muted"> ({{ row.win_count }})</span>
                        </li>
                    </ul>
                </div>
                <div>
                    <h3 class="mb-2 text-sm font-medium text-ui-text-secondary">{{ t('superAdmin.aiSales.topLosingResponses') }}</h3>
                    <ul class="space-y-2 text-sm">
                        <li v-for="(row, idx) in metrics.objection_intelligence.top_losing_responses" :key="`loss-${idx}`">
                            {{ row.text }}
                            <span class="text-ui-text-muted"> ({{ row.loss_count }})</span>
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        <section v-if="metrics.by_company.length > 0" class="ui-panel overflow-hidden p-0">
            <div class="border-b border-ui-border px-4 py-3 font-medium">
                {{ t('superAdmin.aiSales.byCompanyTitle') }}
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-ui-border bg-ui-surface-soft text-left text-ui-text-secondary">
                        <tr>
                            <th class="px-4 py-2 font-medium">{{ t('superAdmin.aiSales.companyColumn') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('superAdmin.aiSales.summaryCohort') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('superAdmin.aiSales.kpiQualification') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('superAdmin.aiSales.kpiBudget') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('superAdmin.aiSales.kpiMeeting') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('superAdmin.aiSales.kpiClose') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ui-border">
                        <tr v-for="row in metrics.by_company" :key="row.company_id">
                            <td class="px-4 py-2">
                                <button
                                    type="button"
                                    class="font-medium text-ui-accent hover:underline"
                                    @click="companyId = String(row.company_id); applyFilters()"
                                >
                                    {{ row.company_name }}
                                </button>
                                <div class="text-xs text-ui-text-muted">{{ row.company_slug }}</div>
                            </td>
                            <td class="px-4 py-2">{{ row.cohort_size }}</td>
                            <td class="px-4 py-2">{{ formatPercent(row.qualification_rate) }}</td>
                            <td class="px-4 py-2">{{ formatPercent(row.budget_capture_rate) }}</td>
                            <td class="px-4 py-2">{{ formatPercent(row.meeting_booking_rate) }}</td>
                            <td class="px-4 py-2">{{ formatPercent(row.close_rate) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </SuperAdminLayout>
</template>
