<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

export type AttentionItem = {
    label: string;
    href: string;
    count: number;
};

defineProps<{
    title: string;
    items: AttentionItem[];
}>();
</script>

<template>
    <div v-if="items.length > 0" class="ui-super-admin-attention">
        <div class="ui-super-admin-attention__head">
            <svg class="ui-super-admin-attention__icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <p class="ui-super-admin-attention__title">{{ title }}</p>
        </div>
        <ul class="ui-super-admin-attention__list">
            <li v-for="item in items" :key="item.href">
                <Link :href="item.href" class="ui-super-admin-attention__link">
                    <span>{{ item.label }}</span>
                    <strong>{{ item.count }}</strong>
                </Link>
            </li>
        </ul>
    </div>
</template>
