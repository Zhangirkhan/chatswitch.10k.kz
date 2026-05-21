<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        values: number[];
        color?: string;
        width?: number;
        height?: number;
    }>(),
    {
        color: 'var(--wa-accent)',
        width: 72,
        height: 22,
    },
);

const path = computed((): string => {
    const values = props.values.length > 0 ? props.values : [0];
    const max = Math.max(...values, 1);
    const step = props.width / Math.max(values.length - 1, 1);

    return values
        .map((value, index) => {
            const x = index * step;
            const y = props.height - (value / max) * (props.height - 4) - 2;
            return `${index === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
        })
        .join(' ');
});

const hasActivity = computed(() => props.values.some((value) => value > 0));
</script>

<template>
    <svg
        v-if="hasActivity"
        class="funnel-sparkline"
        :width="width"
        :height="height"
        :viewBox="`0 0 ${width} ${height}`"
        aria-hidden="true"
        role="presentation"
    >
        <path
            :d="path"
            fill="none"
            :stroke="color"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
    </svg>
</template>

<style scoped>
.funnel-sparkline {
    display: block;
    opacity: 0.85;
}
</style>
