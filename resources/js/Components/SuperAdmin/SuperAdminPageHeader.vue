<script setup lang="ts">
export type SuperAdminAccentGroup = 'overview' | 'operations' | 'billing' | 'platform';

defineProps<{
    eyebrow?: string;
    title: string;
    subtitle?: string;
    accentGroup?: SuperAdminAccentGroup;
}>();
</script>

<template>
    <header
        class="ui-super-admin-page-header"
        :class="accentGroup ? `ui-super-admin-page-header--${accentGroup}` : undefined"
    >
        <div class="ui-super-admin-page-header__intro">
            <p v-if="eyebrow" class="ui-super-admin-page-header__eyebrow">{{ eyebrow }}</p>
            <h1 class="ui-super-admin-page-header__title">{{ title }}</h1>
            <p v-if="subtitle" class="ui-super-admin-page-header__subtitle">{{ subtitle }}</p>
        </div>
        <div v-if="$slots.actions" class="ui-super-admin-page-header__actions">
            <slot name="actions" />
        </div>
    </header>
</template>
