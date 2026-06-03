<script setup lang="ts">
import { computed } from 'vue';
import { Doughnut, Line } from 'vue-chartjs';
import { useI18n } from '@/composables/useI18n';
import { ensureAnalyticsChartsRegistered } from '../analyticsCharts';
import AnalyticsKpiGrid from './AnalyticsKpiGrid.vue';
import type { DialogSummary, KpiItem } from '../types';

ensureAnalyticsChartsRegistered();

const props = defineProps<{
    summary: DialogSummary;
    lineChartData: any;
    doughnutStatus: any;
    chartOpts: any;
    doughnutOpts: any;
    fmtSec: (s: number | null | undefined) => string;
    fmtPct: (p: number | null | undefined) => string;
}>();

const { t } = useI18n();

const kpiItems = computed<KpiItem[]>(() => [
    { label: t('analytics.kpiTotalDialogs'), value: String(props.summary.total_dialogs ?? 0) },
    { label: t('analytics.kpiActive'), value: String(props.summary.active_dialogs ?? 0), tone: 'success' },
    { label: t('analytics.kpiAvgFirstResponse'), value: props.fmtSec(props.summary.avg_first_response_seconds), tone: 'success' },
    { label: t('analytics.kpiUnanswered'), value: String(props.summary.unanswered_dialogs ?? 0), tone: 'danger' },
    { label: t('analytics.kpiOverdue'), value: props.fmtPct(props.summary.overdue_response_percent), tone: 'danger' },
    { label: t('analytics.kpiDialogsPerEmployee'), value: props.summary.dialogs_per_staff_member != null ? String(props.summary.dialogs_per_staff_member) : '—' },
]);
</script>

<template>
    <div class="ui-analytics-tab-pane">
        <section class="ui-analytics-section">
            <h2 class="ui-analytics-section__title">{{ t('analytics.kpiTitle') }}</h2>
            <AnalyticsKpiGrid :items="kpiItems" compact />
        </section>

        <div class="ui-analytics-chart-grid ui-analytics-chart-grid--overview">
            <section class="ui-panel ui-analytics-chart-card">
                <div class="ui-analytics-chart-card__head">
                    <h3 class="ui-analytics-chart-card__title">{{ t('analytics.chartActivity') }}</h3>
                    <p class="ui-analytics-chart-card__hint">{{ t('analytics.chartActivityHint') }}</p>
                </div>
                <div class="ui-analytics-chart-card__canvas">
                    <Line :data="lineChartData" :options="chartOpts" />
                </div>
            </section>

            <section class="ui-panel ui-analytics-chart-card">
                <div class="ui-analytics-chart-card__head">
                    <h3 class="ui-analytics-chart-card__title">{{ t('analytics.chartStatuses') }}</h3>
                    <p class="ui-analytics-chart-card__hint">{{ t('analytics.chartStatusesHint') }}</p>
                </div>
                <div class="ui-analytics-chart-card__canvas ui-analytics-chart-card__canvas--compact">
                    <Doughnut :data="doughnutStatus" :options="doughnutOpts" />
                </div>
            </section>
        </div>
    </div>
</template>
