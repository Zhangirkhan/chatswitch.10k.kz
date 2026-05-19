<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue?: boolean;
        checked?: boolean;
        disabled?: boolean;
        size?: 'sm' | 'md';
        title?: string;
        id?: string;
        ariaLabel?: string;
    }>(),
    {
        disabled: false,
        size: 'md',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
    change: [value: boolean];
    click: [event: MouseEvent];
}>();

const isChecked = computed(() => props.modelValue ?? props.checked ?? false);

function onChange(event: Event): void {
    const next = (event.target as HTMLInputElement).checked;
    emit('update:modelValue', next);
    emit('change', next);
}
</script>

<template>
    <span
        class="ui-checkbox"
        :class="{
            'ui-checkbox-checked': isChecked,
            'ui-checkbox-disabled': disabled,
            'ui-checkbox-sm': size === 'sm',
        }"
        :title="title"
    >
        <input
            :id="id"
            type="checkbox"
            class="ui-checkbox-native"
            :checked="isChecked"
            :disabled="disabled"
            :aria-label="ariaLabel"
            @change="onChange"
            @click="emit('click', $event)"
        />
        <span class="ui-checkbox-box" aria-hidden="true">
            <svg
                class="ui-checkbox-icon"
                viewBox="0 0 16 16"
                fill="none"
                stroke="currentColor"
                stroke-width="2.5"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <path d="M3.5 8.2 6.4 11 12.5 5" />
            </svg>
        </span>
    </span>
</template>
