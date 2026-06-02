<script setup lang="ts">
import type { ClientProfileActivityItem } from '@/Components/Clients/clientProfileTypes';
import { useI18n } from '@/composables/useI18n';

defineProps<{
    items: ClientProfileActivityItem[];
}>();

const { t } = useI18n();

function dateLabel(v: string | null): string {
    if (!v) {
        return '';
    }
    const d = new Date(v);
    if (Number.isNaN(d.getTime())) {
        return '';
    }
    return d.toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });
}

function itemLabel(item: ClientProfileActivityItem): string {
    if (item.type === 'message') {
        return item.direction === 'inbound' ? t('clients.timeline.client') : t('clients.timeline.manager');
    }
    if (item.type === 'event') {
        return t('clients.timeline.event');
    }
    return item.label || t('clients.timeline.fact');
}
</script>

<template>
    <div v-if="items.length === 0" class="text-xs opacity-70">{{ t('clients.timeline.empty') }}</div>
    <ul v-else class="space-y-2">
        <li
            v-for="(item, idx) in items"
            :key="`activity-${idx}`"
            class="rounded-lg px-3 py-2 text-xs"
            :style="{ background: 'color-mix(in srgb, var(--ui-surface-muted) 80%, transparent)' }"
        >
            <div class="mb-1 flex items-center justify-between gap-2 opacity-70">
                <span>{{ itemLabel(item) }}</span>
                <span v-if="item.at">{{ dateLabel(item.at) }}</span>
            </div>
            <div class="whitespace-pre-wrap break-words">{{ item.body }}</div>
            <div v-if="item.assignee" class="mt-1 opacity-70">{{ t('clients.timeline.assignee', { name: item.assignee }) }}</div>
        </li>
    </ul>
</template>
