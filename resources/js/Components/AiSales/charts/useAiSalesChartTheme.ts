import { computed, type ComputedRef } from 'vue';

export type AiSalesChartTheme = {
    text: string;
    textMuted: string;
    border: string;
    accent: string;
    accentSoft: string;
    success: string;
    danger: string;
    warning: string;
    panel: string;
    gridLine: string;
    series: string[];
};

function cssVar(name: string, fallback: string): string {
    if (typeof document === 'undefined') {
        return fallback;
    }
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();

    return value !== '' ? value : fallback;
}

export function readAiSalesChartTheme(): AiSalesChartTheme {
    return {
        text: cssVar('--ui-text', '#e8eaed'),
        textMuted: cssVar('--ui-text-muted', '#9aa0a6'),
        border: cssVar('--ui-border', '#3c4043'),
        accent: cssVar('--ui-accent', '#25d366'),
        accentSoft: cssVar('--ui-accent-soft', 'rgba(37, 211, 102, 0.15)'),
        success: cssVar('--wa-chroma-success-fg', '#34d399'),
        danger: cssVar('--wa-chroma-critical-fg', '#f87171'),
        warning: cssVar('--wa-chroma-warning-fg', '#fbbf24'),
        panel: cssVar('--ui-surface', '#202124'),
        gridLine: cssVar('--ui-border', '#3c4043'),
        series: [
            cssVar('--ui-accent', '#25d366'),
            cssVar('--wa-chroma-info-fg', '#60a5fa'),
            cssVar('--wa-chroma-warning-fg', '#fbbf24'),
            cssVar('--wa-chroma-critical-fg', '#f87171'),
            cssVar('--wa-chroma-success-fg', '#34d399'),
        ],
    };
}

export function useAiSalesChartTheme(): ComputedRef<AiSalesChartTheme> {
    return computed(() => readAiSalesChartTheme());
}

export function baseChartOptions(theme: AiSalesChartTheme): Record<string, unknown> {
    return {
        textStyle: { color: theme.text, fontFamily: 'inherit' },
        grid: { left: 16, right: 20, top: 52, bottom: 24, containLabel: true },
        tooltip: {
            trigger: 'axis',
            backgroundColor: theme.panel,
            borderColor: theme.border,
            textStyle: { color: theme.text, fontSize: 12 },
        },
    };
}
