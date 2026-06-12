<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

export type SuperAdminKpiTone =
    | 'default'
    | 'accent'
    | 'info'
    | 'billing'
    | 'platform'
    | 'highlight'
    | 'danger';

export type SuperAdminKpiItem = {
    label: string;
    value: string | number;
    hint?: string;
    href?: string;
    tone?: SuperAdminKpiTone;
};

defineProps<{
    items: SuperAdminKpiItem[];
}>();
</script>

<template>
    <div class="ui-super-admin-kpi-grid">
        <component
            :is="item.href ? Link : 'article'"
            v-for="(item, index) in items"
            :key="`${item.label}-${index}`"
            :href="item.href"
            class="ui-super-admin-kpi-card"
            :class="{
                'ui-super-admin-kpi-card--accent': item.tone === 'accent',
                'ui-super-admin-kpi-card--info': item.tone === 'info',
                'ui-super-admin-kpi-card--billing': item.tone === 'billing',
                'ui-super-admin-kpi-card--platform': item.tone === 'platform',
                'ui-super-admin-kpi-card--highlight': item.tone === 'highlight',
                'ui-super-admin-kpi-card--danger': item.tone === 'danger',
                'ui-super-admin-kpi-card--link': !!item.href,
            }"
        >
            <span class="ui-super-admin-kpi-card__label">{{ item.label }}</span>
            <strong class="ui-super-admin-kpi-card__value">{{ item.value }}</strong>
            <p v-if="item.hint" class="ui-super-admin-kpi-card__hint">{{ item.hint }}</p>
        </component>
    </div>
</template>
