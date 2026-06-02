<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/composables/useI18n';
import { computed, ref } from 'vue';
import Avatar from '@/Components/Avatar.vue';
import OrganizationLayout from '@/Layouts/OrganizationLayout.vue';
import type { OrgDepartment } from './Partials/OrganizationSidebar.vue';

const { t, locale } = useI18n();
const page = usePage();

const props = defineProps<{
    departments: OrgDepartment[];
}>();

type FilterKey = 'all' | 'open' | 'in_progress' | 'done';

const activeFilter = ref<FilterKey>('all');

const totalOpen = computed(() => props.departments.reduce((s, d) => s + d.open_count, 0));
const totalInProgress = computed(() => props.departments.reduce((s, d) => s + d.in_progress_count, 0));
const totalDone = computed(() => props.departments.reduce((s, d) => s + d.done_count, 0));
const totalAll = computed(() => totalOpen.value + totalInProgress.value + totalDone.value);

const orgOpenBadge = computed(() => Number(page.props.orgOpenTasksCount ?? 0));

const showScopeHint = computed(
    () => orgOpenBadge.value > 0 && totalOpen.value + totalInProgress.value !== orgOpenBadge.value,
);

const filteredDepartments = computed(() => {
    if (activeFilter.value === 'all') {
        return props.departments;
    }

    return props.departments.filter((d) => {
        if (activeFilter.value === 'open') {
            return d.open_count > 0;
        }
        if (activeFilter.value === 'in_progress') {
            return d.in_progress_count > 0;
        }
        if (activeFilter.value === 'done') {
            return d.done_count > 0;
        }

        return true;
    });
});

const filteredTasksTotal = computed(() => {
    if (activeFilter.value === 'all') {
        return totalAll.value;
    }
    if (activeFilter.value === 'open') {
        return totalOpen.value;
    }
    if (activeFilter.value === 'in_progress') {
        return totalInProgress.value;
    }
    if (activeFilter.value === 'done') {
        return totalDone.value;
    }

    return 0;
});

function setFilter(key: FilterKey) {
    activeFilter.value = key;
}

function deptLabel(count: number): string {
    if (locale.value === 'ru') {
        if (count === 1) {
            return t('organization.deptLabelOne');
        }
        if (count >= 2 && count <= 4) {
            return t('organization.deptLabelFew');
        }

        return t('organization.deptLabelMany');
    }

    return count === 1 ? t('organization.deptLabelOne') : t('organization.deptLabelMany');
}

function filterSummaryText(): string {
    if (activeFilter.value !== 'all') {
        const deptCount = filteredDepartments.value.length;

        return t('organization.indexFilterSummary', {
            deptCount,
            deptLabel: deptLabel(deptCount),
            taskCount: filteredTasksTotal.value,
            taskLabel: t('organization.taskLabelMany'),
        });
    }

    return t('organization.indexDeptSummary', {
        count: props.departments.length,
        deptLabel: deptLabel(props.departments.length),
    });
}
</script>

<template>
    <Head :title="t('organization.title')" />
    <OrganizationLayout :departments="departments" :selected-department-id="null">
        <div class="ui-org-shell">
            <div class="ui-kpi-row">
                <button
                    type="button"
                    class="ui-kpi-tile"
                    data-status="all"
                    :class="{ 'is-active': activeFilter === 'all' }"
                    :aria-pressed="activeFilter === 'all'"
                    @click="setFilter('all')"
                >
                    <div class="ui-kpi-tile__icon" data-status="all">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                        </svg>
                    </div>
                    <div class="ui-kpi-tile__value">{{ totalAll }}</div>
                    <div class="ui-kpi-tile__label">{{ t('organization.indexKpiTotal') }}</div>
                </button>

                <button
                    type="button"
                    class="ui-kpi-tile"
                    data-status="open"
                    :class="{ 'is-active': activeFilter === 'open' }"
                    :aria-pressed="activeFilter === 'open'"
                    @click="setFilter('open')"
                >
                    <div class="ui-kpi-tile__icon" data-status="open">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="9" />
                        </svg>
                    </div>
                    <div class="ui-kpi-tile__value">{{ totalOpen }}</div>
                    <div class="ui-kpi-tile__label">{{ t('organization.indexKpiOpen') }}</div>
                </button>

                <button
                    type="button"
                    class="ui-kpi-tile"
                    data-status="in_progress"
                    :class="{ 'is-active': activeFilter === 'in_progress' }"
                    :aria-pressed="activeFilter === 'in_progress'"
                    @click="setFilter('in_progress')"
                >
                    <div class="ui-kpi-tile__icon" data-status="in_progress">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ui-kpi-tile__value">{{ totalInProgress }}</div>
                    <div class="ui-kpi-tile__label">{{ t('organization.indexKpiInProgress') }}</div>
                </button>

                <button
                    type="button"
                    class="ui-kpi-tile"
                    data-status="done"
                    :class="{ 'is-active': activeFilter === 'done' }"
                    :aria-pressed="activeFilter === 'done'"
                    @click="setFilter('done')"
                >
                    <div class="ui-kpi-tile__icon" data-status="done">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                    </div>
                    <div class="ui-kpi-tile__value">{{ totalDone }}</div>
                    <div class="ui-kpi-tile__label">{{ t('organization.indexKpiDone') }}</div>
                </button>
            </div>

            <p class="ui-org-hint">{{ filterSummaryText() }}</p>
            <p v-if="showScopeHint" class="ui-org-hint">
                {{ t('organization.indexScopeHint', { count: orgOpenBadge }) }}
            </p>

            <div v-if="departments.length === 0" class="ui-empty-state ui-empty-state--org">
                <div class="ui-empty-state__icon">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M5 21V7l7-4 7 4v14M9 21V12h6v9" />
                    </svg>
                </div>
                <p class="text-sm text-[var(--wa-text-secondary)] m-0">{{ t('organization.noDepartmentsAvailable') }}</p>
            </div>

            <div v-else-if="filteredDepartments.length === 0" class="ui-empty-state ui-empty-state--org">
                <div class="ui-empty-state__icon">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                    </svg>
                </div>
                <p class="text-sm text-[var(--wa-text-secondary)] m-0">{{ t('organization.indexNoDeptsFiltered') }}</p>
                <button type="button" class="ui-btn ui-btn--secondary ui-btn--pill" @click="setFilter('all')">
                    {{ t('organization.indexResetFilter') }}
                </button>
            </div>

            <div v-else class="ui-org-dept-grid">
                <Link
                    v-for="dept in filteredDepartments"
                    :key="dept.id"
                    :href="route('organization.departments.show', dept.id)"
                    class="ui-task-card"
                >
                    <div class="ui-task-card__head">
                        <Avatar :name="dept.name" :size="36" variant="group" />
                        <div class="ui-task-card__title truncate">{{ dept.name }}</div>
                    </div>

                    <div v-if="dept.description" class="ui-task-card__desc">{{ dept.description }}</div>

                    <div class="ui-task-card__badges">
                        <span
                            v-if="dept.in_progress_count > 0"
                            class="ui-task-status ui-task-status--in_progress"
                            :class="{ 'ui-task-status--highlighted': activeFilter === 'in_progress' }"
                            :title="t('organization.indexTitleInProgress')"
                        >
                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd" />
                            </svg>
                            {{ t('organization.indexBadgeInProgress', { count: dept.in_progress_count }) }}
                        </span>
                        <span
                            v-if="dept.open_count > 0"
                            class="ui-task-status ui-task-status--open"
                            :class="{ 'ui-task-status--highlighted': activeFilter === 'open' }"
                            :title="t('organization.indexTitleOpen')"
                        >
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="9" />
                            </svg>
                            {{ t('organization.indexBadgeOpen', { count: dept.open_count }) }}
                        </span>
                        <span
                            v-if="dept.done_count > 0"
                            class="ui-task-status ui-task-status--done"
                            :class="{ 'ui-task-status--highlighted': activeFilter === 'done' }"
                            :title="t('organization.indexTitleDone')"
                        >
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                            {{ t('organization.indexBadgeDone', { count: dept.done_count }) }}
                        </span>
                        <span
                            v-if="dept.open_count === 0 && dept.in_progress_count === 0 && dept.done_count === 0"
                            class="ui-tab-badge ui-tab-badge--neutral"
                        >{{ t('organization.indexNoTasks') }}</span>
                    </div>

                    <div class="ui-task-card__footer">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                        </svg>
                    </div>
                </Link>
            </div>
        </div>
    </OrganizationLayout>
</template>
