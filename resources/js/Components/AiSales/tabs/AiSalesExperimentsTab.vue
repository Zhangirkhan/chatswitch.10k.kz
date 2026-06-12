<script setup lang="ts">
import AiSalesChartCard from '@/Components/AiSales/partials/AiSalesChartCard.vue';
import { experimentsBarOption } from '@/Components/AiSales/charts/buildChartOptions';
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
const experiments = computed(() => props.metrics.experiments ?? []);
const barOption = computed(() => experimentsBarOption(props.metrics.charts.experiments, theme.value));

function formatPercent(value: number | null): string {
    if (value === null) {
        return t(`${props.i18nPrefix}.emDash`);
    }
    return `${value.toFixed(1)}%`;
}
</script>

<template>
    <div class="ui-ai-sales-tab">
        <AiSalesChartCard
            v-if="experiments.length > 0"
            :title="t(`${i18nPrefix}.experimentsTitle`)"
            :hint="t(`${i18nPrefix}.chartExperimentsHint`)"
            tall
        >
            <VChart :option="barOption" autoresize />
        </AiSalesChartCard>

        <section v-if="experiments.length === 0" class="ui-panel ui-analytics-empty px-4 py-8">
            {{ t(`${i18nPrefix}.noExperiments`) }}
        </section>

        <section v-else class="ui-panel overflow-hidden p-0 mt-6">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-ui-border bg-ui-surface-soft text-left text-ui-text-secondary">
                        <tr>
                            <th class="px-4 py-2 font-medium">{{ t(`${i18nPrefix}.experimentColumn`) }}</th>
                            <th class="px-4 py-2 font-medium">{{ t(`${i18nPrefix}.variantColumn`) }}</th>
                            <th class="px-4 py-2 font-medium">{{ t(`${i18nPrefix}.experimentReplies`) }}</th>
                            <th class="px-4 py-2 font-medium">{{ t(`${i18nPrefix}.experimentQualified`) }}</th>
                            <th class="px-4 py-2 font-medium">{{ t(`${i18nPrefix}.kpiClose`) }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ui-border">
                        <tr v-for="row in experiments" :key="`${row.experiment_id}-${row.variant_key}`">
                            <td class="px-4 py-2">{{ row.experiment_name }}</td>
                            <td class="px-4 py-2">
                                {{ row.variant_key }}
                                <span v-if="row.is_control" class="text-xs text-ui-text-muted"> (control)</span>
                            </td>
                            <td class="px-4 py-2">{{ row.replies }}</td>
                            <td class="px-4 py-2">{{ row.qualified }}</td>
                            <td class="px-4 py-2">{{ formatPercent(row.close_rate) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
