<script setup lang="ts">
import { computed } from 'vue';
import type { MessageReaction } from '@/types';

interface Props {
    reactions: MessageReaction[];
    currentUserId?: number;
}

const props = defineProps<Props>();
const emit = defineEmits<{ (e: 'react', emoji: string): void }>();

interface Bucket {
    emoji: string;
    names: string[];
    mine: boolean;
}

const grouped = computed<Bucket[]>(() => {
    const map = new Map<string, Bucket>();
    for (const r of props.reactions ?? []) {
        const bucket = map.get(r.emoji) ?? { emoji: r.emoji, names: [], mine: false };
        bucket.names.push(r.user?.name || r.external_name || 'Клиент');
        if (props.currentUserId && r.user?.id === props.currentUserId) {
            bucket.mine = true;
        }
        map.set(r.emoji, bucket);
    }
    return Array.from(map.values());
});

const totalCount = computed(() => (props.reactions ?? []).length);

const panelTitle = computed(() => {
    return grouped.value
        .map((b) => `${b.emoji}: ${b.names.join(', ')}`)
        .join(' | ');
});
</script>

<template>
    <div v-if="grouped.length" class="mt-1">
        <div
            class="inline-flex items-center rounded-full border border-[var(--wa-border)] px-2 py-0.5 text-xs shadow-sm backdrop-blur-[2px]"
            :title="panelTitle"
            :style="{
                background: 'color-mix(in srgb, var(--wa-panel) 88%, transparent)',
            }"
        >
            <span
                v-if="totalCount > 1"
                class="mr-1 tabular-nums"
                :style="{ color: 'var(--wa-text-secondary)' }"
            >
                {{ totalCount }}
            </span>

            <div class="flex items-center gap-1">
                <button
                    v-for="bucket in grouped"
                    :key="bucket.emoji"
                    type="button"
                    class="leading-none transition-opacity hover:opacity-90"
                    :class="bucket.mine ? 'opacity-100' : 'opacity-90'"
                    :title="bucket.names.join(', ')"
                    @click="emit('react', bucket.emoji)"
                >
                    <span class="text-[14px] leading-none">{{ bucket.emoji }}</span>
                </button>
            </div>
        </div>
    </div>
</template>
