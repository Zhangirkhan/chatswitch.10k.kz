<script setup lang="ts">
import AiSalesMetricsPanel, { type AiSalesMetricsPayload, type CompanyOption } from '@/Components/AiSales/AiSalesMetricsPanel.vue';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { Head } from '@inertiajs/vue3';

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

        <AiSalesMetricsPanel
            :metrics="metrics"
            :filters="filters"
            :companies="companies"
            base-url="/ai-sales"
            i18n-prefix="superAdmin.aiSales"
            show-company-filter
        />
    </SuperAdminLayout>
</template>
