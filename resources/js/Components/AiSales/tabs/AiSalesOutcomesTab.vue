<script setup lang="ts">
import AiSalesChartCard from '@/Components/AiSales/partials/AiSalesChartCard.vue';
import { lostReasonsPieOption, winRateByGradeOption, wonLostPieOption } from '@/Components/AiSales/charts/buildChartOptions';
import { ensureAiSalesEchartsRegistered, VChart } from '@/Components/AiSales/charts/aiSalesEcharts';
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

const closeKpi = computed(() => props.metrics.kpis.find((k) => k.key === 'close_rate'));
const wonCount = computed(() => closeKpi.value?.numerator ?? 0);
const closedTotal = computed(() => props.metrics.summary.closed_deals);
const lostCount = computed(() => Math.max(0, closedTotal.value - wonCount.value));

const wonLostOption = computed(() => wonLostPieOption(wonCount.value, lostCount.value, theme.value));
const lostReasonsOption = computed(() => lostReasonsPieOption(props.metrics.charts.lost_reasons, theme.value));
const gradeOption = computed(() => winRateByGradeOption(props.metrics.charts.win_rate_by_grade, theme.value));
</script>

<template>
    <div class="ui-ai-sales-tab">
        <div class="ui-ai-sales-charts-grid">
            <AiSalesChartCard
                :title="t(`${i18nPrefix}.chartWonLostTitle`)"
                :hint="t(`${i18nPrefix}.chartWonLostHint`)"
                :empty="Object.keys(wonLostOption).length === 0"
                :empty-text="t(`${i18nPrefix}.noData`)"
            >
                <VChart :option="wonLostOption" autoresize />
            </AiSalesChartCard>

            <AiSalesChartCard
                :title="t(`${i18nPrefix}.chartLostReasons`)"
                :hint="t(`${i18nPrefix}.chartLostReasonsHint`)"
                :empty="Object.keys(lostReasonsOption).length === 0"
                :empty-text="t(`${i18nPrefix}.noData`)"
            >
                <VChart :option="lostReasonsOption" autoresize />
            </AiSalesChartCard>
        </div>

        <AiSalesChartCard
            class="mt-6"
            :title="t(`${i18nPrefix}.winByGradeTitle`)"
            :hint="t(`${i18nPrefix}.chartWinByGradeHint`)"
            :empty="Object.keys(gradeOption).length === 0"
            :empty-text="t(`${i18nPrefix}.noData`)"
            tall
        >
            <VChart :option="gradeOption" autoresize />
        </AiSalesChartCard>
    </div>
</template>
