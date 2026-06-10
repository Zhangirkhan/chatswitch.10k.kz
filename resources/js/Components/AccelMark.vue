<script setup lang="ts">
withDefaults(
    defineProps<{
        size?: number | string;
        title?: string;
    }>(),
    {
        size: 32,
        title: 'Accel',
    },
);
</script>

<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 768 768"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
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
</template>
