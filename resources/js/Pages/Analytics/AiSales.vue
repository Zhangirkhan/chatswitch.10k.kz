<script setup lang="ts">
import AiSalesMetricsPanel, { type AiSalesMetricsPayload } from '@/Components/AiSales/AiSalesMetricsPanel.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AnalyticsTypeNav from '@/Pages/Analytics/partials/AnalyticsTypeNav.vue';
import { useI18n } from '@/composables/useI18n';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    metrics: AiSalesMetricsPayload;
    filters: {
        period: string;
    };
}>();

const { t } = useI18n();
const page = usePage();

const roles = computed(() => (page.props as { auth?: { user?: { roles?: string[] } } }).auth?.user?.roles ?? []);
const isAdministrator = computed(() => roles.value.includes('administrator'));
const analyticsModuleEnabled = computed<boolean>(() => Boolean((page.props as { modules?: { analytics?: boolean } }).modules?.analytics ?? true));
const funnelsModuleEnabled = computed<boolean>(() => Boolean((page.props as { modules?: { funnels?: boolean } }).modules?.funnels ?? true));
const aiSalesEnabled = computed(() => isAdministrator.value && Boolean((page.props as { modules?: { ai_quality?: boolean } }).modules?.ai_quality ?? true));
</script>

<template>
    <Head :title="t('settings.aiSales.pageTitle')" />
    <AuthenticatedLayout>
        <div class="ui-tool-page ui-analytics-page">
            <header class="ui-tool-page__header ui-analytics-page__header">
                <div class="ui-analytics-page__intro">
                    <p class="ui-analytics-page__eyebrow">{{ t('analytics.overview') }}</p>
                    <h1 class="ui-analytics-page__title">{{ t('settings.aiSales.title') }}</h1>
                    <p class="ui-analytics-page__subtitle">{{ t('settings.aiSales.subtitle') }}</p>
                </div>

                <AnalyticsTypeNav
                    active-type="ai-sales"
                    variant="routes"
                    :analytics-module-enabled="analyticsModuleEnabled"
                    :funnels-module-enabled="funnelsModuleEnabled"
                    :ai-sales-enabled="aiSalesEnabled"
                />
            </header>

            <div class="ui-tool-page__main ui-analytics-page__main wa-scrollbar">
                <AiSalesMetricsPanel
                    :metrics="metrics"
                    :filters="filters"
                    base-url="/analytics/ai-sales"
                    i18n-prefix="settings.aiSales"
                    layout="embedded"
                    class="ui-ai-sales-page ui-ai-sales-page--embedded"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
