<script setup lang="ts">
withDefaults(
    defineProps<{
        size?: number;
        bg?: string;
    }>(),
    {
        size: 18,
        bg: 'var(--icon-cutout, var(--wa-panel))',
    },
);
</script>

<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <path d="M8.7 3A6 6 0 0 1 18 8c0 2.2.2 3.8.5 5" />
        <path d="M17 17H3s3-2 3-9a5 5 0 0 1 .3-1.7" />
        <path d="M13.73 21a2 2 0 0 1-3.46 0" />
        <line
            x1="3"
            y1="3"
            x2="21"
            y2="21"
            :stroke="bg"
            stroke-width="3.6"
        />
        <line x1="3" y1="3" x2="21" y2="21" stroke="currentColor" />
    </svg>
</template>
