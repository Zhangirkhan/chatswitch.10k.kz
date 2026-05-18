<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps<{
    href: string;
    active?: boolean;
}>();

const classes = computed(() =>
    props.active
        ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-[var(--wa-accent)] text-start text-base font-medium text-[var(--wa-accent)] bg-[var(--wa-accent-soft)] focus:outline-none focus:text-[var(--wa-accent)] focus:bg-[var(--wa-accent-soft)] focus:border-[var(--wa-accent-hover)] transition duration-150 ease-in-out'
        : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-gray-600 hover:text-gray-800 hover:bg-gray-50 hover:border-[var(--wa-accent)] focus:outline-none focus:text-gray-800 focus:bg-gray-50 focus:border-[var(--wa-accent)] transition duration-150 ease-in-out',
);
</script>

<template>
    <Link :href="href" :class="classes">
        <slot />
    </Link>
</template>
