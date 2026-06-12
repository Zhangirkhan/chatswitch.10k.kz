<script setup lang="ts">
import UiPillNav from '@/Components/Ui/UiPillNav.vue';
import AiSalesCompaniesTab from '@/Components/AiSales/tabs/AiSalesCompaniesTab.vue';
import AiSalesExperimentsTab from '@/Components/AiSales/tabs/AiSalesExperimentsTab.vue';
import AiSalesIntelligenceTab from '@/Components/AiSales/tabs/AiSalesIntelligenceTab.vue';
import AiSalesOutcomesTab from '@/Components/AiSales/tabs/AiSalesOutcomesTab.vue';
import AiSalesOverviewTab from '@/Components/AiSales/tabs/AiSalesOverviewTab.vue';
import AiSalesPipelineTab from '@/Components/AiSales/tabs/AiSalesPipelineTab.vue';
import type { AiSalesMetricsPayload, CompanyOption } from '@/Components/AiSales/types';
import { useI18n } from '@/composables/useI18n';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        metrics: AiSalesMetricsPayload;
        filters: { period: string; company_id?: number | null };
        companies?: CompanyOption[];
        baseUrl: string;
        i18nPrefix?: string;
        showCompanyFilter?: boolean;
        layout?: 'standalone' | 'embedded';
    }>(),
    {
        companies: () => [],
        i18nPrefix: 'superAdmin.aiSales',
        showCompanyFilter: false,
        layout: 'standalone',
    },
);

const { t, locale } = useI18n();

const period = ref(props.filters.period || '30d');
const companyId = ref(props.filters.company_id ? String(props.filters.company_id) : '');
const activeTab = ref('overview');

const periodOptions = computed(() => [
    { id: '7d', label: t(`${props.i18nPrefix}.period7d`) },
    { id: '30d', label: t(`${props.i18nPrefix}.period30d`) },
    { id: '90d', label: t(`${props.i18nPrefix}.period90d`) },
]);

const tabOptions = computed(() => {
    const tabs = [
        { id: 'overview', label: t(`${props.i18nPrefix}.tabOverview`) },
        { id: 'pipeline', label: t(`${props.i18nPrefix}.tabPipeline`) },
        { id: 'outcomes', label: t(`${props.i18nPrefix}.tabOutcomes`) },
        { id: 'intelligence', label: t(`${props.i18nPrefix}.tabIntelligence`) },
    ];

    if ((props.metrics.experiments?.length ?? 0) > 0) {
        tabs.push({ id: 'experiments', label: t(`${props.i18nPrefix}.tabExperiments`) });
    }

    if (props.showCompanyFilter) {
        tabs.push({ id: 'companies', label: t(`${props.i18nPrefix}.tabCompanies`) });
    }

    return tabs;
});

const dateLocale = computed(() => (locale.value === 'kk' ? 'kk-KZ' : locale.value === 'en' ? 'en-GB' : 'ru-RU'));

const periodLabel = computed(() => {
    try {
        const from = new Date(props.metrics.period.from);
        const to = new Date(props.metrics.period.to);
        const fmt = new Intl.DateTimeFormat(dateLocale.value, { dateStyle: 'medium' });
        return `${fmt.format(from)} — ${fmt.format(to)}`;
    } catch {
        return '';
    }
});

function applyFilters(): void {
    router.get(
        props.baseUrl,
        {
            period: period.value,
            company_id: props.showCompanyFilter && companyId.value !== '' ? companyId.value : undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function onFilterCompany(id: number): void {
    companyId.value = String(id);
    applyFilters();
}
</script>

<template>
    <div class="ui-ai-sales-page" :class="{ 'ui-ai-sales-page--embedded': layout === 'embedded' }">
        <header v-if="layout === 'standalone'" class="ui-ai-sales-page__header">
            <div class="ui-analytics-page__intro">
                <p class="ui-analytics-page__eyebrow">{{ t(`${i18nPrefix}.pageTitle`) }}</p>
                <h1 class="ui-analytics-page__title">{{ t(`${i18nPrefix}.title`) }}</h1>
                <p class="ui-analytics-page__subtitle">{{ t(`${i18nPrefix}.subtitle`) }}</p>
                <p v-if="periodLabel" class="mt-1 text-xs text-ui-text-muted">{{ periodLabel }}</p>
            </div>

            <div class="ui-ai-sales-page__filters">
                <UiPillNav class="shrink-0">
                    <button
                        v-for="option in periodOptions"
                        :key="option.id"
                        type="button"
                        class="ui-pill-nav__item"
                        :class="{ 'is-active': period === option.id }"
                        @click="period = option.id; applyFilters()"
                    >
                        {{ option.label }}
                    </button>
                </UiPillNav>

                <select
                    v-if="showCompanyFilter"
                    v-model="companyId"
                    class="ui-input min-w-[220px]"
                    :aria-label="t(`${i18nPrefix}.companyFilter`)"
                    @change="applyFilters()"
                >
                    <option value="">{{ t(`${i18nPrefix}.allCompanies`) }}</option>
                    <option v-for="company in companies" :key="company.id" :value="String(company.id)">
                        {{ company.name }} ({{ company.slug }})
                    </option>
                </select>
            </div>
        </header>

        <div v-else class="ui-ai-sales-page__toolbar">
            <p v-if="periodLabel" class="ui-ai-sales-page__period text-sm text-ui-text-muted">{{ periodLabel }}</p>
            <div class="ui-ai-sales-page__filters">
                <UiPillNav class="shrink-0">
                    <button
                        v-for="option in periodOptions"
                        :key="option.id"
                        type="button"
                        class="ui-pill-nav__item"
                        :class="{ 'is-active': period === option.id }"
                        @click="period = option.id; applyFilters()"
                    >
                        {{ option.label }}
                    </button>
                </UiPillNav>

                <select
                    v-if="showCompanyFilter"
                    v-model="companyId"
                    class="ui-input min-w-[220px]"
                    :aria-label="t(`${i18nPrefix}.companyFilter`)"
                    @change="applyFilters()"
                >
                    <option value="">{{ t(`${i18nPrefix}.allCompanies`) }}</option>
                    <option v-for="company in companies" :key="company.id" :value="String(company.id)">
                        {{ company.name }} ({{ company.slug }})
                    </option>
                </select>
            </div>
        </div>

        <p class="ui-alert ui-ai-sales-page__disclaimer border-ui-border bg-ui-surface-soft text-sm text-ui-text-secondary">
            {{ t(`${i18nPrefix}.disclaimer`) }}
        </p>

        <UiPillNav class="ui-ai-sales-tabs">
            <button
                v-for="tab in tabOptions"
                :key="tab.id"
                type="button"
                class="ui-pill-nav__item"
                :class="{ 'is-active': activeTab === tab.id }"
                @click="activeTab = tab.id"
            >
                {{ tab.label }}
            </button>
        </UiPillNav>

        <AiSalesOverviewTab
            v-if="activeTab === 'overview'"
            :metrics="metrics"
            :i18n-prefix="i18nPrefix"
        />
        <AiSalesPipelineTab
            v-else-if="activeTab === 'pipeline'"
            :metrics="metrics"
            :i18n-prefix="i18nPrefix"
        />
        <AiSalesOutcomesTab
            v-else-if="activeTab === 'outcomes'"
            :metrics="metrics"
            :i18n-prefix="i18nPrefix"
        />
        <AiSalesIntelligenceTab
            v-else-if="activeTab === 'intelligence'"
            :metrics="metrics"
            :i18n-prefix="i18nPrefix"
        />
        <AiSalesExperimentsTab
            v-else-if="activeTab === 'experiments'"
            :metrics="metrics"
            :i18n-prefix="i18nPrefix"
        />
        <AiSalesCompaniesTab
            v-else-if="activeTab === 'companies'"
            :metrics="metrics"
            :i18n-prefix="i18nPrefix"
            @filter-company="onFilterCompany"
        />
    </div>
</template>
