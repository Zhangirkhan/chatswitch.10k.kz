<script setup lang="ts">
import type { AiSalesMetricsPayload } from '@/Components/AiSales/types';
import { useI18n } from '@/composables/useI18n';
import { computed } from 'vue';

const props = defineProps<{
    metrics: AiSalesMetricsPayload;
    i18nPrefix: string;
}>();

const { t } = useI18n();

const closeRateKpi = computed(() => props.metrics.kpis.find((k) => k.key === 'close_rate'));

const closeRateDisplay = computed(() => {
    const kpi = closeRateKpi.value;
    if (kpi == null || !kpi.sufficient_data || kpi.percent == null) {
        return t(`${props.i18nPrefix}.insufficientData`);
    }
    return `${kpi.percent.toFixed(1)}%`;
});
</script>

<template>
    <div class="ui-ai-sales-hero mb-6">
        <div class="ui-ai-sales-hero__grid">
            <article class="ui-ai-sales-hero__card ui-ai-sales-hero__card--accent">
                <span class="ui-ai-sales-hero__label">{{ t(`${i18nPrefix}.summaryCohort`) }}</span>
                <strong class="ui-ai-sales-hero__value">{{ metrics.summary.cohort_size }}</strong>
            </article>
            <article class="ui-ai-sales-hero__card">
                <span class="ui-ai-sales-hero__label">{{ t(`${i18nPrefix}.summaryClosed`) }}</span>
                <strong class="ui-ai-sales-hero__value">{{ metrics.summary.closed_deals }}</strong>
            </article>
            <article class="ui-ai-sales-hero__card">
                <span class="ui-ai-sales-hero__label">{{ t(`${i18nPrefix}.summaryFollowUps`) }}</span>
                <strong class="ui-ai-sales-hero__value">{{ metrics.summary.follow_ups_sent }}</strong>
            </article>
            <article class="ui-ai-sales-hero__card ui-ai-sales-hero__card--highlight">
                <span class="ui-ai-sales-hero__label">{{ t(`${i18nPrefix}.kpiClose`) }}</span>
                <strong class="ui-ai-sales-hero__value">{{ closeRateDisplay }}</strong>
            </article>
        </div>
        <div v-if="metrics.win_prob_model" class="ui-ai-sales-hero__badge">
            {{ metrics.win_prob_model.type === 'ml'
                ? t(`${i18nPrefix}.winProbModelMl`, { version: metrics.win_prob_model.version ?? 1 })
                : t(`${i18nPrefix}.winProbModelHeuristic`) }}
        </div>
    </div>
</template>
