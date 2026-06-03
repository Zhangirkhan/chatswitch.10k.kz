<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { FunnelCoverageRow } from '../types';

defineProps<{
    funnelRows: FunnelCoverageRow[];
    fmtPct: (p: number | null | undefined) => string;
}>();

const { t } = useI18n();
</script>

<template>
    <div class="ui-analytics-tab-pane">
        <section class="ui-panel ui-analytics-table-section">
            <h3 class="ui-analytics-table-section__title">{{ t('analytics.coverageTitle') }}</h3>
            <div class="ui-analytics-table-wrap">
                <table class="ui-analytics-table">
                    <thead>
                        <tr>
                            <th>{{ t('analytics.colFunnel') }}</th>
                            <th>{{ t('analytics.colDepartments') }}</th>
                            <th>{{ t('analytics.colStages') }}</th>
                            <th>{{ t('analytics.colSelected') }}</th>
                            <th>{{ t('analytics.colCoverage') }}</th>
                            <th>{{ t('analytics.colStatus') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="funnel in funnelRows" :key="funnel.id">
                            <td>
                                <div class="ui-analytics-funnel-card__title-wrap">
                                    <span class="ui-analytics-funnel-card__dot" :style="{ background: funnel.color }" />
                                    <span>{{ funnel.name }}</span>
                                </div>
                                <div v-if="funnel.description" class="ui-analytics-table__sub">{{ funnel.description }}</div>
                            </td>
                            <td>
                                <div v-if="funnel.departments?.length" class="ui-analytics-chip-row">
                                    <span v-for="department in funnel.departments" :key="department.id" class="ui-analytics-chip">
                                        {{ department.name }}
                                    </span>
                                </div>
                                <span v-else>—</span>
                            </td>
                            <td>{{ funnel.stages_count }}</td>
                            <td>{{ funnel.selected_stages_count }}</td>
                            <td>{{ fmtPct(funnel.coverage_percent) }}</td>
                            <td>
                                <span class="ui-analytics-badge" :class="funnel.is_active ? 'ui-analytics-badge--accent' : 'ui-analytics-badge--danger'">
                                    {{ funnel.is_active ? t('analytics.funnelActive') : t('analytics.funnelInactive') }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="ui-panel ui-analytics-section">
            <h3 class="ui-analytics-section__title">{{ t('analytics.stagesByFunnel') }}</h3>
            <p class="ui-analytics-section__hint">{{ t('analytics.stagesByFunnelHint') }}</p>
            <div class="ui-analytics-funnel-stack">
                <article v-for="funnel in funnelRows" :key="`stages-${funnel.id}`" class="ui-analytics-stage-card">
                    <div class="ui-analytics-funnel-card__title-wrap">
                        <span class="ui-analytics-funnel-card__dot" :style="{ background: funnel.color }" />
                        <span class="ui-analytics-stage-card__name">{{ funnel.name }}</span>
                    </div>
                    <div v-if="funnel.stages?.length" class="ui-analytics-chip-row">
                        <span
                            v-for="stage in funnel.stages"
                            :key="stage.id"
                            class="ui-analytics-stage-chip"
                            :class="{ 'is-selected': stage.selected }"
                            :style="{ opacity: stage.is_active ? 1 : 0.55 }"
                        >
                            <span class="ui-analytics-funnel-card__dot ui-analytics-funnel-card__dot--sm" :style="{ background: stage.color }" />
                            {{ stage.name }}
                        </span>
                    </div>
                    <p v-else class="ui-analytics-section__hint">{{ t('analytics.noStages') }}</p>
                </article>
            </div>
        </section>
    </div>
</template>
