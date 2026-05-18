<script setup lang="ts">
withDefaults(
    defineProps<{
        showDeal?: boolean;
    }>(),
    {
        showDeal: true,
    },
);
</script>

<template>
    <div class="space-y-3 animate-pulse" aria-hidden="true">
        <div v-if="showDeal" class="rounded-xl border p-3 space-y-2" :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel)' }">
            <div class="h-4 w-24 rounded" :style="{ background: 'var(--wa-panel-header)' }" />
            <div class="h-3 w-full rounded" :style="{ background: 'var(--wa-panel-header)' }" />
            <div class="h-3 w-2/3 rounded" :style="{ background: 'var(--wa-panel-header)' }" />
        </div>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <div v-for="n in 4" :key="n" class="h-14 rounded-lg" :style="{ background: 'var(--wa-panel-header)' }" />
        </div>
        <div class="space-y-2">
            <div class="h-3 w-full rounded" :style="{ background: 'var(--wa-panel-header)' }" />
            <div class="h-3 w-5/6 rounded" :style="{ background: 'var(--wa-panel-header)' }" />
            <div class="h-3 w-2/3 rounded" :style="{ background: 'var(--wa-panel-header)' }" />
        </div>
    </div>
</template>
