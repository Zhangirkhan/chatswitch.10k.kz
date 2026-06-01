<script setup lang="ts">
import type { ClientProfileField } from '@/Components/Clients/clientProfileTypes';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        title: string;
        semantic?: 'who' | 'context' | 'agreements';
        fields?: ClientProfileField[];
        defaultOpen?: boolean;
        editable?: boolean;
    }>(),
    {
        fields: () => [],
        defaultOpen: true,
        editable: false,
    },
);

const emit = defineEmits<{
    saveField: [field: ClientProfileField, value: unknown];
}>();

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

function fieldDraft(field: ClientProfileField): string {
    const raw = (field as ClientProfileField & { raw_value?: string }).raw_value;
    if (raw !== undefined && raw !== '') {
        return raw;
    }
    return field.value === '—' ? '' : field.value;
}

function onFieldBlur(field: ClientProfileField, event: Event): void {
    const target = event.target as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement;
    let value: unknown = target.value;
    if (field.type === 'boolean') {
        value = target.value === '1';
    }
    emit('saveField', field, value);
}
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
                class="grid grid-cols-1 gap-1 sm:grid-cols-[minmax(120px,34%)_1fr]"
            >
                <div class="text-xs opacity-70">{{ field.label }}</div>
                <div v-if="editable && field.editable && field.definition_id" class="min-w-0">
                    <select
                        v-if="field.type === 'boolean'"
                        class="w-full rounded-lg border-0 px-2 py-1.5 text-sm focus:ring-0 focus:outline-none"
                        :style="{ background: 'var(--ui-surface-muted)' }"
                        :value="fieldDraft(field) === 'Да' ? '1' : fieldDraft(field) === 'Нет' ? '0' : ''"
                        @change="onFieldBlur(field, $event)"
                    >
                        <option value="">—</option>
                        <option value="1">Да</option>
                        <option value="0">Нет</option>
                    </select>
                    <select
                        v-else-if="field.type === 'list' && field.options?.choices?.length"
                        class="w-full rounded-lg border-0 px-2 py-1.5 text-sm focus:ring-0 focus:outline-none"
                        :style="{ background: 'var(--ui-surface-muted)' }"
                        :value="fieldDraft(field)"
                        @change="onFieldBlur(field, $event)"
                    >
                        <option value="">—</option>
                        <option v-for="choice in field.options.choices" :key="choice" :value="choice">{{ choice }}</option>
                    </select>
                    <textarea
                        v-else-if="field.type === 'text' || field.type === 'address'"
                        rows="2"
                        class="w-full rounded-lg border-0 px-2 py-1.5 text-sm focus:ring-0 focus:outline-none"
                        :style="{ background: 'var(--ui-surface-muted)' }"
                        :value="fieldDraft(field)"
                        @blur="onFieldBlur(field, $event)"
                    />
                    <input
                        v-else
                        :type="field.type === 'number' || field.type === 'money' ? 'text' : field.type === 'date' ? 'date' : field.type === 'datetime' ? 'datetime-local' : field.type === 'link' ? 'url' : 'text'"
                        class="w-full rounded-lg border-0 px-2 py-1.5 text-sm focus:ring-0 focus:outline-none"
                        :style="{ background: 'var(--ui-surface-muted)' }"
                        :value="fieldDraft(field)"
                        @blur="onFieldBlur(field, $event)"
                    />
                </div>
                <div v-else class="whitespace-pre-wrap break-words">{{ field.value }}</div>
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
