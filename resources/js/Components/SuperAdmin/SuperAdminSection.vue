<script setup lang="ts">
defineProps<{
    title: string;
    hint?: string;
    flush?: boolean;
}>();
</script>

<template>
    <section class="ui-super-admin-section">
        <div class="ui-super-admin-section__head">
            <div>
                <h2 class="ui-super-admin-section__title">{{ title }}</h2>
                <p v-if="hint" class="ui-super-admin-section__hint">{{ hint }}</p>
            </div>
            <div v-if="$slots.actions" class="ui-super-admin-section__actions">
                <slot name="actions" />
            </div>
        </div>
        <div class="ui-panel ui-super-admin-section__body" :class="{ 'ui-super-admin-section__body--flush': flush }">
            <slot />
        </div>
    </section>
</template>
