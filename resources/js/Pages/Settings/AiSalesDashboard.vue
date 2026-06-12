<script setup lang="ts">
import AiSalesMetricsPanel, { type AiSalesMetricsPayload, type CompanyOption } from '@/Components/AiSales/AiSalesMetricsPanel.vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { Head } from '@inertiajs/vue3';

defineProps<{
    metrics: AiSalesMetricsPayload;
    filters: {
        period: string;
    };
}>();

const { t } = useI18n();
</script>

<template>
    <SettingsLayout>
        <Head :title="t('settings.aiSales.pageTitle')" />

        <div class="w-full px-6 py-6">
            <AiSalesMetricsPanel
                :metrics="metrics"
                :filters="filters"
                base-url="/settings/ai-sales"
                i18n-prefix="settings.aiSales"
                class="ui-ai-sales-page"
            />
        </div>
    </SettingsLayout>
</template>
