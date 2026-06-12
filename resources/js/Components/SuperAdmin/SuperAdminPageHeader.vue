<script setup lang="ts">
defineProps<{
    eyebrow?: string;
    title: string;
    subtitle?: string;
}>();
</script>

<template>
    <header class="ui-super-admin-page-header">
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
