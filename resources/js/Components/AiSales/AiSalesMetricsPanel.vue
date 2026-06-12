<script setup lang="ts">
import AiSalesDashboardShell from '@/Components/AiSales/AiSalesDashboardShell.vue';
import type { AiSalesMetricsPayload, CompanyOption } from '@/Components/AiSales/types';

export type { AiSalesMetricsPayload, CompanyOption, Kpi, LostReason, WinRateGrade, ExperimentRow } from '@/Components/AiSales/types';

defineProps<{
    metrics: AiSalesMetricsPayload;
    filters: { period: string; company_id?: number | null };
    companies?: CompanyOption[];
    baseUrl: string;
    i18nPrefix?: string;
    showCompanyFilter?: boolean;
}>();
</script>

<template>
    <AiSalesDashboardShell
        :metrics="metrics"
        :filters="filters"
        :companies="companies"
        :base-url="baseUrl"
        :i18n-prefix="i18nPrefix"
        :show-company-filter="showCompanyFilter"
    />
</template>
