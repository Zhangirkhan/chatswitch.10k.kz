<script setup lang="ts">
withDefaults(
    defineProps<{
        variant?: 'default' | 'table' | 'cards' | 'form';
    }>(),
    {
        variant: 'default',
    },
);
</script>

<template>
    <div class="p-6 space-y-6 animate-pulse" aria-hidden="true" aria-busy="true">
        <template v-if="variant === 'table'">
            <div class="h-10 w-full max-w-md rounded-lg" :style="{ background: 'var(--wa-panel-header)' }" />
            <div class="rounded-xl border overflow-hidden" :style="{ borderColor: 'var(--wa-border)' }">
                <div class="h-11 border-b" :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel-header)' }" />
                <div v-for="row in 8" :key="row" class="flex gap-4 px-4 py-3 border-b last:border-0" :style="{ borderColor: 'var(--wa-border)' }">
                    <div class="h-4 flex-1 rounded" :style="{ background: 'var(--wa-panel-header)' }" />
                    <div class="h-4 w-24 rounded" :style="{ background: 'var(--wa-panel-header)' }" />
                    <div class="h-4 w-16 rounded" :style="{ background: 'var(--wa-panel-header)' }" />
                </div>
            </div>
        </template>

        <template v-else-if="variant === 'cards'">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="card in 6"
                    :key="card"
                    class="rounded-xl border p-4 space-y-3"
                    :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel)' }"
                >
                    <div class="h-5 w-2/3 rounded" :style="{ background: 'var(--wa-panel-header)' }" />
                    <div class="h-3 w-full rounded" :style="{ background: 'var(--wa-panel-header)' }" />
                    <div class="h-3 w-4/5 rounded" :style="{ background: 'var(--wa-panel-header)' }" />
                    <div class="flex gap-2 pt-2">
                        <div class="h-8 flex-1 rounded-lg" :style="{ background: 'var(--wa-panel-header)' }" />
                        <div class="h-8 w-20 rounded-lg" :style="{ background: 'var(--wa-panel-header)' }" />
                    </div>
                </div>
            </div>
        </template>

        <template v-else-if="variant === 'form'">
            <div class="max-w-2xl space-y-4">
                <div v-for="field in 5" :key="field" class="space-y-2">
                    <div class="h-3 w-28 rounded" :style="{ background: 'var(--wa-panel-header)' }" />
                    <div class="h-10 w-full rounded-lg" :style="{ background: 'var(--wa-panel-header)' }" />
                </div>
            </div>
        </template>

        <template v-else>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div v-for="kpi in 4" :key="kpi" class="h-24 rounded-xl" :style="{ background: 'var(--wa-panel-header)' }" />
            </div>
            <div class="h-64 rounded-xl" :style="{ background: 'var(--wa-panel-header)' }" />
        </template>
    </div>
</template>
