<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import type { AnalyticsType } from '../types';

defineProps<{
    analyticsType: AnalyticsType;
    periodPreset: 'today' | '7d' | '30d' | 'custom';
    from: string;
    to: string;
    departmentId: string;
    employeeId: string;
    status: string;
    channel: string;
    filteredEmployees: Array<{ id: number; name: string; department_id?: number | null }>;
    departments: Array<{ id: number; name: string }>;
    isEmployee: boolean;
}>();

const emit = defineEmits<{
    'update:from': [value: string];
    'update:to': [value: string];
    'update:departmentId': [value: string];
    'update:employeeId': [value: string];
    'update:status': [value: string];
    'update:channel': [value: string];
    'update:periodPreset': [value: 'today' | '7d' | '30d' | 'custom'];
    preset: [value: 'today' | '7d' | '30d'];
    reset: [];
}>();

const { t } = useI18n();
</script>

<template>
    <section class="ui-panel ui-analytics-filters" :aria-label="t('analytics.filtersTitle')">
        <div class="ui-analytics-filters__row ui-analytics-filters__row--period">
            <span class="ui-analytics-filters__title">{{ t('analytics.filtersTitle') }}</span>

            <div class="ui-analytics-filters__pills">
                <button
                    type="button"
                    class="ui-analytics-filter-pill"
                    :class="{ 'is-active': periodPreset === 'today' }"
                    @click="emit('preset', 'today')"
                >
                    {{ t('analytics.today') }}
                </button>
                <button
                    type="button"
                    class="ui-analytics-filter-pill"
                    :class="{ 'is-active': periodPreset === '7d' }"
                    @click="emit('preset', '7d')"
                >
                    {{ t('analytics.days7') }}
                </button>
                <button
                    type="button"
                    class="ui-analytics-filter-pill"
                    :class="{ 'is-active': periodPreset === '30d' }"
                    @click="emit('preset', '30d')"
                >
                    {{ t('analytics.days30') }}
                </button>
            </div>

            <div class="ui-analytics-filters__dates">
                <input
                    :value="from"
                    type="date"
                    class="ui-analytics-input ui-analytics-input--date"
                    :aria-label="t('analytics.customDates')"
                    @input="emit('update:from', ($event.target as HTMLInputElement).value); emit('update:periodPreset', 'custom')"
                />
                <span class="ui-analytics-filters__dash" aria-hidden="true">—</span>
                <input
                    :value="to"
                    type="date"
                    class="ui-analytics-input ui-analytics-input--date"
                    @input="emit('update:to', ($event.target as HTMLInputElement).value); emit('update:periodPreset', 'custom')"
                />
            </div>

            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm ui-analytics-filters__reset" @click="emit('reset')">
                {{ t('analytics.resetAll') }}
            </button>
        </div>

        <div class="ui-analytics-filters__row ui-analytics-filters__grid">
            <label class="ui-analytics-field">
                <span class="ui-analytics-field__label">{{ t('analytics.department') }}</span>
                <select
                    :value="departmentId"
                    class="ui-analytics-input"
                    :disabled="isEmployee"
                    @change="emit('update:departmentId', ($event.target as HTMLSelectElement).value)"
                >
                    <option value="">{{ t('analytics.allDepartments') }}</option>
                    <option v-for="d in departments" :key="d.id" :value="String(d.id)">{{ d.name }}</option>
                </select>
            </label>
            <label class="ui-analytics-field">
                <span class="ui-analytics-field__label">{{ t('analytics.employee') }}</span>
                <select
                    :value="employeeId"
                    class="ui-analytics-input"
                    :disabled="isEmployee"
                    @change="emit('update:employeeId', ($event.target as HTMLSelectElement).value)"
                >
                    <option value="">{{ departmentId ? t('analytics.allInDepartment') : t('analytics.allEmployees') }}</option>
                    <option v-for="e in filteredEmployees" :key="e.id" :value="String(e.id)">{{ e.name }}</option>
                </select>
            </label>
            <label v-if="analyticsType === 'dialogs'" class="ui-analytics-field">
                <span class="ui-analytics-field__label">{{ t('analytics.chatStatus') }}</span>
                <select
                    :value="status"
                    class="ui-analytics-input"
                    @change="emit('update:status', ($event.target as HTMLSelectElement).value)"
                >
                    <option value="all">{{ t('analytics.statusAll') }}</option>
                    <option value="active">{{ t('analytics.statusActive') }}</option>
                    <option value="closed">{{ t('analytics.statusClosed') }}</option>
                    <option value="waiting">{{ t('analytics.statusWaiting') }}</option>
                </select>
            </label>
            <label v-if="analyticsType === 'dialogs'" class="ui-analytics-field">
                <span class="ui-analytics-field__label">{{ t('analytics.channel') }}</span>
                <select
                    :value="channel"
                    class="ui-analytics-input"
                    @change="emit('update:channel', ($event.target as HTMLSelectElement).value)"
                >
                    <option value="all">{{ t('analytics.channelAll') }}</option>
                    <option value="whatsapp">{{ t('analytics.channelWhatsapp') }}</option>
                    <option value="telegram">Telegram</option>
                    <option value="site">{{ t('analytics.channelSite') }}</option>
                </select>
            </label>
        </div>
    </section>
</template>
