import { useTheme } from '@/composables/useTheme';
import { useI18n } from '@/composables/useI18n';
import axios from 'axios';
import { computed, onMounted, ref, watch, type Ref } from 'vue';
import type {
    AnalyticsPayload,
    AnalyticsType,
    ChartData,
    DepartmentStatRow,
    DialogAnalyticsPayload,
    DialogsTab,
    EmployeeStatRow,
    FilterOptions,
    FunnelAnalyticsPayload,
    FunnelsTab,
    RankingBlockDef,
    RankingsPayload,
} from './types';

export const RANKING_BLOCK_DEFS: RankingBlockDef[] = [
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

function fmtPctStatic(p: number | null | undefined): string {
    if (p === null || p === undefined) {
        return '—';
    }

    return `${p}%`;
}

export function useAnalyticsData(
    filterOptions: FilterOptions,
    analyticsModuleEnabled: Ref<boolean>,
    funnelsModuleEnabled: Ref<boolean>,
) {
    const { theme } = useTheme();
    const { t } = useI18n();

    const analyticsType = ref<AnalyticsType>('dialogs');
    const dialogsTab = ref<DialogsTab>('overview');
    const funnelsTab = ref<FunnelsTab>('overview');

    const from = ref(filterOptions.default_from.slice(0, 10));
    const to = ref(filterOptions.default_to.slice(0, 10));
    const employeeId = ref('');
    const departmentId = ref('');
    const status = ref('all');
    const channel = ref('all');
    const problemPage = ref(1);
    const periodPreset = ref<'today' | '7d' | '30d' | 'custom'>('7d');

    const loading = ref(true);
    const error = ref<string | null>(null);
    const payload = ref<AnalyticsPayload | null>(null);

    const employeeStats = ref<EmployeeStatRow[]>([]);
    const deptStats = ref<DepartmentStatRow[]>([]);
    const empSort = ref<{ key: string; dir: 'asc' | 'desc' }>({ key: 'dialog_count', dir: 'desc' });
    const deptSort = ref<{ key: string; dir: 'asc' | 'desc' }>({ key: 'dialog_count', dir: 'desc' });

    const filteredEmployees = computed(() => {
        if (!departmentId.value) {
            return filterOptions.employees;
        }
        const did = Number(departmentId.value);

        return filterOptions.employees.filter((e) => e.department_id === did);
    });

    const summary = computed(() => payload.value?.summary || {});
    const chartData = computed<ChartData>(() => (payload.value as DialogAnalyticsPayload | null)?.chart_data || {});
    const funnelRows = computed(() => (payload.value as FunnelAnalyticsPayload | null)?.funnels || []);
    const conversionPayload = computed(() => (payload.value as FunnelAnalyticsPayload | null)?.conversion || null);
    const conversionFunnels = computed(() => conversionPayload.value?.funnels || []);
    const rankingsData = computed<Partial<RankingsPayload>>(() => (payload.value as DialogAnalyticsPayload | null)?.rankings || {});

    const problematic = computed(() => (payload.value as DialogAnalyticsPayload | null)?.problematic_chats?.data || []);
    const problemMeta = computed(
        () =>
            (payload.value as DialogAnalyticsPayload | null)?.problematic_chats?.meta || {
                total: 0,
                last_page: 1,
                current_page: 1,
            },
    );

    const rankingBlocks = computed(() =>
        RANKING_BLOCK_DEFS.map((block) => ({
            ...block,
            title: t(block.titleKey),
            hint: t(block.hintKey),
            primary:
                block.key === 'fastest_avg_response' || block.key === 'slowest_avg_response'
                    ? (r: Record<string, unknown>) => fmtSec(r.avg_response_seconds as number | null | undefined)
                    : block.key === 'best_sla' || block.key === 'worst_sla'
                      ? (r: Record<string, unknown>) => fmtPct(r.sla_on_time_percent as number | null | undefined)
                      : block.primary,
            secondary: block.secondaryKey ? () => t(block.secondaryKey!) : block.secondary,
        })),
    );

    function rankingRows(key: keyof RankingsPayload): Record<string, unknown>[] {
        const rows = rankingsData.value[key];

        return Array.isArray(rows) ? (rows as Record<string, unknown>[]) : [];
    }

    function fmtSec(s: number | null | undefined): string {
        if (s === null || s === undefined) {
            return '—';
        }
        if (s < 60) {
            return t('analytics.secondsShort', { count: Math.round(s) });
        }
        if (s < 3600) {
            return t('analytics.minutesShort', { count: Math.round(s / 60) });
        }

        return t('analytics.hoursShort', { count: Number((s / 3600).toFixed(1)) });
    }

    function fmtPct(p: number | null | undefined): string {
        if (p === null || p === undefined) {
            return '—';
        }

        return `${p}%`;
    }

    function fmtMinutes(m: number | null | undefined): string {
        if (m == null || Number.isNaN(Number(m))) {
            return '—';
        }
        const value = Number(m);
        if (value < 60) {
            return t('analytics.minutesShort', { count: Math.round(value) });
        }

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

    const lineChartData = computed(() => {
        const rows = chartData.value.dialogs_over_time || [];

        return {
            labels: rows.map((r) => r.date),
            datasets: [
                {
                    label: t('analytics.chartLabelActivity'),
                    data: rows.map((r) => r.count),
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
            labels: rows.map((r) => r.date),
            datasets: [
                {
                    label: t('analytics.chartLabelResponse'),
                    data: rows.map((r) => r.avg_seconds ?? 0),
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
            labels: rows.map((r) => r.name),
            datasets: [
                {
                    label: t('analytics.chartLabelDialogs'),
                    data: rows.map((r) => r.dialogs),
                    backgroundColor: 'rgba(59, 130, 246, 0.55)',
                },
            ],
        };
    });

    const doughnutStatus = computed(() => {
        const d = chartData.value.status_distribution || {};

        return {
            labels: [
                t('analytics.statusActiveLabel'),
                t('analytics.statusClosedLabel'),
                t('analytics.statusWaitingLabel'),
            ],
            datasets: [
                {
                    data: [d.active || 0, d.closed || 0, d.waiting || 0],
                    backgroundColor: ['#01b964', '#94a3b8', '#eab308'],
                },
            ],
        };
    });

    function conversionBarData(funnel: { stages: Array<{ name: string; color: string; conversion_percent?: number | null; is_final?: boolean }> }) {
        const stages = funnel.stages.filter((s) => !s.is_final);

        return {
            labels: stages.map((s) => s.name),
            datasets: [
                {
                    label: t('analytics.colConversion'),
                    data: stages.map((s) => s.conversion_percent ?? 0),
                    backgroundColor: stages.map((s) => s.color),
                    borderRadius: 6,
                },
            ],
        };
    }

    const isEmpty = computed(() => {
        if (loading.value || !payload.value) {
            return false;
        }
        if (analyticsType.value === 'funnels') {
            return (summary.value as { total_funnels?: number }).total_funnels === 0;
        }

        const dialogSummary = summary.value as { total_dialogs?: number };

        return dialogSummary.total_dialogs === 0 || dialogSummary.total_dialogs === undefined;
    });

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
            const { data } =
                analyticsType.value === 'dialogs'
                    ? await axios.get<DialogAnalyticsPayload>(route('api.analytics.dialogs'), {
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
                    : await axios.get<FunnelAnalyticsPayload>(route('api.analytics.funnels'), {
                          params: {
                              from: from.value,
                              to: to.value,
                              department_id: departmentId.value || undefined,
                              employee_id: employeeId.value || undefined,
                          },
                      });
            payload.value = data;
        } catch (e: unknown) {
            const err = e as { response?: { data?: { message?: string } }; message?: string };
            error.value = err.response?.data?.message || err.message || t('analytics.loadError');
            payload.value = null;
        } finally {
            loading.value = false;
        }
    }

    function debouncedFetch() {
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }
        debounceTimer = setTimeout(() => {
            fetchAnalytics();
        }, 280);
    }

    function sortEmp() {
        const k = empSort.value.key;
        const dir = empSort.value.dir === 'asc' ? 1 : -1;
        employeeStats.value.sort((a, b) => {
            const va = (a as Record<string, unknown>)[k];
            const vb = (b as Record<string, unknown>)[k];
            if (va === null || va === undefined) {
                return 1;
            }
            if (vb === null || vb === undefined) {
                return -1;
            }
            if (typeof va === 'number' && typeof vb === 'number') {
                return (va - vb) * dir;
            }

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

    function sortDept() {
        const k = deptSort.value.key;
        const dir = deptSort.value.dir === 'asc' ? 1 : -1;
        deptStats.value.sort((a, b) => {
            const va = (a as Record<string, unknown>)[k];
            const vb = (b as Record<string, unknown>)[k];
            if (va === null || va === undefined) {
                return 1;
            }
            if (vb === null || vb === undefined) {
                return -1;
            }
            if (typeof va === 'number' && typeof vb === 'number') {
                return (va - vb) * dir;
            }

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

    function readTabFromUrl(): void {
        if (typeof window === 'undefined') {
            return;
        }
        const params = new URLSearchParams(window.location.search);
        const tab = params.get('tab');
        const funnelTab = params.get('funnel_tab');

        if (tab === 'overview' || tab === 'dynamics' || tab === 'team' || tab === 'problems') {
            dialogsTab.value = tab;
        }
        if (funnelTab === 'overview' || funnelTab === 'conversion' || funnelTab === 'coverage') {
            funnelsTab.value = funnelTab;
        }
    }

    function syncTabToUrl(): void {
        if (typeof window === 'undefined') {
            return;
        }
        const url = new URL(window.location.href);
        if (analyticsType.value === 'dialogs') {
            url.searchParams.set('tab', dialogsTab.value);
            url.searchParams.delete('funnel_tab');
        } else {
            url.searchParams.set('funnel_tab', funnelsTab.value);
            url.searchParams.delete('tab');
        }
        window.history.replaceState({}, '', url);
    }

    function setAnalyticsType(type: AnalyticsType) {
        analyticsType.value = type;
        if (type === 'dialogs') {
            dialogsTab.value = 'overview';
        } else {
            funnelsTab.value = 'overview';
        }
        syncTabToUrl();
    }

    function setDialogsTab(tab: DialogsTab) {
        dialogsTab.value = tab;
        syncTabToUrl();
    }

    function setFunnelsTab(tab: FunnelsTab) {
        funnelsTab.value = tab;
        syncTabToUrl();
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

    watch([analyticsModuleEnabled, funnelsModuleEnabled], () => {
        if (!funnelsModuleEnabled.value && analyticsType.value === 'funnels') {
            if (analyticsModuleEnabled.value) {
                setAnalyticsType('dialogs');
            }
        }
        if (!analyticsModuleEnabled.value && analyticsType.value === 'dialogs') {
            if (funnelsModuleEnabled.value) {
                setAnalyticsType('funnels');
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

    watch(
        () => (payload.value as DialogAnalyticsPayload | null)?.employee_stats,
        (rows) => {
            employeeStats.value = rows ? [...rows] : [];
            sortEmp();
        },
        { immediate: true },
    );

    watch(
        () => (payload.value as DialogAnalyticsPayload | null)?.department_stats,
        (rows) => {
            deptStats.value = rows ? [...rows] : [];
            sortDept();
        },
        { immediate: true },
    );

    onMounted(() => {
        readTabFromUrl();
        fetchAnalytics();
    });

    return {
        analyticsType,
        dialogsTab,
        funnelsTab,
        from,
        to,
        employeeId,
        departmentId,
        status,
        channel,
        problemPage,
        periodPreset,
        loading,
        error,
        payload,
        filteredEmployees,
        summary,
        chartData,
        funnelRows,
        conversionFunnels,
        rankingsData,
        rankingBlocks,
        rankingRows,
        problematic,
        problemMeta,
        employeeStats,
        deptStats,
        empSort,
        isEmpty,
        lineChartData,
        lineAvgResp,
        barLoad,
        doughnutStatus,
        chartOpts,
        doughnutOpts,
        conversionBarData,
        fmtSec,
        fmtPct,
        fmtMinutes,
        fmtHours,
        applyPreset,
        resetFilters,
        fetchAnalytics,
        toggleEmpSort,
        toggleDeptSort,
        setAnalyticsType,
        setDialogsTab,
        setFunnelsTab,
    };
}
