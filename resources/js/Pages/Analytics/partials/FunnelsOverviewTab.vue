<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from '@/composables/useI18n';
import AnalyticsKpiGrid from './AnalyticsKpiGrid.vue';
import type { FunnelSummary, KpiItem } from '../types';

const props = defineProps<{
    summary: FunnelSummary;
    fmtPct: (p: number | null | undefined) => string;
}>();

const { t } = useI18n();

const kpiItems = computed<KpiItem[]>(() => [
    { label: t('analytics.kpiTotalFunnels'), value: String(props.summary.total_funnels ?? 0) },
    { label: t('analytics.kpiActive'), value: String(props.summary.active_funnels ?? 0), tone: 'success' },
    { label: t('analytics.kpiDeptLinked'), value: String(props.summary.connected_funnels ?? 0), tone: 'success' },
    { label: t('analytics.kpiTotalStages'), value: String(props.summary.total_stages ?? 0) },
    { label: t('analytics.kpiSelectedStages'), value: String(props.summary.selected_stages ?? 0) },
    { label: t('analytics.kpiStageCoverage'), value: props.fmtPct(props.summary.stage_coverage_percent) },
    { label: t('analytics.kpiDepartments'), value: String(props.summary.departments_in_scope ?? 0) },
    { label: t('analytics.kpiChats'), value: String(props.summary.tracked_chats ?? 0) },
    { label: t('analytics.kpiTransitions'), value: String(props.summary.total_transitions ?? 0) },
]);
</script>

<template>
    <div class="ui-analytics-tab-pane">
        <section class="ui-analytics-section">
            <h2 class="ui-analytics-section__title">{{ t('analytics.kpiTitle') }}</h2>
            <AnalyticsKpiGrid :items="kpiItems" />
        </section>
    </div>
</template>
