<script setup lang="ts">
import AiSalesChartCard from '@/Components/AiSales/partials/AiSalesChartCard.vue';
import AiSalesKpiHero from '@/Components/AiSales/partials/AiSalesKpiHero.vue';
import { cohortDailyOption, funnelChartOption, outcomesDailyOption } from '@/Components/AiSales/charts/buildChartOptions';
import { ensureAiSalesEchartsRegistered, VChart } from '@/Components/AiSales/charts/aiSalesEcharts';
import { useAiSalesChartLabels } from '@/Components/AiSales/charts/useAiSalesChartLabels';
import { readAiSalesChartTheme } from '@/Components/AiSales/charts/useAiSalesChartTheme';
import type { AiSalesMetricsPayload } from '@/Components/AiSales/types';
import { useI18n } from '@/composables/useI18n';
import { computed } from 'vue';

ensureAiSalesEchartsRegistered();

const props = defineProps<{
    metrics: AiSalesMetricsPayload;
    i18nPrefix: string;
}>();

const { t } = useI18n();
const theme = computed(() => readAiSalesChartTheme());
const { seriesLabels, funnelStageLabels } = useAiSalesChartLabels(props.i18nPrefix);

const funnelOption = computed(() => funnelChartOption(props.metrics.charts.funnel, theme.value, funnelStageLabels.value));
const outcomesOption = computed(() => outcomesDailyOption(props.metrics.charts.outcomes_daily, theme.value, seriesLabels.value));
const cohortOption = computed(() => cohortDailyOption(props.metrics.charts.cohort_daily, theme.value));

const topLostReason = computed(() => props.metrics.lost_reasons[0] ?? null);
const bestGrade = computed(() => {
    const sorted = [...props.metrics.win_rate_by_grade].sort((a, b) => (b.percent ?? 0) - (a.percent ?? 0));
    return sorted[0] ?? null;
});
</script>

<template>
    <div class="ui-ai-sales-tab">
        <AiSalesKpiHero :metrics="metrics" :i18n-prefix="i18nPrefix" />

        <div class="ui-ai-sales-insights mb-6" v-if="topLostReason || bestGrade">
            <article v-if="topLostReason" class="ui-ai-sales-insight">
                <span class="ui-ai-sales-insight__label">{{ t(`${i18nPrefix}.topLostReason`) }}</span>
                <strong>{{ topLostReason.reason }}</strong>
                <span class="text-ui-text-muted">{{ topLostReason.percent }}%</span>
            </article>
            <article v-if="bestGrade" class="ui-ai-sales-insight">
                <span class="ui-ai-sales-insight__label">{{ t(`${i18nPrefix}.bestGrade`) }}</span>
                <strong>{{ bestGrade.grade }}</strong>
                <span class="text-ui-text-muted">{{ bestGrade.percent != null ? `${bestGrade.percent}%` : '—' }}</span>
            </article>
        </div>

        <div class="ui-ai-sales-charts-grid mb-6">
            <AiSalesChartCard
                :title="t(`${i18nPrefix}.chartFunnelTitle`)"
                :hint="t(`${i18nPrefix}.chartFunnelHint`)"
                :empty="Object.keys(funnelOption).length === 0"
                :empty-text="t(`${i18nPrefix}.noData`)"
                tall
            >
                <VChart :option="funnelOption" autoresize />
            </AiSalesChartCard>

            <AiSalesChartCard
                :title="t(`${i18nPrefix}.chartOutcomesTrend`)"
                :hint="t(`${i18nPrefix}.chartOutcomesTrendHint`)"
                tall
            >
                <VChart :option="outcomesOption" autoresize />
            </AiSalesChartCard>
        </div>

        <AiSalesChartCard
            :title="t(`${i18nPrefix}.chartCohortDaily`)"
            :hint="t(`${i18nPrefix}.chartCohortDailyHint`)"
        >
            <VChart :option="cohortOption" autoresize />
        </AiSalesChartCard>
    </div>
</template>
