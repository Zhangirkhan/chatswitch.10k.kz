<script setup lang="ts">
withDefaults(
    defineProps<{
        lines?: number;
        wrapperClass?: string;
    }>(),
    {
        lines: 3,
        wrapperClass: '',
    },
);
</script>

<template>
    <div class="space-y-2 animate-pulse" :class="wrapperClass" aria-hidden="true">
        <div
            v-for="line in lines"
            :key="line"
            class="h-3 rounded"
            :class="line === lines ? 'w-2/3' : 'w-full'"
            :style="{ background: 'var(--wa-panel-header)' }"
        />
    </div>
</template>
