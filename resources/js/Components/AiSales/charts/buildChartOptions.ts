import type { AiSalesChartTheme } from './useAiSalesChartTheme';
import { baseChartOptions } from './useAiSalesChartTheme';

export type ChartsPayload = {
    funnel: { stages: Array<{ name: string; key: string; value: number; percent: number | null; denominator: number }>; cohort_size: number };
    outcomes_daily: { labels: string[]; won: number[]; lost: number[] };
    cohort_daily: { labels: string[]; counts: number[] };
    milestones_daily: { labels: string[]; qualified: number[]; meeting_booked: number[]; closed_won: number[] };
    lost_reasons: { labels: string[]; values: number[]; percents: number[] };
    win_rate_by_grade: { grades: string[]; won: number[]; total: number[]; rates: Array<number | null> };
    objections: { labels: string[]; frequencies: number[]; win_rates: Array<number | null> };
    experiments: { labels: string[]; replies: number[]; qualified: number[]; close_rates: Array<number | null> };
    by_company: { labels: string[]; cohort: number[]; close_rates: Array<number | null> };
    win_prob_calibration: { labels: string[]; predicted: number[]; actual: number[] } | null;
};

export type ChartSeriesLabels = {
    won: string;
    lost: string;
    winRate: string;
    count: string;
    replies: string;
    qualified: string;
    predicted: string;
    actual: string;
};

export type FunnelStageLabels = Record<string, string>;

function shortDateLabels(labels: string[]): string[] {
    return labels.map((d) => {
        const parts = d.split('-');
        return parts.length === 3 ? `${parts[2]}.${parts[1]}` : d;
    });
}

export function funnelChartOption(
    data: ChartsPayload['funnel'],
    theme: AiSalesChartTheme,
    stageLabels: FunnelStageLabels,
): Record<string, unknown> {
    const stages = data.stages.filter((s) => s.value > 0 || s.percent !== null);
    if (stages.length === 0) {
        return {};
    }

    return {
        ...baseChartOptions(theme),
        tooltip: { trigger: 'item', formatter: '{b}: {c}' },
        series: [
            {
                type: 'funnel',
                left: '10%',
                top: 28,
                bottom: 28,
                width: '84%',
                min: 0,
                max: Math.max(data.cohort_size, 1),
                sort: 'descending',
                gap: 4,
                label: {
                    show: true,
                    color: theme.text,
                    formatter: (p: { name: string; data: { percent: number | null } }) => {
                        const pct = p.data.percent;
                        return pct != null ? `${p.name}\n${pct.toFixed(1)}%` : p.name;
                    },
                },
                itemStyle: { borderColor: theme.panel, borderWidth: 2 },
                data: stages.map((s, i) => ({
                    name: stageLabels[s.key] ?? s.name,
                    value: s.value,
                    percent: s.percent,
                    itemStyle: { color: theme.series[i % theme.series.length] },
                })),
            },
        ],
    };
}

export function outcomesDailyOption(
    data: ChartsPayload['outcomes_daily'],
    theme: AiSalesChartTheme,
    seriesLabels: ChartSeriesLabels,
): Record<string, unknown> {
    return {
        ...baseChartOptions(theme),
        legend: { top: 0, textStyle: { color: theme.textMuted } },
        xAxis: { type: 'category', data: shortDateLabels(data.labels), axisLabel: { color: theme.textMuted } },
        yAxis: { type: 'value', splitLine: { lineStyle: { color: theme.gridLine } }, axisLabel: { color: theme.textMuted } },
        series: [
            { name: seriesLabels.won, type: 'line', smooth: true, stack: 'total', areaStyle: { opacity: 0.25 }, data: data.won, color: theme.success },
            { name: seriesLabels.lost, type: 'line', smooth: true, stack: 'total', areaStyle: { opacity: 0.2 }, data: data.lost, color: theme.danger },
        ],
    };
}

export function cohortDailyOption(data: ChartsPayload['cohort_daily'], theme: AiSalesChartTheme): Record<string, unknown> {
    return {
        ...baseChartOptions(theme),
        xAxis: { type: 'category', data: shortDateLabels(data.labels), axisLabel: { color: theme.textMuted } },
        yAxis: { type: 'value', splitLine: { lineStyle: { color: theme.gridLine } }, axisLabel: { color: theme.textMuted } },
        series: [{ type: 'line', smooth: true, areaStyle: { opacity: 0.2, color: theme.accentSoft }, data: data.counts, color: theme.accent }],
    };
}

export function pipelineBarOption(
    kpis: Array<{ key: string; label: string; percent: number | null; numerator: number; denominator: number; sufficient_data: boolean }>,
    labels: Record<string, string>,
    theme: AiSalesChartTheme,
): Record<string, unknown> {
    const pipelineKeys = [
        'qualification_rate', 'budget_capture_rate', 'requirements_capture_rate',
        'timeline_capture_rate', 'dm_capture_rate', 'proposal_rate', 'meeting_booking_rate',
    ];
    const filtered = kpis.filter((k) => pipelineKeys.includes(k.key));
    const names = filtered.map((k) => labels[k.key] ?? k.label);
    const values = filtered.map((k) => (k.sufficient_data && k.percent != null ? k.percent : 0));

    return {
        ...baseChartOptions(theme),
        grid: { ...baseChartOptions(theme).grid as object, left: 120 },
        xAxis: { type: 'value', max: 100, axisLabel: { color: theme.textMuted, formatter: '{value}%' }, splitLine: { lineStyle: { color: theme.gridLine } } },
        yAxis: { type: 'category', data: names, axisLabel: { color: theme.text } },
        series: [{
            type: 'bar',
            data: values.map((v, i) => ({ value: v, itemStyle: { color: theme.series[i % theme.series.length] } })),
            label: { show: true, position: 'right', color: theme.text, formatter: '{c}%' },
        }],
    };
}

export function lostReasonsPieOption(data: ChartsPayload['lost_reasons'], theme: AiSalesChartTheme): Record<string, unknown> {
    if (data.labels.length === 0) {
        return {};
    }

    return {
        tooltip: { trigger: 'item', backgroundColor: theme.panel, borderColor: theme.border, textStyle: { color: theme.text } },
        legend: { bottom: 0, textStyle: { color: theme.textMuted } },
        series: [{
            type: 'pie',
            radius: ['42%', '68%'],
            center: ['50%', '45%'],
            data: data.labels.map((name, i) => ({
                name,
                value: data.values[i],
                itemStyle: { color: theme.series[i % theme.series.length] },
            })),
            label: { color: theme.text },
        }],
    };
}

export function winRateByGradeOption(
    data: ChartsPayload['win_rate_by_grade'],
    theme: AiSalesChartTheme,
    seriesLabels: ChartSeriesLabels,
): Record<string, unknown> {
    if (data.grades.length === 0) {
        return {};
    }

    return {
        ...baseChartOptions(theme),
        legend: { top: 0, textStyle: { color: theme.textMuted } },
        xAxis: { type: 'category', data: data.grades, axisLabel: { color: theme.textMuted } },
        yAxis: [
            { type: 'value', name: seriesLabels.count, splitLine: { lineStyle: { color: theme.gridLine } }, axisLabel: { color: theme.textMuted } },
            { type: 'value', name: '%', max: 100, axisLabel: { color: theme.textMuted, formatter: '{value}%' }, splitLine: { show: false } },
        ],
        series: [
            { name: seriesLabels.won, type: 'bar', stack: 'total', data: data.won, color: theme.success },
            { name: seriesLabels.lost, type: 'bar', stack: 'total', data: data.total.map((t, i) => t - data.won[i]), color: theme.danger, itemStyle: { opacity: 0.5 } },
            { name: seriesLabels.winRate, type: 'line', yAxisIndex: 1, data: data.rates.map((r) => r ?? 0), color: theme.accent },
        ],
    };
}

export function objectionsBarOption(data: ChartsPayload['objections'], theme: AiSalesChartTheme): Record<string, unknown> {
    if (data.labels.length === 0) {
        return {};
    }

    return {
        ...baseChartOptions(theme),
        grid: { ...baseChartOptions(theme).grid as object, left: 140 },
        xAxis: { type: 'value', splitLine: { lineStyle: { color: theme.gridLine } }, axisLabel: { color: theme.textMuted } },
        yAxis: { type: 'category', data: data.labels, axisLabel: { color: theme.text, width: 120, overflow: 'truncate' } },
        series: [{
            type: 'bar',
            data: data.frequencies.map((v, i) => ({
                value: v,
                itemStyle: {
                    color: data.win_rates[i] != null && data.win_rates[i]! >= 50 ? theme.success : theme.warning,
                },
            })),
        }],
    };
}

export function experimentsBarOption(
    data: ChartsPayload['experiments'],
    theme: AiSalesChartTheme,
    seriesLabels: ChartSeriesLabels,
): Record<string, unknown> {
    if (data.labels.length === 0) {
        return {};
    }

    return {
        ...baseChartOptions(theme),
        legend: { top: 0, textStyle: { color: theme.textMuted } },
        xAxis: { type: 'category', data: data.labels, axisLabel: { color: theme.textMuted, rotate: 20 } },
        yAxis: { type: 'value', splitLine: { lineStyle: { color: theme.gridLine } }, axisLabel: { color: theme.textMuted } },
        series: [
            { name: seriesLabels.replies, type: 'bar', data: data.replies, color: theme.series[0] },
            { name: seriesLabels.qualified, type: 'bar', data: data.qualified, color: theme.series[1] },
        ],
    };
}

export function byCompanyBarOption(data: ChartsPayload['by_company'], theme: AiSalesChartTheme): Record<string, unknown> {
    if (data.labels.length === 0) {
        return {};
    }

    return {
        ...baseChartOptions(theme),
        grid: { ...baseChartOptions(theme).grid as object, left: 160 },
        xAxis: { type: 'value', max: 100, axisLabel: { color: theme.textMuted, formatter: '{value}%' }, splitLine: { lineStyle: { color: theme.gridLine } } },
        yAxis: { type: 'category', data: data.labels, axisLabel: { color: theme.text } },
        series: [{
            type: 'bar',
            data: data.close_rates.map((r) => r ?? 0),
            color: theme.accent,
            label: { show: true, position: 'right', color: theme.text, formatter: '{c}%' },
        }],
    };
}

export function wonLostPieOption(won: number, lost: number, theme: AiSalesChartTheme, seriesLabels: ChartSeriesLabels): Record<string, unknown> {
    if (won + lost === 0) {
        return {};
    }

    return {
        tooltip: { trigger: 'item', backgroundColor: theme.panel, borderColor: theme.border, textStyle: { color: theme.text } },
        series: [{
            type: 'pie',
            radius: ['45%', '70%'],
            data: [
                { name: seriesLabels.won, value: won, itemStyle: { color: theme.success } },
                { name: seriesLabels.lost, value: lost, itemStyle: { color: theme.danger } },
            ],
            label: { color: theme.text },
        }],
    };
}

export function calibrationOption(
    data: NonNullable<ChartsPayload['win_prob_calibration']>,
    theme: AiSalesChartTheme,
    seriesLabels: ChartSeriesLabels,
): Record<string, unknown> {
    return {
        ...baseChartOptions(theme),
        legend: { top: 0, textStyle: { color: theme.textMuted } },
        xAxis: { type: 'category', data: data.labels, axisLabel: { color: theme.textMuted } },
        yAxis: { type: 'value', max: 100, axisLabel: { color: theme.textMuted, formatter: '{value}%' }, splitLine: { lineStyle: { color: theme.gridLine } } },
        series: [
            { name: seriesLabels.predicted, type: 'bar', data: data.predicted, color: theme.series[1] },
            { name: seriesLabels.actual, type: 'bar', data: data.actual, color: theme.accent },
        ],
    };
}
