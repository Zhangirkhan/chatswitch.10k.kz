<script setup lang="ts">
import UiPillNav from '@/Components/Ui/UiPillNav.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '@/composables/useI18n';
import AnalyticsFilters from './partials/AnalyticsFilters.vue';
import AnalyticsTypeNav from './partials/AnalyticsTypeNav.vue';
import DialogsDynamicsTab from './partials/DialogsDynamicsTab.vue';
import DialogsOverviewTab from './partials/DialogsOverviewTab.vue';
import DialogsProblemsTab from './partials/DialogsProblemsTab.vue';
import DialogsTeamTab from './partials/DialogsTeamTab.vue';
import FunnelsConversionTab from './partials/FunnelsConversionTab.vue';
import FunnelsCoverageTab from './partials/FunnelsCoverageTab.vue';
import FunnelsOverviewTab from './partials/FunnelsOverviewTab.vue';
import type { DialogSummary, FilterOptions, FunnelSummary } from './types';
import { useAnalyticsData } from './useAnalyticsData';

const props = defineProps<{
    filterOptions: FilterOptions;
}>();

const page = usePage();
const { t } = useI18n();

const roles = computed(() => (page.props as { auth?: { user?: { roles?: string[] } } }).auth?.user?.roles || []);
const isEmployee = computed(() => roles.value.includes('employee') && !roles.value.includes('administrator'));
const funnelsModuleEnabled = computed<boolean>(() => Boolean((page.props as { modules?: { funnels?: boolean } }).modules?.funnels ?? true));
const analyticsModuleEnabled = computed<boolean>(() => Boolean((page.props as { modules?: { analytics?: boolean } }).modules?.analytics ?? true));

const analytics = useAnalyticsData(props.filterOptions, analyticsModuleEnabled, funnelsModuleEnabled);

const contextLabel = computed(() => {
    const dept = analytics.departmentId.value
        ? props.filterOptions.departments.find((d) => String(d.id) === analytics.departmentId.value)
        : null;
    const emp = analytics.employeeId.value
        ? props.filterOptions.employees.find((e) => String(e.id) === analytics.employeeId.value)
        : null;
    if (!dept && !emp) {
        return t('analytics.sliceAll');
    }
    if (dept && !emp) {
        return t('analytics.sliceDept', { dept: dept.name });
    }
    if (dept && emp) {
        return t('analytics.sliceDeptEmployee', { dept: dept.name, employee: emp.name });
    }
    if (!dept && emp) {
        return t('analytics.sliceEmployee', { employee: emp.name });
    }

    return '';
});

const pageTitle = computed(() => (analytics.analyticsType.value === 'dialogs' ? t('analytics.dialogsTitle') : t('analytics.funnelsTitle')));
const pageSubtitle = computed(() =>
    analytics.analyticsType.value === 'dialogs'
        ? t('analytics.dialogsIntro', { seconds: props.filterOptions.sla_seconds })
        : t('analytics.funnelsIntro'),
);

const dialogSummary = computed(() => analytics.summary.value as DialogSummary);
const funnelSummary = computed(() => analytics.summary.value as FunnelSummary);

function onPreset(value: 'today' | '7d' | '30d') {
    analytics.applyPreset(value);
}

function onResetFilters() {
    analytics.resetFilters();
}

function onProblemPrev() {
    if (analytics.problemPage.value > 1) {
        analytics.problemPage.value -= 1;
    }
}

function onProblemNext() {
    if (analytics.problemPage.value < analytics.problemMeta.value.last_page) {
        analytics.problemPage.value += 1;
    }
}
</script>

<template>
    <Head :title="pageTitle" />
    <AuthenticatedLayout>
        <div class="ui-tool-page ui-analytics-page">
            <header class="ui-tool-page__header ui-analytics-page__header">
                <div class="ui-analytics-page__intro">
                    <p class="ui-analytics-page__eyebrow">{{ t('analytics.overview') }}</p>
                    <h1 class="ui-analytics-page__title">{{ pageTitle }}</h1>
                    <p class="ui-analytics-page__subtitle">{{ pageSubtitle }}</p>
                    <div class="ui-analytics-page__slice">
                        <span>{{ t('analytics.slice') }}</span>
                        <strong>{{ contextLabel }}</strong>
                    </div>
                </div>

                <AnalyticsTypeNav
                    :analytics-type="analytics.analyticsType.value"
                    :analytics-module-enabled="analyticsModuleEnabled"
                    :funnels-module-enabled="funnelsModuleEnabled"
                    @change="analytics.setAnalyticsType"
                />
            </header>

            <div class="ui-tool-page__main ui-analytics-page__main wa-scrollbar">
                <div class="ui-analytics-page__sticky-bar">
                    <AnalyticsFilters
                        :analytics-type="analytics.analyticsType.value"
                    :period-preset="analytics.periodPreset.value"
                    :from="analytics.from.value"
                    :to="analytics.to.value"
                    :department-id="analytics.departmentId.value"
                    :employee-id="analytics.employeeId.value"
                    :status="analytics.status.value"
                    :channel="analytics.channel.value"
                    :filtered-employees="analytics.filteredEmployees.value"
                    :departments="filterOptions.departments"
                    :is-employee="isEmployee"
                    @update:from="analytics.from.value = $event"
                    @update:to="analytics.to.value = $event"
                    @update:department-id="analytics.departmentId.value = $event"
                    @update:employee-id="analytics.employeeId.value = $event"
                    @update:status="analytics.status.value = $event"
                    @update:channel="analytics.channel.value = $event"
                    @update:period-preset="analytics.periodPreset.value = $event"
                    @preset="onPreset"
                    @reset="onResetFilters"
                    />

                    <div class="ui-analytics-page__tabs">
                    <UiPillNav v-if="analytics.analyticsType.value === 'dialogs'">
                        <button
                            type="button"
                            class="ui-pill-nav__item"
                            :class="{ 'is-active': analytics.dialogsTab.value === 'overview' }"
                            @click="analytics.setDialogsTab('overview')"
                        >
                            {{ t('analytics.tabOverview') }}
                        </button>
                        <button
                            type="button"
                            class="ui-pill-nav__item"
                            :class="{ 'is-active': analytics.dialogsTab.value === 'dynamics' }"
                            @click="analytics.setDialogsTab('dynamics')"
                        >
                            {{ t('analytics.tabDynamics') }}
                        </button>
                        <button
                            type="button"
                            class="ui-pill-nav__item"
                            :class="{ 'is-active': analytics.dialogsTab.value === 'team' }"
                            @click="analytics.setDialogsTab('team')"
                        >
                            {{ t('analytics.tabTeam') }}
                        </button>
                        <button
                            type="button"
                            class="ui-pill-nav__item"
                            :class="{ 'is-active': analytics.dialogsTab.value === 'problems' }"
                            @click="analytics.setDialogsTab('problems')"
                        >
                            {{ t('analytics.tabProblems') }}
                            <span v-if="analytics.problemMeta.value.total > 0" class="ui-analytics-tab-badge">
                                {{ analytics.problemMeta.value.total > 99 ? '99+' : analytics.problemMeta.value.total }}
                            </span>
                        </button>
                    </UiPillNav>

                    <UiPillNav v-else>
                        <button
                            type="button"
                            class="ui-pill-nav__item"
                            :class="{ 'is-active': analytics.funnelsTab.value === 'overview' }"
                            @click="analytics.setFunnelsTab('overview')"
                        >
                            {{ t('analytics.tabOverview') }}
                        </button>
                        <button
                            type="button"
                            class="ui-pill-nav__item"
                            :class="{ 'is-active': analytics.funnelsTab.value === 'conversion' }"
                            @click="analytics.setFunnelsTab('conversion')"
                        >
                            {{ t('analytics.tabConversion') }}
                        </button>
                        <button
                            type="button"
                            class="ui-pill-nav__item"
                            :class="{ 'is-active': analytics.funnelsTab.value === 'coverage' }"
                            @click="analytics.setFunnelsTab('coverage')"
                        >
                            {{ t('analytics.tabCoverage') }}
                        </button>
                    </UiPillNav>
                </div>
                </div>

                <div class="ui-analytics-page__body">
                <div
                    v-if="analytics.error.value"
                    class="ui-alert ui-analytics-error"
                >
                    {{ analytics.error.value }}
                </div>

                <div v-if="analytics.loading.value" class="ui-analytics-skeleton">
                    <div class="ui-analytics-skeleton__kpis">
                        <div v-for="n in 6" :key="n" class="ui-analytics-skeleton__block" />
                    </div>
                    <div class="ui-analytics-skeleton__chart ui-analytics-skeleton__block" />
                </div>

                <template v-else-if="analytics.payload.value">
                    <div
                        v-if="analytics.isEmpty.value"
                        class="ui-panel ui-analytics-empty"
                    >
                        <p class="ui-analytics-empty__title">
                            {{ analytics.analyticsType.value === 'funnels' ? t('analytics.noFunnels') : t('analytics.emptyTitle') }}
                        </p>
                        <p class="ui-analytics-empty__hint">
                            {{ analytics.analyticsType.value === 'funnels' ? t('analytics.noFunnelsHint') : t('analytics.emptyHint') }}
                        </p>
                    </div>

                    <template v-else-if="analytics.analyticsType.value === 'dialogs'">
                        <DialogsOverviewTab
                            v-if="analytics.dialogsTab.value === 'overview'"
                            :summary="dialogSummary"
                            :line-chart-data="analytics.lineChartData.value"
                            :doughnut-status="analytics.doughnutStatus.value"
                            :chart-opts="analytics.chartOpts.value"
                            :doughnut-opts="analytics.doughnutOpts.value"
                            :fmt-sec="analytics.fmtSec"
                            :fmt-pct="analytics.fmtPct"
                        />
                        <DialogsDynamicsTab
                            v-else-if="analytics.dialogsTab.value === 'dynamics'"
                            :line-chart-data="analytics.lineChartData.value"
                            :line-avg-resp="analytics.lineAvgResp.value"
                            :bar-load="analytics.barLoad.value"
                            :doughnut-status="analytics.doughnutStatus.value"
                            :chart-opts="analytics.chartOpts.value"
                            :doughnut-opts="analytics.doughnutOpts.value"
                        />
                        <DialogsTeamTab
                            v-else-if="analytics.dialogsTab.value === 'team'"
                            :ranking-blocks="analytics.rankingBlocks.value"
                            :ranking-rows="analytics.rankingRows"
                            :employee-stats="analytics.employeeStats.value"
                            :dept-stats="analytics.deptStats.value"
                            :fmt-sec="analytics.fmtSec"
                            :fmt-pct="analytics.fmtPct"
                            :toggle-emp-sort="analytics.toggleEmpSort"
                            :toggle-dept-sort="analytics.toggleDeptSort"
                        />
                        <DialogsProblemsTab
                            v-else
                            :problematic="analytics.problematic.value"
                            :problem-meta="analytics.problemMeta.value"
                            :fmt-sec="analytics.fmtSec"
                            @prev="onProblemPrev"
                            @next="onProblemNext"
                        />
                    </template>

                    <template v-else>
                        <FunnelsOverviewTab
                            v-if="analytics.funnelsTab.value === 'overview'"
                            :summary="funnelSummary"
                            :fmt-pct="analytics.fmtPct"
                        />
                        <FunnelsConversionTab
                            v-else-if="analytics.funnelsTab.value === 'conversion'"
                            :conversion-funnels="analytics.conversionFunnels.value"
                            :chart-opts="analytics.chartOpts.value"
                            :conversion-bar-data="analytics.conversionBarData"
                            :fmt-pct="analytics.fmtPct"
                            :fmt-minutes="analytics.fmtMinutes"
                            :fmt-hours="analytics.fmtHours"
                        />
                        <FunnelsCoverageTab
                            v-else
                            :funnel-rows="analytics.funnelRows.value"
                            :fmt-pct="analytics.fmtPct"
                        />
                    </template>
                </template>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
