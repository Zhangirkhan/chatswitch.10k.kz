<script setup lang="ts">
defineProps<{
    title: string;
    hint?: string;
    empty?: boolean;
    emptyText?: string;
    tall?: boolean;
}>();
</script>

<template>
    <article class="ui-panel ui-analytics-chart-card ui-ai-sales-chart-card">
        <div class="ui-analytics-chart-card__head">
            <h3 class="ui-analytics-chart-card__title">{{ title }}</h3>
            <p v-if="hint" class="ui-analytics-chart-card__hint">{{ hint }}</p>
        </div>
        <div
            v-if="empty"
            class="ui-analytics-empty px-4 py-8 text-sm text-ui-text-muted"
        >
            {{ emptyText }}
        </div>
        <div
            v-else
            class="ui-analytics-chart-card__canvas ui-ai-sales-chart-card__canvas"
            :class="{ 'ui-ai-sales-chart-card__canvas--tall': tall }"
        >
            <slot />
        </div>
    </article>
</template>
