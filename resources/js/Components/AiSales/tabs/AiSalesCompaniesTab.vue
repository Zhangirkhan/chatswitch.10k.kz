<script setup lang="ts">
import AiSalesChartCard from '@/Components/AiSales/partials/AiSalesChartCard.vue';
import { byCompanyBarOption } from '@/Components/AiSales/charts/buildChartOptions';
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

const emit = defineEmits<{ filterCompany: [companyId: number] }>();

const { t } = useI18n();
const theme = computed(() => readAiSalesChartTheme());
const barOption = computed(() => byCompanyBarOption(props.metrics.charts.by_company, theme.value));

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
            :title="t(`${i18nPrefix}.chartByCompanyTitle`)"
            :hint="t(`${i18nPrefix}.chartByCompanyHint`)"
            :empty="Object.keys(barOption).length === 0"
            :empty-text="t(`${i18nPrefix}.noData`)"
            tall
        >
            <VChart :option="barOption" autoresize />
        </AiSalesChartCard>

        <section v-if="metrics.by_company.length > 0" class="ui-panel overflow-hidden p-0 mt-6">
            <div class="border-b border-ui-border px-4 py-3 font-medium">
                {{ t(`${i18nPrefix}.byCompanyTitle`) }}
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-ui-border bg-ui-surface-soft text-left text-ui-text-secondary">
                        <tr>
                            <th class="px-4 py-2 font-medium">{{ t(`${i18nPrefix}.companyColumn`) }}</th>
                            <th class="px-4 py-2 font-medium">{{ t(`${i18nPrefix}.summaryCohort`) }}</th>
                            <th class="px-4 py-2 font-medium">{{ t(`${i18nPrefix}.kpiQualification`) }}</th>
                            <th class="px-4 py-2 font-medium">{{ t(`${i18nPrefix}.kpiBudget`) }}</th>
                            <th class="px-4 py-2 font-medium">{{ t(`${i18nPrefix}.kpiMeeting`) }}</th>
                            <th class="px-4 py-2 font-medium">{{ t(`${i18nPrefix}.kpiClose`) }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ui-border">
                        <tr v-for="row in metrics.by_company" :key="row.company_id">
                            <td class="px-4 py-2">
                                <button
                                    type="button"
                                    class="font-medium text-ui-accent hover:underline"
                                    @click="emit('filterCompany', row.company_id)"
                                >
                                    {{ row.company_name }}
                                </button>
                                <div class="text-xs text-ui-text-muted">{{ row.company_slug }}</div>
                            </td>
                            <td class="px-4 py-2">{{ row.cohort_size }}</td>
                            <td class="px-4 py-2">{{ formatPercent(row.qualification_rate) }}</td>
                            <td class="px-4 py-2">{{ formatPercent(row.budget_capture_rate) }}</td>
                            <td class="px-4 py-2">{{ formatPercent(row.meeting_booking_rate) }}</td>
                            <td class="px-4 py-2">{{ formatPercent(row.close_rate) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
