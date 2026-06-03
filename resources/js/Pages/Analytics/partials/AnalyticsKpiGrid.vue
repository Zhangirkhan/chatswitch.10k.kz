<script setup lang="ts">
import type { KpiItem } from '../types';

defineProps<{
    items: KpiItem[];
    compact?: boolean;
}>();
</script>

<template>
    <div class="ui-analytics-kpi-grid" :class="{ 'ui-analytics-kpi-grid--compact': compact }">
        <article
            v-for="(item, idx) in items"
            :key="`${item.label}-${idx}`"
            class="ui-analytics-kpi"
            :data-tone="item.tone || 'neutral'"
        >
            <div class="ui-analytics-kpi__label">{{ item.label }}</div>
            <div class="ui-analytics-kpi__value">{{ item.value }}</div>
        </article>
    </div>
</template>
