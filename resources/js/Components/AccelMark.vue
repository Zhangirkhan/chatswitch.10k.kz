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
    >
        <img
            v-if="isBadge"
            src="/branding/logo-badge.png"
            :width="size"
            :height="size"
            :alt="title"
            class="accel-mark__badge-img"
            decoding="async"
        />
        <svg
            v-else
            :width="size"
            :height="size"
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
                stroke="#048B4F"
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

.accel-mark__badge-img {
    display: block;
    border-radius: 24%;
    box-shadow:
        0 0 0 1px rgba(1, 185, 100, 0.35),
        0 8px 20px rgba(0, 0, 0, 0.28);
}

.accel-mark__svg {
    display: block;
}
</style>
