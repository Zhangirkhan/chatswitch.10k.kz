<script setup lang="ts">
import UiPillNav from '@/Components/Ui/UiPillNav.vue';
import { useI18n } from '@/composables/useI18n';
import type { AnalyticsType } from '../types';

defineProps<{
    analyticsType: AnalyticsType;
    analyticsModuleEnabled: boolean;
    funnelsModuleEnabled: boolean;
}>();

const emit = defineEmits<{
    change: [type: AnalyticsType];
}>();

const { t } = useI18n();
</script>

<template>
    <div class="ui-analytics-type-nav">
        <span class="ui-analytics-type-nav__label">{{ t('analytics.typeLabel') }}</span>
        <UiPillNav>
            <button
                v-if="analyticsModuleEnabled"
                type="button"
                class="ui-pill-nav__item"
                :class="{ 'is-active': analyticsType === 'dialogs' }"
                @click="emit('change', 'dialogs')"
            >
                {{ t('analytics.typeDialogs') }}
            </button>
            <button
                v-if="funnelsModuleEnabled"
                type="button"
                class="ui-pill-nav__item"
                :class="{ 'is-active': analyticsType === 'funnels' }"
                @click="emit('change', 'funnels')"
            >
                {{ t('analytics.typeFunnels') }}
            </button>
        </UiPillNav>
    </div>
</template>
