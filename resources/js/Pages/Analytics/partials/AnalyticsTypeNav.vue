<script setup lang="ts">
import UiPillNav from '@/Components/Ui/UiPillNav.vue';
import { useI18n } from '@/composables/useI18n';
import { Link } from '@inertiajs/vue3';

export type AnalyticsNavType = 'dialogs' | 'funnels' | 'ai-sales';

const props = withDefaults(
    defineProps<{
        activeType: AnalyticsNavType;
        analyticsModuleEnabled: boolean;
        funnelsModuleEnabled: boolean;
        aiSalesEnabled?: boolean;
        variant?: 'inline' | 'routes';
    }>(),
    {
        aiSalesEnabled: false,
        variant: 'inline',
    },
);

const emit = defineEmits<{
    change: [type: 'dialogs' | 'funnels'];
}>();

const { t } = useI18n();

function onInlineTypeClick(type: 'dialogs' | 'funnels'): void {
    emit('change', type);
}
</script>

<template>
    <div class="ui-analytics-type-nav">
        <span class="ui-analytics-type-nav__label">{{ t('analytics.typeLabel') }}</span>
        <UiPillNav>
            <template v-if="variant === 'routes'">
                <Link
                    v-if="analyticsModuleEnabled"
                    :href="route('analytics.dialogs')"
                    class="ui-pill-nav__item"
                    :class="{ 'is-active': activeType === 'dialogs' }"
                >
                    {{ t('analytics.typeDialogs') }}
                </Link>
                <Link
                    v-if="funnelsModuleEnabled"
                    :href="route('analytics.dialogs')"
                    class="ui-pill-nav__item"
                    :class="{ 'is-active': activeType === 'funnels' }"
                >
                    {{ t('analytics.typeFunnels') }}
                </Link>
            </template>
            <template v-else>
                <button
                    v-if="analyticsModuleEnabled"
                    type="button"
                    class="ui-pill-nav__item"
                    :class="{ 'is-active': activeType === 'dialogs' }"
                    @click="onInlineTypeClick('dialogs')"
                >
                    {{ t('analytics.typeDialogs') }}
                </button>
                <button
                    v-if="funnelsModuleEnabled"
                    type="button"
                    class="ui-pill-nav__item"
                    :class="{ 'is-active': activeType === 'funnels' }"
                    @click="onInlineTypeClick('funnels')"
                >
                    {{ t('analytics.typeFunnels') }}
                </button>
            </template>
            <Link
                v-if="aiSalesEnabled"
                :href="route('analytics.ai-sales')"
                class="ui-pill-nav__item"
                :class="{ 'is-active': activeType === 'ai-sales' }"
            >
                {{ t('analytics.typeAiSales') }}
            </Link>
        </UiPillNav>
    </div>
</template>
