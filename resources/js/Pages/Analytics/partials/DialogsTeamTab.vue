<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { DepartmentStatRow, EmployeeStatRow, RankingsPayload } from '../types';

type RankingBlock = {
    key: keyof RankingsPayload;
    title: string;
    hint: string;
    primary: (r: Record<string, unknown>) => string;
    secondary?: (r: Record<string, unknown>) => string;
};

defineProps<{
    rankingBlocks: RankingBlock[];
    rankingRows: (key: keyof RankingsPayload) => Record<string, unknown>[];
    employeeStats: EmployeeStatRow[];
    deptStats: DepartmentStatRow[];
    fmtSec: (s: number | null | undefined) => string;
    fmtPct: (p: number | null | undefined) => string;
    toggleEmpSort: (key: string) => void;
    toggleDeptSort: (key: string) => void;
}>();

const { t } = useI18n();
</script>

<template>
    <div class="ui-analytics-tab-pane">
        <section class="ui-analytics-section">
            <h2 class="ui-analytics-section__title">{{ t('analytics.employeeRankingTitle') }}</h2>
            <p class="ui-analytics-section__hint">{{ t('analytics.tabTeamRankingHint') }}</p>
            <div class="ui-analytics-ranking-grid">
                <article v-for="block in rankingBlocks" :key="block.key" class="ui-panel ui-analytics-ranking-card">
                    <h3 class="ui-analytics-ranking-card__title">{{ block.title }}</h3>
                    <p class="ui-analytics-ranking-card__hint">{{ block.hint }}</p>
                    <ul class="ui-analytics-ranking-card__list">
                        <li
                            v-for="(row, idx) in rankingRows(block.key).slice(0, 3)"
                            :key="String(row.user_id) + '-' + idx"
                            class="ui-analytics-ranking-card__row"
                        >
                            <span class="ui-analytics-ranking-card__rank">{{ idx + 1 }}</span>
                            <span class="ui-analytics-ranking-card__name">{{ row.name }}</span>
                            <span class="ui-analytics-ranking-card__value">
                                {{ block.primary(row) }}
                                <span v-if="block.secondary" class="ui-analytics-ranking-card__meta">{{ block.secondary(row) }}</span>
                            </span>
                        </li>
                        <li v-if="rankingRows(block.key).length === 0" class="ui-analytics-ranking-card__empty">
                            {{ t('analytics.noRankingData') }}
                        </li>
                    </ul>
                </article>
            </div>
        </section>

        <section class="ui-panel ui-analytics-table-section">
            <h3 class="ui-analytics-table-section__title">{{ t('analytics.employeesSection') }}</h3>
            <div class="ui-analytics-table-wrap">
                <table class="ui-analytics-table">
                    <thead>
                        <tr>
                            <th @click="toggleEmpSort('name')">{{ t('analytics.colEmployee') }}</th>
                            <th @click="toggleEmpSort('dialog_count')">{{ t('analytics.colDialogs') }}</th>
                            <th @click="toggleEmpSort('avg_response_seconds')">{{ t('analytics.colAvgResponse') }}</th>
                            <th @click="toggleEmpSort('max_response_seconds')">{{ t('analytics.colMaxResponse') }}</th>
                            <th @click="toggleEmpSort('unanswered_dialogs')">{{ t('analytics.kpiUnanswered') }}</th>
                            <th @click="toggleEmpSort('closed_dialogs')">{{ t('analytics.colClosed') }}</th>
                            <th @click="toggleEmpSort('sla_on_time_percent')">SLA %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in employeeStats" :key="row.user_id">
                            <td>{{ row.name }}</td>
                            <td>{{ row.dialog_count }}</td>
                            <td>{{ fmtSec(row.avg_response_seconds) }}</td>
                            <td>{{ fmtSec(row.max_response_seconds) }}</td>
                            <td>{{ row.unanswered_dialogs }}</td>
                            <td>{{ row.closed_dialogs }}</td>
                            <td>{{ fmtPct(row.sla_on_time_percent) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="ui-panel ui-analytics-table-section">
            <h3 class="ui-analytics-table-section__title">{{ t('analytics.departmentsSection') }}</h3>
            <div class="ui-analytics-table-wrap">
                <table class="ui-analytics-table">
                    <thead>
                        <tr>
                            <th @click="toggleDeptSort('name')">{{ t('analytics.colDepartment') }}</th>
                            <th @click="toggleDeptSort('dialog_count')">{{ t('analytics.colDialogs') }}</th>
                            <th @click="toggleDeptSort('avg_response_seconds')">{{ t('analytics.colAvgResponse') }}</th>
                            <th @click="toggleDeptSort('max_delay_seconds')">{{ t('analytics.colMaxDelay') }}</th>
                            <th @click="toggleDeptSort('active_dialogs')">{{ t('analytics.kpiActive') }}</th>
                            <th @click="toggleDeptSort('overdue_dialogs')">{{ t('analytics.colOverdue') }}</th>
                            <th>{{ t('analytics.colBestEmployee') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in deptStats" :key="row.department_id">
                            <td>{{ row.name }}</td>
                            <td>{{ row.dialog_count }}</td>
                            <td>{{ fmtSec(row.avg_response_seconds) }}</td>
                            <td>{{ fmtSec(row.max_delay_seconds) }}</td>
                            <td>{{ row.active_dialogs }}</td>
                            <td>{{ row.overdue_dialogs }}</td>
                            <td>{{ row.best_employee_name || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
