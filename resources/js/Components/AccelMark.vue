<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        size?: number | string;
        title?: string;
        variant?: 'plain' | 'badge';
    }>(),
    {
        size: 32,
        title: 'Accel',
        variant: 'plain',
    },
);

const isBadge = computed(() => props.variant === 'badge');
</script>

<template>
    <span
        class="accel-mark"
        :class="{ 'accel-mark--badge': isBadge }"
        :style="isBadge ? { width: `${size}px`, height: `${size}px` } : undefined"
    >
        <svg
            :width="isBadge ? undefined : size"
            :height="isBadge ? undefined : size"
            viewBox="0 0 768 768"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            class="accel-mark__svg"
            role="img"
            :aria-label="title"
        >
            <title>{{ title }}</title>
            <path
                d="M552 248L384 384L552 520"
                :stroke="isBadge ? '#066B3F' : '#048B4F'"
                stroke-width="88"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
            <path
                d="M216 248L384 384L216 520"
                stroke="#0B141A"
                stroke-width="88"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </svg>
    </span>
</template>

<style scoped>
.accel-mark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    line-height: 0;
}

.accel-mark--badge {
    border-radius: 24%;
    background: #01b964;
    padding: 19%;
    box-sizing: border-box;
    box-shadow:
        0 0 0 1px rgba(1, 185, 100, 0.35),
        0 8px 20px rgba(0, 0, 0, 0.28);
}

.accel-mark--badge .accel-mark__svg {
    display: block;
    width: 100%;
    height: 100%;
}
</style>
