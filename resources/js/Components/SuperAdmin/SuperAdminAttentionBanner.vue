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
        <p class="ui-super-admin-attention__title">{{ title }}</p>
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
