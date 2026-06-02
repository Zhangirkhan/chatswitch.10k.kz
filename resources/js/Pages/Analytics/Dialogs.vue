<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    DoughnutController,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';
import { useTheme } from '@/composables/useTheme';
import { useI18n } from '@/composables/useI18n';
import { computed, onMounted, ref, watch } from 'vue';
import { Bar, Doughnut, Line } from 'vue-chartjs';

type RankingsPayload = {
    fastest_avg_response: Record<string, unknown>[];
    slowest_avg_response: Record<string, unknown>[];
    most_unanswered: Record<string, unknown>[];
    most_dialogs: Record<string, unknown>[];
    best_sla: Record<string, unknown>[];
    worst_sla: Record<string, unknown>[];
};

function fmtPctStatic(p: number | null | undefined): string {
    if (p === null || p === undefined) return '—';
    return `${p}%`;
}

const RANKING_BLOCK_DEFS: {
    key: keyof RankingsPayload;
    titleKey: string;
    hintKey: string;
    secondaryKey?: string;
    primary: (r: Record<string, unknown>) => string;
    secondary?: (r: Record<string, unknown>) => string;
}[] = [
    {
        key: 'fastest_avg_response',
        titleKey: 'analytics.rankingFastest',
        hintKey: 'analytics.rankingFastestHint',
        primary: () => '',
    },
    {
        key: 'slowest_avg_response',
        titleKey: 'analytics.rankingSlowest',
        hintKey: 'analytics.rankingSlowestHint',
        primary: () => '',
    },
    {
        key: 'most_unanswered',
        titleKey: 'analytics.rankingWaiting',
        hintKey: 'analytics.rankingWaitingHint',
        primary: (r) => String(r.unanswered_dialogs ?? 0),
        secondaryKey: 'analytics.chatsUnit',
    },
    {
        key: 'most_dialogs',
        titleKey: 'analytics.rankingMostDialogs',
        hintKey: 'analytics.rankingMostDialogsHint',
        primary: (r) => String(r.dialog_count ?? 0),
    },
    {
        key: 'best_sla',
        titleKey: 'analytics.rankingSlaBest',
        hintKey: 'analytics.rankingSlaBestHint',
        primary: (r) => fmtPctStatic(r.sla_on_time_percent as number | null | undefined),
    },
    {
        key: 'worst_sla',
        titleKey: 'analytics.rankingSlaWorst',
        hintKey: 'analytics.rankingSlaWorstHint',
        primary: (r) => fmtPctStatic(r.sla_on_time_percent as number | null | undefined),
    },
];

ChartJS.register(
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    DoughnutController,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
);

type FilterOption = { id: number; name: string; department_id?: number | null };
type AnalyticsType = 'dialogs' | 'funnels';

const props = defineProps<{
    filterOptions: {
        departments: FilterOption[];
        employees: FilterOption[];
        sla_seconds: number;
        default_from: string;
        default_to: string;
    };
}>();

const page = usePage();
const { theme } = useTheme();
const { t } = useI18n();
const roles = computed(() => (page.props as { auth?: { user?: { roles?: string[] } } }).auth?.user?.roles || []);
const isEmployee = computed(() => roles.value.includes('employee') && !roles.value.includes('administrator'));
const funnelsModuleEnabled = computed<boolean>(() => Boolean(
    (page.props as { modules?: { funnels?: boolean } }).modules?.funnels ?? true,
));
const analyticsModuleEnabled = computed<boolean>(() => Boolean(
    (page.props as { modules?: { analytics?: boolean } }).modules?.analytics ?? true,
));
const analyticsType = ref<AnalyticsType>('dialogs');

const from = ref(props.filterOptions.default_from.slice(0, 10));
const to = ref(props.filterOptions.default_to.slice(0, 10));
const employeeId = ref<string>('');
const departmentId = ref<string>('');
const status = ref('all');
const channel = ref('all');
const problemPage = ref(1);

const loading = ref(true);
const error = ref<string | null>(null);
const payload = ref<any>(null);

const filteredEmployees = computed(() => {
    if (!departmentId.value) {
        return props.filterOptions.employees;
    }
    const did = Number(departmentId.value);
    return props.filterOptions.employees.filter((e) => e.department_id === did);
});

const contextLabel = computed(() => {
    const dept = departmentId.value
        ? props.filterOptions.departments.find((d) => String(d.id) === departmentId.value)
        : null;
    const emp = employeeId.value ? props.filterOptions.employees.find((e) => String(e.id) === employeeId.value) : null;
    if (!dept && !emp) {
        return t('analytics.sliceAll');
    }
    if (dept && !emp) {
        return t('analytics.sliceDept', { dept: dept.name });
    }
    if (dept && emp) {
        return t('analytics.sliceDeptEmployee', { dept: dept.name, employee: emp.name });
    }
    if (!dept && emp) {
        return t('analytics.sliceEmployee', { employee: emp.name });
    }
    return '';
});

const pageTitle = computed(() => analyticsType.value === 'dialogs' ? t('analytics.dialogsTitle') : t('analytics.funnelsTitle'));
const pageSubtitle = computed(() => analyticsType.value === 'dialogs'
    ? t('analytics.dialogsIntro', { seconds: props.filterOptions.sla_seconds })
    : t('analytics.funnelsIntro'));

const rankingBlocks = computed(() => RANKING_BLOCK_DEFS.map((block) => ({
    ...block,
    title: t(block.titleKey),
    hint: t(block.hintKey),
    primary:
        block.key === 'fastest_avg_response' || block.key === 'slowest_avg_response'
            ? (r: Record<string, unknown>) => fmtSec(r.avg_response_seconds as number | null | undefined)
            : block.key === 'best_sla' || block.key === 'worst_sla'
              ? (r: Record<string, unknown>) => fmtPct(r.sla_on_time_percent as number | null | undefined)
              : block.primary,
    secondary: block.secondaryKey
        ? () => t(block.secondaryKey!)
        : block.secondary,
})));

const rankingsData = computed<Partial<RankingsPayload>>(() => payload.value?.rankings || {});

function rankingRows(key: keyof RankingsPayload): Record<string, unknown>[] {
    const r = rankingsData.value[key];
    return Array.isArray(r) ? (r as Record<string, unknown>[]) : [];
}

/** Один выбранный рейтинг (select отдаёт string) */
const rankingKey = ref<string>('fastest_avg_response');

const currentRankingBlock = computed(() => {
    return rankingBlocks.value.find((b) => b.key === rankingKey.value) ?? rankingBlocks.value[0];
});

function rankingRowsSelected(): Record<string, unknown>[] {
    return rankingRows(rankingKey.value as keyof RankingsPayload);
}

watch(departmentId, () => {
    if (!employeeId.value) {
        return;
    }
    const allowed = filteredEmployees.value.some((e) => String(e.id) === employeeId.value);
    if (!allowed) {
        employeeId.value = '';
    }
});

const periodPreset = ref<'today' | '7d' | '30d' | 'custom'>('7d');

function applyPreset(p: typeof periodPreset.value) {
    periodPreset.value = p;
    const end = new Date();
    const endStr = end.toISOString().slice(0, 10);
    if (p === 'today') {
        from.value = endStr;
        to.value = endStr;
    } else if (p === '7d') {
        const s = new Date();
        s.setDate(s.getDate() - 7);
        from.value = s.toISOString().slice(0, 10);
        to.value = endStr;
    } else if (p === '30d') {
        const s = new Date();
        s.setDate(s.getDate() - 30);
        from.value = s.toISOString().slice(0, 10);
        to.value = endStr;
    }
}

function resetFilters() {
    applyPreset('7d');
    employeeId.value = '';
    departmentId.value = '';
    status.value = 'all';
    channel.value = 'all';
    problemPage.value = 1;
}

let debounceTimer: ReturnType<typeof setTimeout> | null = null;

async function fetchAnalytics() {
    loading.value = true;
    error.value = null;
    try {
        const { data } = analyticsType.value === 'dialogs'
            ? await axios.get(route('api.analytics.dialogs'), {
                params: {
                    from: from.value,
                    to: to.value,
                    employee_id: employeeId.value || undefined,
                    department_id: departmentId.value || undefined,
                    status: status.value,
                    channel: channel.value,
                    page: problemPage.value,
                    per_page: 15,
                },
            })
            : await axios.get(route('api.analytics.funnels'), {
                params: {
                    from: from.value,
                    to: to.value,
                    department_id: departmentId.value || undefined,
                    employee_id: employeeId.value || undefined,
                },
            });
        payload.value = data;
    } catch (e: any) {
        error.value = e.response?.data?.message || e.message || t('analytics.loadError');
        payload.value = null;
    } finally {
        loading.value = false;
    }
}

function debouncedFetch() {
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        fetchAnalytics();
    }, 280);
}

watch([analyticsModuleEnabled, funnelsModuleEnabled], () => {
    if (!funnelsModuleEnabled.value && analyticsType.value === 'funnels') {
        if (analyticsModuleEnabled.value) {
            analyticsType.value = 'dialogs';
        }
    }
    if (!analyticsModuleEnabled.value && analyticsType.value === 'dialogs') {
        if (funnelsModuleEnabled.value) {
            analyticsType.value = 'funnels';
        }
    }
}, { immediate: true });

watch([analyticsType, from, to, employeeId, departmentId, status, channel], () => {
    problemPage.value = 1;
    debouncedFetch();
});

watch(problemPage, () => {
    fetchAnalytics();
});

onMounted(() => {
    fetchAnalytics();
});

function fmtSec(s: number | null | undefined): string {
    if (s === null || s === undefined) return '—';
    if (s < 60) return t('analytics.secondsShort', { count: Math.round(s) });
    if (s < 3600) return t('analytics.minutesShort', { count: Math.round(s / 60) });
    return t('analytics.hoursShort', { count: Number((s / 3600).toFixed(1)) });
}

function fmtPct(p: number | null | undefined): string {
    if (p === null || p === undefined) return '—';
    return `${p}%`;
}

const summary = computed(() => payload.value?.summary || {});
const chartData = computed(() => payload.value?.chart_data || {});
const funnelRows = computed<any[]>(() => payload.value?.funnels || []);
const conversionPayload = computed(() => payload.value?.conversion || null);
const conversionFunnels = computed<any[]>(() => conversionPayload.value?.funnels || []);

function fmtMinutes(m: number | null | undefined): string {
    if (m == null || Number.isNaN(Number(m))) return '—';
    const value = Number(m);
    if (value < 60) return t('analytics.minutesShort', { count: Math.round(value) });
    return t('analytics.hoursShort', { count: Number((value / 60).toFixed(1)) });
}

function fmtHours(h: number | null | undefined): string {
    if (h === null || h === undefined) {
        return '—';
    }
    if (h < 1) {
        return t('analytics.minutesShort', { count: Math.round(h * 60) });
    }

    return t('analytics.hoursShort', { count: Number(h.toFixed(1)) });
}

const lineChartData = computed(() => {
    const rows = chartData.value.dialogs_over_time || [];
    return {
        labels: rows.map((r: any) => r.date),
        datasets: [
            {
                label: t('analytics.chartLabelActivity'),
                data: rows.map((r: any) => r.count),
                borderColor: 'rgb(37, 211, 102)',
                backgroundColor: 'rgba(37, 211, 102, 0.15)',
                tension: 0.25,
                fill: true,
            },
        ],
    };
});

const lineAvgResp = computed(() => {
    const rows = chartData.value.avg_response_by_day || [];
    return {
        labels: rows.map((r: any) => r.date),
        datasets: [
            {
                label: t('analytics.chartLabelResponse'),
                data: rows.map((r: any) => r.avg_seconds ?? 0),
                borderColor: 'rgb(234, 179, 8)',
                backgroundColor: 'rgba(234, 179, 8, 0.12)',
                tension: 0.25,
                fill: true,
            },
        ],
    };
});

const barLoad = computed(() => {
    const rows = chartData.value.load_per_employee || [];
    return {
        labels: rows.map((r: any) => r.name),
        datasets: [
            {
                label: t('analytics.chartLabelDialogs'),
                data: rows.map((r: any) => r.dialogs),
                backgroundColor: 'rgba(59, 130, 246, 0.55)',
            },
        ],
    };
});

const doughnutStatus = computed(() => {
    const d = chartData.value.status_distribution || {};
    return {
        labels: [t('analytics.statusActiveLabel'), t('analytics.statusClosedLabel'), t('analytics.statusWaitingLabel')],
        datasets: [
            {
                data: [d.active || 0, d.closed || 0, d.waiting || 0],
                backgroundColor: ['#01b964', '#94a3b8', '#eab308'],
            },
        ],
    };
});

type ChartKey = 'dialogs' | 'avg_response' | 'load' | 'statuses';

const CHART_BLOCK_DEFS: { key: ChartKey; titleKey: string; hintKey: string }[] = [
    { key: 'dialogs', titleKey: 'analytics.chartActivity', hintKey: 'analytics.chartActivityHint' },
    { key: 'avg_response', titleKey: 'analytics.chartResponseTime', hintKey: 'analytics.chartResponseTimeHint' },
    { key: 'load', titleKey: 'analytics.chartLoad', hintKey: 'analytics.chartLoadHint' },
    { key: 'statuses', titleKey: 'analytics.chartStatuses', hintKey: 'analytics.chartStatusesHint' },
];

const chartBlocks = computed(() => CHART_BLOCK_DEFS.map((block) => ({
    ...block,
    title: t(block.titleKey),
    hint: t(block.hintKey),
})));

const chartKey = ref<ChartKey>('dialogs');

const currentChartBlock = computed(() => {
    return chartBlocks.value.find((block) => block.key === chartKey.value) ?? chartBlocks.value[0];
});

const chartLegendColor = computed(() => (theme.value === 'light' ? '#334155' : '#e2e8f0'));
const chartTickColor = computed(() => (theme.value === 'light' ? '#64748b' : '#94a3b8'));
const chartGridColor = computed(() =>
    theme.value === 'light' ? 'rgba(51, 65, 85, 0.12)' : 'rgba(148, 163, 184, 0.12)',
);

const chartOpts = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    backgroundColor: 'transparent',
    layout: { padding: { top: 4, right: 8, bottom: 4, left: 4 } },
    plugins: {
        legend: {
            position: 'top' as const,
            align: 'start' as const,
            labels: {
                color: chartLegendColor.value,
                boxWidth: 10,
                padding: 14,
                font: { size: 11 },
            },
        },
    },
    scales: {
        x: {
            ticks: { color: chartTickColor.value, maxRotation: 0 },
            grid: { color: chartGridColor.value },
        },
        y: {
            ticks: { color: chartTickColor.value },
            grid: { color: chartGridColor.value },
        },
    },
}));

const doughnutOpts = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    backgroundColor: 'transparent',
    layout: { padding: { top: 8, bottom: 8 } },
    plugins: {
        legend: {
            position: 'bottom' as const,
            labels: {
                color: chartLegendColor.value,
                boxWidth: 10,
                padding: 10,
                font: { size: 11 },
            },
        },
    },
}));

const employeeStats = ref<any[]>([]);
const empSort = ref<{ key: string; dir: 'asc' | 'desc' }>({ key: 'dialog_count', dir: 'desc' });

watch(
    () => payload.value?.employee_stats,
    (rows) => {
        employeeStats.value = rows ? [...rows] : [];
        sortEmp();
    },
    { immediate: true },
);

function sortEmp() {
    const k = empSort.value.key;
    const dir = empSort.value.dir === 'asc' ? 1 : -1;
    employeeStats.value.sort((a: any, b: any) => {
        const va = a[k];
        const vb = b[k];
        if (va === null || va === undefined) return 1;
        if (vb === null || vb === undefined) return -1;
        if (typeof va === 'number' && typeof vb === 'number') return (va - vb) * dir;
        return String(va).localeCompare(String(vb), 'ru') * dir;
    });
}

function toggleEmpSort(key: string) {
    if (empSort.value.key === key) {
        empSort.value.dir = empSort.value.dir === 'asc' ? 'desc' : 'asc';
    } else {
        empSort.value.key = key;
        empSort.value.dir = 'desc';
    }
    sortEmp();
}

const deptStats = ref<any[]>([]);
const deptSort = ref<{ key: string; dir: 'asc' | 'desc' }>({ key: 'dialog_count', dir: 'desc' });

watch(
    () => payload.value?.department_stats,
    (rows) => {
        deptStats.value = rows ? [...rows] : [];
        sortDept();
    },
    { immediate: true },
);

function sortDept() {
    const k = deptSort.value.key;
    const dir = deptSort.value.dir === 'asc' ? 1 : -1;
    deptStats.value.sort((a: any, b: any) => {
        const va = a[k];
        const vb = b[k];
        if (va === null || va === undefined) return 1;
        if (vb === null || vb === undefined) return -1;
        if (typeof va === 'number' && typeof vb === 'number') return (va - vb) * dir;
        return String(va).localeCompare(String(vb), 'ru') * dir;
    });
}

function toggleDeptSort(key: string) {
    if (deptSort.value.key === key) {
        deptSort.value.dir = deptSort.value.dir === 'asc' ? 'desc' : 'asc';
    } else {
        deptSort.value.key = key;
        deptSort.value.dir = 'desc';
    }
    sortDept();
}

const isEmpty = computed(() => {
    if (loading.value || !payload.value) return false;
    if (analyticsType.value === 'funnels') {
        return (summary.value.total_funnels ?? 0) === 0;
    }

    return summary.value.total_dialogs === 0 || summary.value.total_dialogs === undefined;
});

const problematic = computed(() => payload.value?.problematic_chats?.data || []);
const problemMeta = computed(() => payload.value?.problematic_chats?.meta || { total: 0, last_page: 1, current_page: 1 });
</script>

<template>
    <Head :title="pageTitle" />
    <AuthenticatedLayout>
        <div
            class="flex min-h-0 flex-1 flex-col overflow-hidden"
            :style="{ background: 'var(--wa-page-bg)' }"
        >
            <header class="analytics-header shrink-0 border-b px-4 py-5 md:px-8" :style="{ borderColor: 'var(--wa-border)' }">
                <div class="mx-auto max-w-5xl">
                    <p class="text-xs font-medium uppercase tracking-wide text-[var(--wa-text-secondary)]">{{ t('analytics.overview') }}</p>
                    <h1 class="mt-1 text-xl font-semibold tracking-tight text-[var(--wa-text)] md:text-2xl">{{ pageTitle }}</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-relaxed text-[var(--wa-text-secondary)]">
                        {{ pageSubtitle }}
                    </p>
                    <div class="mt-4 inline-flex max-w-full items-center gap-2 rounded-full border px-4 py-2 text-sm" :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel)' }">
                        <span class="text-[var(--wa-text-secondary)]">{{ t('analytics.slice') }}</span>
                        <span class="truncate font-medium text-[var(--wa-text)]">{{ contextLabel }}</span>
                    </div>
                </div>
            </header>

            <div class="wa-scrollbar flex-1 overflow-y-auto px-4 py-6 md:px-8 md:py-8">
                <div class="mx-auto max-w-5xl space-y-8">
                <!-- Filters -->
                <section class="analytics-card rounded-2xl border p-5 md:p-6" :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel)' }">
                    <div class="mb-4 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 class="text-base font-semibold text-[var(--wa-text)]">{{ t('analytics.filtersTitle') }}</h2>
                            <p class="mt-0.5 text-sm text-[var(--wa-text-secondary)]">
                                {{ t('analytics.filtersHint') }}
                            </p>
                        </div>
                        <button type="button" class="analytics-btn-ghost mt-2 shrink-0 sm:mt-0" @click="resetFilters">{{ t('analytics.resetAll') }}</button>
                    </div>
                    <div class="mb-5 flex flex-col gap-2 sm:flex-row sm:items-center">
                        <span class="text-xs text-[var(--wa-text-secondary)]">{{ t('analytics.typeLabel') }}</span>
                        <div class="inline-flex flex-wrap gap-1 rounded-xl p-1" :style="{ background: 'var(--wa-surface-inset)' }">
                            <button
                                v-if="analyticsModuleEnabled"
                                type="button"
                                class="analytics-pill"
                                :class="{ 'analytics-pill-active': analyticsType === 'dialogs' }"
                                @click="analyticsType = 'dialogs'"
                            >
                                {{ t('analytics.typeDialogs') }}
                            </button>
                            <button
                                v-if="funnelsModuleEnabled"
                                type="button"
                                class="analytics-pill"
                                :class="{ 'analytics-pill-active': analyticsType === 'funnels' }"
                                @click="analyticsType = 'funnels'"
                            >
                                {{ t('analytics.typeFunnels') }}
                            </button>
                        </div>
                    </div>
                    <div class="mb-5 flex flex-wrap items-center gap-2">
                        <span class="mr-1 text-xs text-[var(--wa-text-secondary)]">{{ t('analytics.period') }}</span>
                        <div class="inline-flex flex-wrap gap-1 rounded-xl p-1" :style="{ background: 'var(--wa-surface-inset)' }">
                            <button
                                type="button"
                                class="analytics-pill"
                                :class="{ 'analytics-pill-active': periodPreset === 'today' }"
                                @click="applyPreset('today')"
                            >
                                {{ t('analytics.today') }}
                            </button>
                            <button
                                type="button"
                                class="analytics-pill"
                                :class="{ 'analytics-pill-active': periodPreset === '7d' }"
                                @click="applyPreset('7d')"
                            >
                                {{ t('analytics.days7') }}
                            </button>
                            <button
                                type="button"
                                class="analytics-pill"
                                :class="{ 'analytics-pill-active': periodPreset === '30d' }"
                                @click="applyPreset('30d')"
                            >
                                {{ t('analytics.days30') }}
                            </button>
                        </div>
                        <span class="ml-2 text-xs text-[var(--wa-text-secondary)]">{{ t('analytics.customDates') }}</span>
                        <input v-model="from" type="date" class="analytics-input" @focus="periodPreset = 'custom'" />
                        <span class="text-[var(--wa-text-secondary)]">—</span>
                        <input v-model="to" type="date" class="analytics-input" @focus="periodPreset = 'custom'" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2" :class="analyticsType === 'dialogs' ? 'lg:grid-cols-4' : 'lg:grid-cols-2'">
                        <label class="analytics-field">
                            <span class="analytics-field-label">{{ t('analytics.department') }}</span>
                            <select v-model="departmentId" class="analytics-input w-full" :disabled="isEmployee">
                                <option value="">{{ t('analytics.allDepartments') }}</option>
                                <option v-for="d in filterOptions.departments" :key="d.id" :value="String(d.id)">{{ d.name }}</option>
                            </select>
                        </label>
                        <label class="analytics-field">
                            <span class="analytics-field-label">{{ t('analytics.employee') }}</span>
                            <select v-model="employeeId" class="analytics-input w-full" :disabled="isEmployee">
                                <option value="">{{ departmentId ? t('analytics.allInDepartment') : t('analytics.allEmployees') }}</option>
                                <option v-for="e in filteredEmployees" :key="e.id" :value="String(e.id)">{{ e.name }}</option>
                            </select>
                        </label>
                        <label v-if="analyticsType === 'dialogs'" class="analytics-field">
                            <span class="analytics-field-label">{{ t('analytics.chatStatus') }}</span>
                            <select v-model="status" class="analytics-input w-full">
                                <option value="all">{{ t('analytics.statusAll') }}</option>
                                <option value="active">{{ t('analytics.statusActive') }}</option>
                                <option value="closed">{{ t('analytics.statusClosed') }}</option>
                                <option value="waiting">{{ t('analytics.statusWaiting') }}</option>
                            </select>
                        </label>
                        <label v-if="analyticsType === 'dialogs'" class="analytics-field">
                            <span class="analytics-field-label">{{ t('analytics.channel') }}</span>
                            <select v-model="channel" class="analytics-input w-full">
                                <option value="all">{{ t('analytics.channelAll') }}</option>
                                <option value="whatsapp">{{ t('analytics.channelWhatsapp') }}</option>
                                <option value="telegram">Telegram</option>
                                <option value="site">{{ t('analytics.channelSite') }}</option>
                            </select>
                        </label>
                    </div>
                </section>

                <div
                    v-if="error"
                    class="rounded-xl border px-4 py-3 text-sm leading-relaxed"
                    :style="{
                        borderColor: 'color-mix(in srgb, var(--wa-danger) 40%, transparent)',
                        background: 'color-mix(in srgb, var(--wa-danger) 10%, transparent)',
                        color: 'var(--wa-danger)',
                    }"
                >
                    {{ error }}
                </div>

                <!-- Skeleton -->
                <div v-if="loading" class="space-y-6 animate-pulse">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div v-for="n in 6" :key="n" class="h-28 rounded-2xl bg-[var(--wa-panel-header)]" />
                    </div>
                    <div class="h-48 rounded-2xl bg-[var(--wa-panel-header)]" />
                </div>

                <template v-else-if="payload">
                    <template v-if="analyticsType === 'funnels'">
                        <div
                            v-if="isEmpty"
                            class="rounded-2xl border px-6 py-16 text-center"
                            :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel)' }"
                        >
                            <p class="text-base font-medium text-[var(--wa-text)]">{{ t('analytics.noFunnels') }}</p>
                            <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-[var(--wa-text-secondary)]">
                                {{ t('analytics.noFunnelsHint') }}
                            </p>
                        </div>

                        <template v-else>
                            <section>
                                <h2 class="mb-4 text-base font-semibold text-[var(--wa-text)]">{{ t('analytics.kpiTitle') }}</h2>
                                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                    <div class="kpi-card" style="border-color: var(--wa-border)">
                                        <div class="kpi-label">{{ t('analytics.kpiTotalFunnels') }}</div>
                                        <div class="kpi-value">{{ summary.total_funnels ?? 0 }}</div>
                                    </div>
                                    <div class="kpi-card" style="border-color: rgba(34, 197, 94, 0.35)">
                                        <div class="kpi-label">{{ t('analytics.kpiActive') }}</div>
                                        <div class="kpi-value">{{ summary.active_funnels ?? 0 }}</div>
                                    </div>
                                    <div class="kpi-card" style="border-color: rgba(59, 130, 246, 0.45)">
                                        <div class="kpi-label">{{ t('analytics.kpiDeptLinked') }}</div>
                                        <div class="kpi-value">{{ summary.connected_funnels ?? 0 }}</div>
                                    </div>
                                    <div class="kpi-card" style="border-color: var(--wa-border)">
                                        <div class="kpi-label">{{ t('analytics.kpiTotalStages') }}</div>
                                        <div class="kpi-value">{{ summary.total_stages ?? 0 }}</div>
                                    </div>
                                    <div class="kpi-card" style="border-color: rgba(168, 85, 247, 0.45)">
                                        <div class="kpi-label">{{ t('analytics.kpiSelectedStages') }}</div>
                                        <div class="kpi-value">{{ summary.selected_stages ?? 0 }}</div>
                                    </div>
                                    <div class="kpi-card" style="border-color: rgba(234, 179, 8, 0.4)">
                                        <div class="kpi-label">{{ t('analytics.kpiStageCoverage') }}</div>
                                        <div class="kpi-value">{{ fmtPct(summary.stage_coverage_percent) }}</div>
                                    </div>
                                    <div class="kpi-card" style="border-color: var(--wa-border)">
                                        <div class="kpi-label">{{ t('analytics.kpiDepartments') }}</div>
                                        <div class="kpi-value">{{ summary.departments_in_scope ?? 0 }}</div>
                                    </div>
                                    <div class="kpi-card" style="border-color: rgba(34, 197, 94, 0.35)">
                                        <div class="kpi-label">{{ t('analytics.kpiChats') }}</div>
                                        <div class="kpi-value">{{ summary.tracked_chats ?? 0 }}</div>
                                    </div>
                                    <div class="kpi-card" style="border-color: rgba(59, 130, 246, 0.45)">
                                        <div class="kpi-label">{{ t('analytics.kpiTransitions') }}</div>
                                        <div class="kpi-value">{{ summary.total_transitions ?? 0 }}</div>
                                    </div>
                                </div>
                            </section>

                            <section
                                v-if="conversionFunnels.length > 0"
                                class="rounded-2xl border p-5 md:p-6"
                                :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel)' }"
                            >
                                <h2 class="text-base font-semibold text-[var(--wa-text)]">{{ t('analytics.conversionTitle') }}</h2>
                                <p class="mt-1 text-sm text-[var(--wa-text-secondary)]">
                                    {{ t('analytics.conversionHint') }}
                                </p>
                                <div class="mt-5 space-y-6">
                                    <article
                                        v-for="funnel in conversionFunnels"
                                        :key="`conv-${funnel.id}`"
                                        class="rounded-xl border p-4"
                                        :style="{ borderColor: 'var(--wa-border)' }"
                                    >
                                        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                                            <div class="flex items-center gap-2">
                                                <span class="h-2.5 w-2.5 rounded-full" :style="{ background: funnel.color }"></span>
                                                <h3 class="font-medium text-[var(--wa-text)]">{{ funnel.name }}</h3>
                                            </div>
                                            <span
                                                v-if="funnel.overall_conversion_percent != null"
                                                class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                                :style="{ background: 'var(--wa-selected)', color: 'var(--wa-accent)' }"
                                            >
                                                {{ t('analytics.overallConversion', { percent: fmtPct(funnel.overall_conversion_percent) }) }}
                                            </span>
                                        </div>
                                        <div class="overflow-x-auto">
                                            <table class="w-full min-w-[960px] text-left text-sm">
                                                <thead>
                                                    <tr class="text-[var(--wa-text-secondary)]">
                                                        <th class="px-2 py-2">{{ t('analytics.colStage') }}</th>
                                                        <th class="px-2 py-2">{{ t('analytics.colNow') }}</th>
                                                        <th class="px-2 py-2">{{ t('analytics.colEntered') }}</th>
                                                        <th class="px-2 py-2">{{ t('analytics.colForward') }}</th>
                                                        <th class="px-2 py-2">{{ t('analytics.colConversion') }}</th>
                                                        <th class="px-2 py-2">{{ t('analytics.colDrop') }}</th>
                                                        <th class="px-2 py-2">{{ t('analytics.colOnStage') }}</th>
                                                        <th class="px-2 py-2">{{ t('analytics.colAiResponse') }}</th>
                                                        <th class="px-2 py-2">{{ t('analytics.colManagerResponse') }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr
                                                        v-for="stage in funnel.stages"
                                                        :key="stage.id"
                                                        class="border-t"
                                                        :style="{ borderColor: 'var(--wa-border)' }"
                                                    >
                                                        <td class="px-2 py-2">
                                                            <div class="flex items-center gap-2">
                                                                <span class="h-2 w-2 rounded-full" :style="{ background: stage.color }"></span>
                                                                <span class="text-[var(--wa-text)]">{{ stage.name }}</span>
                                                            </div>
                                                        </td>
                                                        <td class="px-2 py-2 text-[var(--wa-text)]">{{ stage.current_chats }}</td>
                                                        <td class="px-2 py-2 text-[var(--wa-text)]">{{ stage.entries }}</td>
                                                        <td class="px-2 py-2 text-[var(--wa-text)]">{{ stage.is_final ? '—' : stage.forward_exits }}</td>
                                                        <td class="px-2 py-2 text-[var(--wa-text)]">{{ stage.is_final ? '—' : fmtPct(stage.conversion_percent) }}</td>
                                                        <td class="px-2 py-2 text-[var(--wa-text)]">{{ stage.is_final ? '—' : stage.drop_off }}</td>
                                                        <td class="px-2 py-2 text-[var(--wa-text)]">{{ fmtHours(stage.avg_hours_on_stage) }}</td>
                                                        <td class="px-2 py-2 text-[var(--wa-text)]" :title="stage.response_samples_ai ? t('analytics.sampleTitle', { sample: stage.response_samples_ai }) : ''">
                                                            {{ fmtMinutes(stage.avg_response_minutes_ai) }}
                                                        </td>
                                                        <td class="px-2 py-2 text-[var(--wa-text)]" :title="stage.response_samples_manager ? t('analytics.sampleTitle', { sample: stage.response_samples_manager }) : ''">
                                                            {{ fmtMinutes(stage.avg_response_minutes_manager) }}
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="mt-4 flex items-end gap-1">
                                            <div
                                                v-for="stage in funnel.stages"
                                                :key="`bar-${stage.id}`"
                                                class="flex-1 rounded-t"
                                                :style="{
                                                    background: stage.color,
                                                    height: `${Math.max(8, Math.min(72, (stage.entries || stage.current_chats) * 6))}px`,
                                                    opacity: (stage.entries || stage.current_chats) > 0 ? 1 : 0.25,
                                                }"
                                                :title="t('analytics.entriesTooltip', { name: stage.name, count: stage.entries })"
                                            />
                                        </div>
                                    </article>
                                </div>
                            </section>

                            <section
                                class="overflow-hidden rounded-2xl border shadow-sm"
                                :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel)' }"
                            >
                                <h3 class="border-b px-5 py-4 text-base font-semibold text-[var(--wa-text)]" :style="{ borderColor: 'var(--wa-border)' }">
                                    {{ t('analytics.coverageTitle') }}
                                </h3>
                                <div class="overflow-x-auto">
                                    <table class="w-full min-w-[900px] text-left text-sm">
                                        <thead>
                                            <tr class="text-[var(--wa-text-secondary)]">
                                                <th class="px-3 py-2">{{ t('analytics.colFunnel') }}</th>
                                                <th class="px-3 py-2">{{ t('analytics.colDepartments') }}</th>
                                                <th class="px-3 py-2">{{ t('analytics.colStages') }}</th>
                                                <th class="px-3 py-2">{{ t('analytics.colSelected') }}</th>
                                                <th class="px-3 py-2">{{ t('analytics.colCoverage') }}</th>
                                                <th class="px-3 py-2">{{ t('analytics.colStatus') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr
                                                v-for="funnel in funnelRows"
                                                :key="funnel.id"
                                                class="border-t text-[var(--wa-text)]"
                                                :style="{ borderColor: 'var(--wa-border)' }"
                                            >
                                                <td class="px-3 py-3">
                                                    <div class="flex items-center gap-2">
                                                        <span class="h-2.5 w-2.5 rounded-full" :style="{ background: funnel.color }"></span>
                                                        <span class="font-medium">{{ funnel.name }}</span>
                                                    </div>
                                                    <div v-if="funnel.description" class="mt-0.5 text-xs text-[var(--wa-text-secondary)]">
                                                        {{ funnel.description }}
                                                    </div>
                                                </td>
                                                <td class="px-3 py-3">
                                                    <div v-if="funnel.departments?.length" class="flex flex-wrap gap-1">
                                                        <span
                                                            v-for="department in funnel.departments"
                                                            :key="department.id"
                                                            class="rounded-full px-2 py-0.5 text-xs"
                                                            :style="{ background: 'var(--wa-selected)', color: 'var(--wa-text)' }"
                                                        >
                                                            {{ department.name }}
                                                        </span>
                                                    </div>
                                                    <span v-else class="text-[var(--wa-text-secondary)]">—</span>
                                                </td>
                                                <td class="px-3 py-3">{{ funnel.stages_count }}</td>
                                                <td class="px-3 py-3">{{ funnel.selected_stages_count }}</td>
                                                <td class="px-3 py-3">{{ fmtPct(funnel.coverage_percent) }}</td>
                                                <td class="px-3 py-3">
                                                    <span
                                                        class="rounded px-2 py-0.5 text-xs"
                                                        :class="funnel.is_active ? 'text-[var(--wa-accent)]' : 'text-red-400'"
                                                        :style="{ background: 'var(--wa-selected)' }"
                                                    >
                                                        {{ funnel.is_active ? t('analytics.funnelActive') : t('analytics.funnelInactive') }}
                                                    </span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </section>

                            <section
                                class="rounded-2xl border p-5 md:p-6"
                                :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel)' }"
                            >
                                <h3 class="text-base font-semibold text-[var(--wa-text)]">{{ t('analytics.stagesByFunnel') }}</h3>
                                <p class="mt-1 text-sm text-[var(--wa-text-secondary)]">
                                    {{ t('analytics.stagesByFunnelHint') }}
                                </p>
                                <div class="mt-4 space-y-4">
                                    <div v-for="funnel in funnelRows" :key="`stages-${funnel.id}`" class="rounded-xl border p-4" :style="{ borderColor: 'var(--wa-border)' }">
                                        <div class="mb-3 flex items-center gap-2">
                                            <span class="h-2.5 w-2.5 rounded-full" :style="{ background: funnel.color }"></span>
                                            <span class="font-medium text-[var(--wa-text)]">{{ funnel.name }}</span>
                                        </div>
                                        <div v-if="funnel.stages?.length" class="flex flex-wrap gap-2">
                                            <span
                                                v-for="stage in funnel.stages"
                                                :key="stage.id"
                                                class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                                                :style="{
                                                    borderColor: stage.selected ? 'var(--wa-accent)' : 'var(--wa-border)',
                                                    background: stage.selected ? 'var(--wa-selected)' : 'transparent',
                                                    color: 'var(--wa-text)',
                                                    opacity: stage.is_active ? 1 : 0.55,
                                                }"
                                            >
                                                <span class="h-1.5 w-1.5 rounded-full" :style="{ background: stage.color }"></span>
                                                {{ stage.name }}
                                            </span>
                                        </div>
                                        <p v-else class="text-sm text-[var(--wa-text-secondary)]">{{ t('analytics.noStages') }}</p>
                                    </div>
                                </div>
                            </section>
                        </template>
                    </template>

                    <template v-else>
                    <!-- Empty -->
                    <div
                        v-if="isEmpty"
                        class="rounded-2xl border px-6 py-16 text-center"
                        :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel)' }"
                    >
                        <p class="text-base font-medium text-[var(--wa-text)]">{{ t('analytics.emptyTitle') }}</p>
                        <p class="mx-auto mt-2 max-w-md text-sm leading-relaxed text-[var(--wa-text-secondary)]">
                            {{ t('analytics.emptyHint') }}
                        </p>
                    </div>

                    <template v-else>
                        <!-- KPI -->
                        <section>
                            <h2 class="mb-4 text-base font-semibold text-[var(--wa-text)]">{{ t('analytics.kpiTitle') }}</h2>
                            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                            <div class="kpi-card" style="border-color: var(--wa-border)">
                                <div class="kpi-label">{{ t('analytics.kpiTotalDialogs') }}</div>
                                <div class="kpi-value">{{ summary.total_dialogs ?? 0 }}</div>
                            </div>
                            <div class="kpi-card" style="border-color: rgba(34, 197, 94, 0.35)">
                                <div class="kpi-label">{{ t('analytics.kpiActive') }}</div>
                                <div class="kpi-value">{{ summary.active_dialogs ?? 0 }}</div>
                            </div>
                            <div class="kpi-card" style="border-color: rgba(34, 197, 94, 0.35)">
                                <div class="kpi-label">{{ t('analytics.kpiAvgFirstResponse') }}</div>
                                <div class="kpi-value">{{ fmtSec(summary.avg_first_response_seconds) }}</div>
                            </div>
                            <div class="kpi-card" style="border-color: var(--wa-border)">
                                <div class="kpi-label">{{ t('analytics.kpiAvgResponse') }}</div>
                                <div class="kpi-value">{{ fmtSec(summary.avg_response_seconds) }}</div>
                            </div>
                            <div class="kpi-card" style="border-color: rgba(234, 179, 8, 0.4)">
                                <div class="kpi-label">{{ t('analytics.kpiMaxWait') }}</div>
                                <div class="kpi-value">{{ fmtSec(summary.max_client_wait_seconds) }}</div>
                            </div>
                            <div class="kpi-card" style="border-color: rgba(239, 68, 68, 0.45)">
                                <div class="kpi-label">{{ t('analytics.kpiUnanswered') }}</div>
                                <div class="kpi-value">{{ summary.unanswered_dialogs ?? 0 }}</div>
                            </div>
                            <div class="kpi-card" style="border-color: var(--wa-border)">
                                <div class="kpi-label">{{ t('analytics.kpiIdleToNew') }}</div>
                                <div class="kpi-value">{{ fmtSec(summary.avg_idle_before_new_chat_seconds) }}</div>
                            </div>
                            <div class="kpi-card" style="border-color: var(--wa-border)">
                                <div class="kpi-label">{{ t('analytics.kpiAvgClose') }}</div>
                                <div class="kpi-value">{{ fmtSec(summary.avg_time_to_close_seconds) }}</div>
                            </div>
                            <div class="kpi-card" style="border-color: rgba(239, 68, 68, 0.45)">
                                <div class="kpi-label">{{ t('analytics.kpiOverdue') }}</div>
                                <div class="kpi-value">{{ fmtPct(summary.overdue_response_percent) }}</div>
                            </div>
                            <div class="kpi-card" style="border-color: var(--wa-border)">
                                <div class="kpi-label">{{ t('analytics.kpiDialogsPerEmployee') }}</div>
                                <div class="kpi-value">{{
                                    summary.dialogs_per_staff_member != null ? String(summary.dialogs_per_staff_member) : '—'
                                }}</div>
                            </div>
                            </div>
                        </section>

                        <!-- Rankings: один выбор -->
                        <section class="analytics-card rounded-2xl border p-5 md:p-6" :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel)' }">
                            <h2 class="text-base font-semibold text-[var(--wa-text)]">{{ t('analytics.employeeRankingTitle') }}</h2>
                            <p class="mt-1 text-sm leading-relaxed text-[var(--wa-text-secondary)]">
                                {{ t('analytics.employeeRankingHint') }}
                            </p>
                            <div class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-start">
                                <label class="analytics-field min-w-[min(100%,280px)] flex-1 sm:max-w-sm">
                                    <span class="analytics-field-label">{{ t('analytics.whatToShow') }}</span>
                                    <select v-model="rankingKey" class="analytics-input w-full text-[var(--wa-text)]">
                                        <option v-for="b in rankingBlocks" :key="b.key" :value="b.key">{{ b.title }}</option>
                                    </select>
                                </label>
                            </div>
                            <p class="mt-3 text-sm italic text-[var(--wa-text-secondary)]">{{ currentRankingBlock.hint }}</p>
                            <ul class="mt-4 divide-y rounded-xl border text-sm" :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-surface-inset)' }">
                                <li
                                    v-for="(row, idx) in rankingRowsSelected()"
                                    :key="String(row.user_id) + '-' + idx"
                                    class="flex items-center justify-between gap-3 px-4 py-3 transition hover:bg-[var(--wa-selected)]/30"
                                    :style="{ borderColor: 'var(--wa-border)' }"
                                >
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                                            :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-text-secondary)' }"
                                            >{{ idx + 1 }}</span
                                        >
                                        <span class="truncate font-medium text-[var(--wa-text)]">{{ row.name }}</span>
                                    </div>
                                    <span class="shrink-0 tabular-nums text-[var(--wa-text)]">
                                        <span class="font-semibold">{{ currentRankingBlock.primary(row) }}</span>
                                        <span v-if="currentRankingBlock.secondary" class="ml-1 text-xs font-normal text-[var(--wa-text-secondary)]">
                                            {{ currentRankingBlock.secondary(row) }}
                                        </span>
                                    </span>
                                </li>
                                <li v-if="rankingRowsSelected().length === 0" class="px-4 py-8 text-center text-sm text-[var(--wa-text-secondary)]">
                                    {{ t('analytics.noRankingData') }}
                                </li>
                            </ul>
                        </section>

                        <!-- Charts -->
                        <section class="analytics-card rounded-2xl border p-5 md:p-6" :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel)' }">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h2 class="text-base font-semibold text-[var(--wa-text)]">{{ t('analytics.chartTitle') }}</h2>
                                    <p class="mt-1 text-sm leading-relaxed text-[var(--wa-text-secondary)]">
                                        {{ t('analytics.chartHint') }}
                                    </p>
                                </div>
                                <label class="analytics-field min-w-[min(100%,260px)]">
                                    <span class="analytics-field-label">{{ t('analytics.whatToShow') }}</span>
                                    <select v-model="chartKey" class="analytics-input w-full">
                                        <option v-for="block in chartBlocks" :key="block.key" :value="block.key">{{ block.title }}</option>
                                    </select>
                                </label>
                            </div>

                            <div class="mt-4 rounded-xl border px-4 py-3" :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-surface-inset)' }">
                                <div class="mb-3">
                                    <h3 class="text-sm font-semibold text-[var(--wa-text)]">{{ currentChartBlock.title }}</h3>
                                    <p class="mt-1 text-xs leading-relaxed text-[var(--wa-text-secondary)]">{{ currentChartBlock.hint }}</p>
                                </div>
                                <div class="h-[220px] sm:h-[240px]">
                                    <Line v-if="chartKey === 'dialogs'" :data="lineChartData" :options="chartOpts" />
                                    <Line v-else-if="chartKey === 'avg_response'" :data="lineAvgResp" :options="chartOpts" />
                                    <Bar v-else-if="chartKey === 'load'" :data="barLoad" :options="{ ...chartOpts, indexAxis: 'y' }" />
                                    <Doughnut v-else :data="doughnutStatus" :options="doughnutOpts" />
                                </div>
                            </div>
                        </section>

                        <!-- Employee table -->
                        <section
                            class="overflow-hidden rounded-2xl border shadow-sm"
                            :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel)' }"
                        >
                            <h3 class="border-b px-5 py-4 text-base font-semibold text-[var(--wa-text)]" :style="{ borderColor: 'var(--wa-border)' }">
                                {{ t('analytics.employeesSection') }}
                            </h3>
                            <table class="w-full min-w-[800px] text-left text-sm">
                                <thead>
                                    <tr class="text-[var(--wa-text-secondary)]">
                                        <th class="cursor-pointer px-3 py-2" @click="toggleEmpSort('name')">{{ t('analytics.colEmployee') }}</th>
                                        <th class="cursor-pointer px-3 py-2" @click="toggleEmpSort('dialog_count')">{{ t('analytics.colDialogs') }}</th>
                                        <th class="cursor-pointer px-3 py-2" @click="toggleEmpSort('avg_response_seconds')">{{ t('analytics.colAvgResponse') }}</th>
                                        <th class="cursor-pointer px-3 py-2" @click="toggleEmpSort('max_response_seconds')">{{ t('analytics.colMaxResponse') }}</th>
                                        <th class="cursor-pointer px-3 py-2" @click="toggleEmpSort('unanswered_dialogs')">{{ t('analytics.kpiUnanswered') }}</th>
                                        <th class="cursor-pointer px-3 py-2" @click="toggleEmpSort('closed_dialogs')">{{ t('analytics.colClosed') }}</th>
                                        <th class="px-3 py-2">{{ t('analytics.colScore') }}</th>
                                        <th class="cursor-pointer px-3 py-2" @click="toggleEmpSort('sla_on_time_percent')">SLA %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in employeeStats"
                                        :key="row.user_id"
                                        class="border-t text-[var(--wa-text)]"
                                        :style="{ borderColor: 'var(--wa-border)' }"
                                    >
                                        <td class="px-3 py-2">{{ row.name }}</td>
                                        <td class="px-3 py-2">{{ row.dialog_count }}</td>
                                        <td class="px-3 py-2">{{ fmtSec(row.avg_response_seconds) }}</td>
                                        <td class="px-3 py-2">{{ fmtSec(row.max_response_seconds) }}</td>
                                        <td class="px-3 py-2">{{ row.unanswered_dialogs }}</td>
                                        <td class="px-3 py-2">{{ row.closed_dialogs }}</td>
                                        <td class="px-3 py-2 text-[var(--wa-text-secondary)]">—</td>
                                        <td class="px-3 py-2">{{ fmtPct(row.sla_on_time_percent) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </section>

                        <!-- Departments -->
                        <section
                            class="overflow-hidden rounded-2xl border shadow-sm"
                            :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel)' }"
                        >
                            <h3 class="border-b px-5 py-4 text-base font-semibold text-[var(--wa-text)]" :style="{ borderColor: 'var(--wa-border)' }">
                                {{ t('analytics.departmentsSection') }}
                            </h3>
                            <table class="w-full min-w-[720px] text-left text-sm">
                                <thead>
                                    <tr class="text-[var(--wa-text-secondary)]">
                                        <th class="cursor-pointer px-3 py-2" @click="toggleDeptSort('name')">{{ t('analytics.colDepartment') }}</th>
                                        <th class="cursor-pointer px-3 py-2" @click="toggleDeptSort('dialog_count')">{{ t('analytics.colDialogs') }}</th>
                                        <th class="cursor-pointer px-3 py-2" @click="toggleDeptSort('avg_response_seconds')">{{ t('analytics.colAvgResponse') }}</th>
                                        <th class="cursor-pointer px-3 py-2" @click="toggleDeptSort('max_delay_seconds')">{{ t('analytics.colMaxDelay') }}</th>
                                        <th class="cursor-pointer px-3 py-2" @click="toggleDeptSort('active_dialogs')">{{ t('analytics.kpiActive') }}</th>
                                        <th class="cursor-pointer px-3 py-2" @click="toggleDeptSort('overdue_dialogs')">{{ t('analytics.colOverdue') }}</th>
                                        <th class="px-3 py-2">{{ t('analytics.colBestEmployee') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in deptStats"
                                        :key="row.department_id"
                                        class="border-t text-[var(--wa-text)]"
                                        :style="{ borderColor: 'var(--wa-border)' }"
                                    >
                                        <td class="px-3 py-2">{{ row.name }}</td>
                                        <td class="px-3 py-2">{{ row.dialog_count }}</td>
                                        <td class="px-3 py-2">{{ fmtSec(row.avg_response_seconds) }}</td>
                                        <td class="px-3 py-2">{{ fmtSec(row.max_delay_seconds) }}</td>
                                        <td class="px-3 py-2">{{ row.active_dialogs }}</td>
                                        <td class="px-3 py-2">{{ row.overdue_dialogs }}</td>
                                        <td class="px-3 py-2">{{ row.best_employee_name || '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </section>

                        <!-- Problematic -->
                        <section
                            class="overflow-hidden rounded-2xl border shadow-sm"
                            :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel)' }"
                        >
                            <h3 class="border-b px-5 py-4 text-base font-semibold text-[var(--wa-text)]" :style="{ borderColor: 'var(--wa-border)' }">
                                {{ t('analytics.problemDialogsTitle') }}
                            </h3>
                            <p class="border-b px-5 py-2 text-xs text-[var(--wa-text-secondary)]" :style="{ borderColor: 'var(--wa-border)' }">
                                {{ t('analytics.problemDialogsHint') }}
                            </p>
                            <div class="overflow-x-auto">
                                <table class="w-full min-w-[900px] text-left text-sm">
                                    <thead>
                                        <tr class="text-[var(--wa-text-secondary)]">
                                            <th class="px-3 py-2">{{ t('analytics.colClient') }}</th>
                                            <th class="px-3 py-2">{{ t('analytics.colEmployee') }}</th>
                                            <th class="px-3 py-2">{{ t('analytics.colDepartment') }}</th>
                                            <th class="px-3 py-2">{{ t('analytics.colLastFromClient') }}</th>
                                            <th class="px-3 py-2">{{ t('analytics.colWait') }}</th>
                                            <th class="px-3 py-2">{{ t('analytics.colStatus') }}</th>
                                            <th class="px-3 py-2"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="row in problematic"
                                            :key="row.chat_id"
                                            class="border-t"
                                            :style="{ borderColor: 'var(--wa-border)' }"
                                        >
                                            <td class="px-3 py-2 text-[var(--wa-text)]">
                                                {{ row.client_label }}
                                                <span v-if="row.client_phone" class="block text-xs text-[var(--wa-text-secondary)]">{{
                                                    row.client_phone
                                                }}</span>
                                            </td>
                                            <td class="px-3 py-2">{{ row.assignee_name || '—' }}</td>
                                            <td class="px-3 py-2">{{ row.department_name || '—' }}</td>
                                            <td class="px-3 py-2 text-xs text-[var(--wa-text-secondary)]">{{ row.last_client_message_at || '—' }}</td>
                                            <td class="px-3 py-2 font-medium" :style="{ color: 'var(--wa-metric-warn)' }">{{ fmtSec(row.wait_seconds) }}</td>
                                            <td class="px-3 py-2">
                                                <span class="rounded px-2 py-0.5 text-xs" :style="{ background: 'var(--wa-selected)' }">{{
                                                    row.status
                                                }}</span>
                                            </td>
                                            <td class="px-3 py-2">
                                                <a
                                                    :href="row.open_url"
                                                    class="text-sm underline"
                                                    style="color: var(--wa-accent)"
                                                    >{{ t('analytics.openChat') }}</a
                                                >
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div
                                v-if="problemMeta.total > 0"
                                class="flex items-center justify-between border-t px-4 py-3 text-sm"
                                :style="{ borderColor: 'var(--wa-border)' }"
                            >
                                <span class="text-[var(--wa-text-secondary)]">{{ t('analytics.problemTotal', { total: problemMeta.total }) }}</span>
                                <div class="flex gap-2">
                                    <button
                                        type="button"
                                        class="analytics-btn-ghost px-4 py-2 text-sm"
                                        :disabled="problemPage <= 1"
                                        :class="{ 'opacity-40': problemPage <= 1 }"
                                        @click="problemPage--"
                                    >
                                        {{ t('analytics.paginationBack') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="analytics-btn-ghost px-4 py-2 text-sm"
                                        :disabled="problemPage >= problemMeta.last_page"
                                        :class="{ 'opacity-40': problemPage >= problemMeta.last_page }"
                                        @click="problemPage++"
                                    >
                                        {{ t('analytics.paginationForward') }}
                                    </button>
                                </div>
                            </div>
                        </section>
                    </template>
                    </template>
                </template>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.analytics-header {
    background: linear-gradient(180deg, var(--wa-panel) 0%, var(--wa-page-bg) 100%);
}

.analytics-card {
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06);
}

.analytics-pill {
    border-radius: 0.625rem;
    padding: 0.35rem 0.85rem;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--wa-text-secondary);
    transition:
        background 0.15s ease,
        color 0.15s ease;
}

.analytics-pill:hover {
    color: var(--wa-text);
    background: var(--wa-selected);
}

.analytics-pill-active {
    color: var(--wa-text);
    background: var(--wa-selected);
    box-shadow: 0 0 0 1px var(--wa-border-strong);
}

.analytics-field {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.analytics-field-label {
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--wa-text-secondary);
}

.analytics-btn-ghost {
    border-radius: 0.625rem;
    border: 1px solid var(--wa-border);
    background: transparent;
    color: var(--wa-text-secondary);
    font-weight: 500;
    transition:
        background 0.15s ease,
        color 0.15s ease,
        border-color 0.15s ease;
}

.analytics-btn-ghost:hover:not(:disabled) {
    color: var(--wa-text);
    background: var(--wa-selected);
    border-color: var(--wa-control-rim);
    box-shadow: var(--wa-control-rim-shadow);
}

.analytics-btn-ghost:disabled {
    cursor: not-allowed;
}

.kpi-card {
    border-radius: 0.75rem;
    border-width: 1px;
    padding: 1rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    background: var(--wa-panel);
}
.kpi-label {
    font-size: 0.75rem;
    color: var(--wa-text-secondary);
}
.kpi-value {
    margin-top: 0.25rem;
    font-size: 1.25rem;
    font-weight: 600;
    letter-spacing: -0.02em;
    color: var(--wa-text);
}
.analytics-input {
    border-radius: 0.5rem;
    border: 1px solid var(--wa-control-rim);
    box-shadow: var(--wa-control-rim-shadow);
    background: var(--wa-surface-inset);
    color: var(--wa-text);
    padding: 0.4rem 0.65rem;
    font-size: 0.875rem;
}
.analytics-input:focus {
    outline: none;
    border-color: var(--wa-accent);
}
.analytics-input:disabled {
    opacity: 0.55;
}
</style>
