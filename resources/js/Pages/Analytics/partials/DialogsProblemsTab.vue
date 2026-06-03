<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { ProblematicChatRow } from '../types';

defineProps<{
    problematic: ProblematicChatRow[];
    problemMeta: { total: number; last_page: number; current_page: number };
    fmtSec: (s: number | null | undefined) => string;
}>();

const emit = defineEmits<{
    prev: [];
    next: [];
}>();

const { t } = useI18n();
</script>

<template>
    <div class="ui-analytics-tab-pane">
        <section class="ui-panel ui-analytics-table-section">
            <div class="ui-analytics-table-section__head">
                <div>
                    <h3 class="ui-analytics-table-section__title">{{ t('analytics.problemDialogsTitle') }}</h3>
                    <p class="ui-analytics-section__hint">{{ t('analytics.problemDialogsHint') }}</p>
                </div>
            </div>
            <div class="ui-analytics-table-wrap">
                <table class="ui-analytics-table">
                    <thead>
                        <tr>
                            <th>{{ t('analytics.colClient') }}</th>
                            <th>{{ t('analytics.colEmployee') }}</th>
                            <th>{{ t('analytics.colDepartment') }}</th>
                            <th>{{ t('analytics.colLastFromClient') }}</th>
                            <th>{{ t('analytics.colWait') }}</th>
                            <th>{{ t('analytics.colStatus') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in problematic" :key="row.chat_id">
                            <td>
                                {{ row.client_label }}
                                <span v-if="row.client_phone" class="ui-analytics-table__sub">{{ row.client_phone }}</span>
                            </td>
                            <td>{{ row.assignee_name || '—' }}</td>
                            <td>{{ row.department_name || '—' }}</td>
                            <td class="ui-analytics-table__sub">{{ row.last_client_message_at || '—' }}</td>
                            <td class="ui-analytics-table__warn">{{ fmtSec(row.wait_seconds) }}</td>
                            <td>
                                <span class="ui-analytics-badge">{{ row.status }}</span>
                            </td>
                            <td>
                                <a :href="row.open_url" class="ui-analytics-link">{{ t('analytics.openChat') }}</a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="problemMeta.total > 0" class="ui-analytics-pagination">
                <span>{{ t('analytics.problemTotal', { total: problemMeta.total }) }}</span>
                <div class="ui-analytics-pagination__actions">
                    <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" :disabled="problemMeta.current_page <= 1" @click="emit('prev')">
                        {{ t('analytics.paginationBack') }}
                    </button>
                    <button
                        type="button"
                        class="ui-btn ui-btn--ghost ui-btn--sm"
                        :disabled="problemMeta.current_page >= problemMeta.last_page"
                        @click="emit('next')"
                    >
                        {{ t('analytics.paginationForward') }}
                    </button>
                </div>
            </div>
        </section>
    </div>
</template>
