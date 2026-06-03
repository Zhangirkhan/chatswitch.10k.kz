<script setup lang="ts">
import { Bar, Doughnut, Line } from 'vue-chartjs';
import { useI18n } from '@/composables/useI18n';
import { ensureAnalyticsChartsRegistered } from '../analyticsCharts';

ensureAnalyticsChartsRegistered();

defineProps<{
    lineChartData: any;
    lineAvgResp: any;
    barLoad: any;
    doughnutStatus: any;
    chartOpts: any;
    doughnutOpts: any;
}>();

const { t } = useI18n();
</script>

<template>
    <div class="ui-analytics-tab-pane">
        <div class="ui-analytics-chart-grid">
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
                    <h3 class="ui-analytics-chart-card__title">{{ t('analytics.chartResponseTime') }}</h3>
                    <p class="ui-analytics-chart-card__hint">{{ t('analytics.chartResponseTimeHint') }}</p>
                </div>
                <div class="ui-analytics-chart-card__canvas">
                    <Line :data="lineAvgResp" :options="chartOpts" />
                </div>
            </section>

            <section class="ui-panel ui-analytics-chart-card">
                <div class="ui-analytics-chart-card__head">
                    <h3 class="ui-analytics-chart-card__title">{{ t('analytics.chartLoad') }}</h3>
                    <p class="ui-analytics-chart-card__hint">{{ t('analytics.chartLoadHint') }}</p>
                </div>
                <div class="ui-analytics-chart-card__canvas">
                    <Bar :data="barLoad" :options="{ ...chartOpts, indexAxis: 'y' }" />
                </div>
            </section>

            <section class="ui-panel ui-analytics-chart-card">
                <div class="ui-analytics-chart-card__head">
                    <h3 class="ui-analytics-chart-card__title">{{ t('analytics.chartStatuses') }}</h3>
                    <p class="ui-analytics-chart-card__hint">{{ t('analytics.chartStatusesHint') }}</p>
                </div>
                <div class="ui-analytics-chart-card__canvas">
                    <Doughnut :data="doughnutStatus" :options="doughnutOpts" />
                </div>
            </section>
        </div>
    </div>
</template>
