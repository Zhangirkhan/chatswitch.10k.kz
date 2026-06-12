<script setup lang="ts">
import AiSalesChartCard from '@/Components/AiSales/partials/AiSalesChartCard.vue';
import { pipelineBarOption } from '@/Components/AiSales/charts/buildChartOptions';
import { ensureAiSalesEchartsRegistered, VChart } from '@/Components/AiSales/charts/aiSalesEcharts';
import { readAiSalesChartTheme } from '@/Components/AiSales/charts/useAiSalesChartTheme';
import type { AiSalesMetricsPayload, Kpi } from '@/Components/AiSales/types';
import { useI18n } from '@/composables/useI18n';
import { computed } from 'vue';

ensureAiSalesEchartsRegistered();

const props = defineProps<{
    metrics: AiSalesMetricsPayload;
    i18nPrefix: string;
}>();

const { t } = useI18n();
const theme = computed(() => readAiSalesChartTheme());

const pipelineKeys = [
    'qualification_rate', 'budget_capture_rate', 'requirements_capture_rate',
    'timeline_capture_rate', 'dm_capture_rate', 'proposal_rate', 'meeting_booking_rate',
];

const pipelineKpis = computed(() =>
    props.metrics.kpis.filter((k) => pipelineKeys.includes(k.key)),
);

const kpiLabels = computed(() => ({
    qualification_rate: t(`${props.i18nPrefix}.kpiQualification`),
    budget_capture_rate: t(`${props.i18nPrefix}.kpiBudget`),
    requirements_capture_rate: t(`${props.i18nPrefix}.kpiRequirements`),
    timeline_capture_rate: t(`${props.i18nPrefix}.kpiTimeline`),
    dm_capture_rate: t(`${props.i18nPrefix}.kpiDm`),
    proposal_rate: t(`${props.i18nPrefix}.kpiProposal`),
    meeting_booking_rate: t(`${props.i18nPrefix}.kpiMeeting`),
}));

const barOption = computed(() => pipelineBarOption(props.metrics.kpis, kpiLabels.value, theme.value));

function formatPercent(kpi: Kpi): string {
    if (!kpi.sufficient_data || kpi.percent == null) {
        return t(`${props.i18nPrefix}.insufficientData`);
    }
    return `${kpi.percent.toFixed(1)}%`;
}

function ringStyle(kpi: Kpi): Record<string, string> {
    const pct = kpi.sufficient_data && kpi.percent != null ? kpi.percent : 0;
    return {
        background: `conic-gradient(var(--ui-accent) ${pct * 3.6}deg, var(--ui-surface-soft) 0)`,
    };
}
</script>

<template>
    <div class="ui-ai-sales-tab">
        <AiSalesChartCard
            :title="t(`${i18nPrefix}.chartPipelineTitle`)"
            :hint="t(`${i18nPrefix}.chartPipelineHint`)"
            tall
        >
            <VChart :option="barOption" autoresize />
        </AiSalesChartCard>

        <section class="mt-6">
            <h2 class="mb-3 text-lg font-semibold">{{ t(`${i18nPrefix}.pipelineTitle`) }}</h2>
            <div class="ui-ai-sales-kpi-rings">
                <article
                    v-for="kpi in pipelineKpis.slice(0, 6)"
                    :key="kpi.key"
                    class="ui-ai-sales-kpi-ring"
                >
                    <div class="ui-ai-sales-kpi-ring__donut" :style="ringStyle(kpi)">
                        <span>{{ formatPercent(kpi) }}</span>
                    </div>
                    <p class="ui-ai-sales-kpi-ring__label">{{ kpiLabels[kpi.key as keyof typeof kpiLabels] ?? kpi.label }}</p>
                    <p class="ui-ai-sales-kpi-ring__meta">{{ kpi.numerator }} / {{ kpi.denominator }}</p>
                </article>
            </div>
        </section>
    </div>
</template>
