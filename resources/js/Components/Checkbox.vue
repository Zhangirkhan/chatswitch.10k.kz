<script setup lang="ts">
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';

const emit = defineEmits<{
    'update:checked': [value: boolean];
}>();

defineProps<{
    checked: boolean;
    disabled?: boolean;
}>();
</script>

<template>
    <UiCheckbox
        :model-value="checked"
        :disabled="disabled"
        @update:model-value="emit('update:checked', $event)"
    />
</template>
