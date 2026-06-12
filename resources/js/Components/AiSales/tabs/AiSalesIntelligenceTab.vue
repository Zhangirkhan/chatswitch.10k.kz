<script setup lang="ts">
import AiSalesChartCard from '@/Components/AiSales/partials/AiSalesChartCard.vue';
import { objectionsBarOption } from '@/Components/AiSales/charts/buildChartOptions';
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
const objectionsOption = computed(() => objectionsBarOption(props.metrics.charts.objections, theme.value));
</script>

<template>
    <div class="ui-ai-sales-tab">
        <AiSalesChartCard
            :title="t(`${i18nPrefix}.chartObjectionsTitle`)"
            :hint="t(`${i18nPrefix}.chartObjectionsHint`)"
            :empty="Object.keys(objectionsOption).length === 0"
            :empty-text="t(`${i18nPrefix}.noData`)"
            tall
        >
            <VChart :option="objectionsOption" autoresize />
        </AiSalesChartCard>

        <section class="mt-6 ui-panel overflow-hidden p-0">
            <div class="border-b border-ui-border px-4 py-3 font-medium">
                {{ t(`${i18nPrefix}.objectionsTitle`) }}
            </div>
            <div class="grid gap-6 px-4 py-4 lg:grid-cols-2">
                <div>
                    <h3 class="mb-2 text-sm font-medium text-ui-text-secondary">{{ t(`${i18nPrefix}.topWinningResponses`) }}</h3>
                    <ul class="space-y-2 text-sm">
                        <li v-for="(row, idx) in metrics.objection_intelligence.top_winning_responses" :key="`win-${idx}`">
                            {{ row.text }}
                            <span class="text-ui-text-muted"> ({{ row.win_count }})</span>
                        </li>
                        <li v-if="metrics.objection_intelligence.top_winning_responses.length === 0" class="text-ui-text-muted">
                            {{ t(`${i18nPrefix}.noData`) }}
                        </li>
                    </ul>
                </div>
                <div>
                    <h3 class="mb-2 text-sm font-medium text-ui-text-secondary">{{ t(`${i18nPrefix}.topLosingResponses`) }}</h3>
                    <ul class="space-y-2 text-sm">
                        <li v-for="(row, idx) in metrics.objection_intelligence.top_losing_responses" :key="`loss-${idx}`">
                            {{ row.text }}
                            <span class="text-ui-text-muted"> ({{ row.loss_count }})</span>
                        </li>
                        <li v-if="metrics.objection_intelligence.top_losing_responses.length === 0" class="text-ui-text-muted">
                            {{ t(`${i18nPrefix}.noData`) }}
                        </li>
                    </ul>
                </div>
            </div>
        </section>
    </div>
</template>
