<script setup lang="ts">
import AiSalesMetricsPanel, { type AiSalesMetricsPayload, type CompanyOption } from '@/Components/AiSales/AiSalesMetricsPanel.vue';
import SuperAdminPageHeader from '@/Components/SuperAdmin/SuperAdminPageHeader.vue';
import { useI18n } from '@/composables/useI18n';
import { Head } from '@inertiajs/vue3';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';

defineProps<{
    metrics: AiSalesMetricsPayload;
    companies: CompanyOption[];
    filters: {
        period: string;
        company_id: number | null;
    };
}>();

const { t } = useI18n();
</script>

<template>
    <SuperAdminLayout>
        <Head :title="t('superAdmin.aiSales.pageTitle')" />

        <SuperAdminPageHeader
            accent-group="operations"
            :eyebrow="t('superAdmin.layout.navGroups.operations')"
            :title="t('superAdmin.aiSales.title')"
            :subtitle="t('superAdmin.aiSales.subtitle')"
        />

        <div class="ui-super-admin-embedded">
            <AiSalesMetricsPanel
                :metrics="metrics"
                :filters="filters"
                :companies="companies"
                base-url="/ai-sales"
                i18n-prefix="superAdmin.aiSales"
                show-company-filter
                layout="embedded"
                class="ui-ai-sales-page ui-ai-sales-page--embedded"
            />
        </div>
    
    </SuperAdminLayout>
</template>
