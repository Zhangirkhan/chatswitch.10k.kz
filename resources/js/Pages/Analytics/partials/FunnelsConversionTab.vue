<script setup lang="ts">
import { Bar } from 'vue-chartjs';
import { useI18n } from '@/composables/useI18n';
import { ensureAnalyticsChartsRegistered } from '../analyticsCharts';
import type { FunnelConversionRow } from '../types';

ensureAnalyticsChartsRegistered();

defineProps<{
    conversionFunnels: FunnelConversionRow[];
    chartOpts: any;
    conversionBarData: (funnel: FunnelConversionRow) => any;
    fmtPct: (p: number | null | undefined) => string;
    fmtMinutes: (m: number | null | undefined) => string;
    fmtHours: (h: number | null | undefined) => string;
}>();

const { t } = useI18n();
</script>

<template>
    <div class="ui-analytics-tab-pane">
        <section v-if="conversionFunnels.length === 0" class="ui-panel ui-analytics-empty">
            <p>{{ t('analytics.noFunnelsHint') }}</p>
        </section>

        <div v-else class="ui-analytics-funnel-stack">
            <article v-for="funnel in conversionFunnels" :key="funnel.id" class="ui-panel ui-analytics-funnel-card">
                <div class="ui-analytics-funnel-card__head">
                    <div class="ui-analytics-funnel-card__title-wrap">
                        <span class="ui-analytics-funnel-card__dot" :style="{ background: funnel.color }" />
                        <h3 class="ui-analytics-funnel-card__title">{{ funnel.name }}</h3>
                    </div>
                    <span v-if="funnel.overall_conversion_percent != null" class="ui-analytics-badge ui-analytics-badge--accent">
                        {{ t('analytics.overallConversion', { percent: fmtPct(funnel.overall_conversion_percent) }) }}
                    </span>
                </div>

                <p class="ui-analytics-section__hint">{{ t('analytics.conversionHint') }}</p>

                <div class="ui-analytics-chart-card__canvas ui-analytics-chart-card__canvas--bar">
                    <Bar :data="conversionBarData(funnel)" :options="{ ...chartOpts, indexAxis: 'y' }" />
                </div>

                <div class="ui-analytics-table-wrap">
                    <table class="ui-analytics-table">
                        <thead>
                            <tr>
                                <th>{{ t('analytics.colStage') }}</th>
                                <th>{{ t('analytics.colNow') }}</th>
                                <th>{{ t('analytics.colEntered') }}</th>
                                <th>{{ t('analytics.colForward') }}</th>
                                <th>{{ t('analytics.colConversion') }}</th>
                                <th>{{ t('analytics.colDrop') }}</th>
                                <th>{{ t('analytics.colOnStage') }}</th>
                                <th>{{ t('analytics.colAiResponse') }}</th>
                                <th>{{ t('analytics.colManagerResponse') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="stage in funnel.stages" :key="stage.id">
                                <td>
                                    <span class="ui-analytics-stage-label">
                                        <span class="ui-analytics-funnel-card__dot ui-analytics-funnel-card__dot--sm" :style="{ background: stage.color }" />
                                        {{ stage.name }}
                                    </span>
                                </td>
                                <td>{{ stage.current_chats }}</td>
                                <td>{{ stage.entries }}</td>
                                <td>{{ stage.is_final ? '—' : stage.forward_exits }}</td>
                                <td>{{ stage.is_final ? '—' : fmtPct(stage.conversion_percent) }}</td>
                                <td>{{ stage.is_final ? '—' : stage.drop_off }}</td>
                                <td>{{ fmtHours(stage.avg_hours_on_stage) }}</td>
                                <td :title="stage.response_samples_ai ? t('analytics.sampleTitle', { sample: stage.response_samples_ai }) : ''">
                                    {{ fmtMinutes(stage.avg_response_minutes_ai) }}
                                </td>
                                <td :title="stage.response_samples_manager ? t('analytics.sampleTitle', { sample: stage.response_samples_manager }) : ''">
                                    {{ fmtMinutes(stage.avg_response_minutes_manager) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </article>
        </div>
    </div>
</template>
