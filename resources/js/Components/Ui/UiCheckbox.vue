<script setup lang="ts">
import { computed } from 'vue';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        modelValue?: boolean;
        /** @deprecated Prefer modelValue / v-model */
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

const isChecked = computed(() => {
    if (props.modelValue !== undefined) {
        return props.modelValue;
    }

    return props.checked ?? false;
});

function onActivate(event: MouseEvent): void {
    event.preventDefault();
    event.stopPropagation();

    emit('click', event);

    if (props.disabled) {
        return;
    }

    const next = !isChecked.value;
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
        role="checkbox"
        :aria-checked="isChecked"
        :aria-disabled="disabled || undefined"
        :aria-label="ariaLabel"
        @click="onActivate"
    >
        <input
            :id="id"
            type="checkbox"
            class="ui-checkbox-native"
            tabindex="-1"
            :checked="isChecked"
            :disabled="disabled"
            aria-hidden="true"
            @click.stop="onActivate"
            @change.prevent
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
