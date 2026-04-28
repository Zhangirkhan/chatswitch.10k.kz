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
</script>

<template>
    <div v-if="grouped.length" class="mt-1 flex flex-wrap gap-1">
        <button
            v-for="bucket in grouped"
            :key="bucket.emoji"
            type="button"
            :title="bucket.names.join(', ')"
            class="flex items-center rounded-full border px-2 py-0.5 text-xs transition"
            :class="bucket.mine ? 'border-sky-500 bg-sky-500/10' : 'border-zinc-300 bg-white/60 dark:border-zinc-700 dark:bg-zinc-800/60'"
            @click="emit('react', bucket.emoji)"
        >
            <span>{{ bucket.emoji }}</span>
        </button>
    </div>
</template>
