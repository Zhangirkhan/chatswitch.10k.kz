<script setup lang="ts">
import AccelMark from '@/Components/AccelMark.vue';

withDefaults(
    defineProps<{
        size?: number | string;
    }>(),
    {
        size: 40,
    },
);
</script>

<template>
    <AccelMark :size="size" />
</template>
