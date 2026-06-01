<script setup lang="ts">
import type { ClientProfileField } from '@/Components/Clients/clientProfileTypes';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        title: string;
        semantic?: 'who' | 'context' | 'agreements';
        fields?: ClientProfileField[];
        defaultOpen?: boolean;
    }>(),
    {
        fields: () => [],
        defaultOpen: true,
    },
);

const open = ref(props.defaultOpen);

const semanticClass = computed(() => {
    if (props.semantic === 'who') {
        return 'client-section--who';
    }
    if (props.semantic === 'context') {
        return 'client-section--context';
    }
    if (props.semantic === 'agreements') {
        return 'client-section--agreements';
    }
    return '';
});
</script>

<template>
    <section class="client-profile-section rounded-xl border overflow-hidden" :class="semanticClass">
        <button
            type="button"
            class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left text-sm font-medium"
            @click="open = !open"
        >
            <span>{{ title }}</span>
            <span class="text-xs opacity-60">{{ open ? '−' : '+' }}</span>
        </button>
        <div v-show="open" class="border-t px-4 py-3 space-y-2 text-sm">
            <div
                v-for="(field, idx) in fields"
                :key="`${title}-${idx}`"
                class="grid grid-cols-1 gap-0.5 sm:grid-cols-[minmax(120px,34%)_1fr]"
            >
                <div class="text-xs opacity-70">{{ field.label }}</div>
                <div class="whitespace-pre-wrap break-words">{{ field.value }}</div>
            </div>
            <slot />
        </div>
    </section>
</template>

<style scoped>
.client-profile-section {
    border-color: var(--ui-border);
    background: var(--ui-surface);
}

.client-section--who {
    background: color-mix(in srgb, var(--sem-who, #8b5cf6) 12%, var(--ui-surface));
}

.client-section--context {
    background: color-mix(in srgb, var(--sem-context, #f59e0b) 12%, var(--ui-surface));
}

.client-section--agreements {
    background: color-mix(in srgb, var(--sem-agreements, #22c55e) 12%, var(--ui-surface));
}
</style>
