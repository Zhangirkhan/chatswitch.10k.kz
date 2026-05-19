<script setup lang="ts">
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import { computed } from 'vue';

const emit = defineEmits<{
    'update:checked': [value: boolean];
}>();

const props = defineProps<{
    checked: boolean;
    disabled?: boolean;
}>();

const proxyChecked = computed({
    get() {
        return props.checked;
    },
    set(val: boolean) {
        emit('update:checked', val);
    },
});
</script>

<template>
    <UiCheckbox v-model="proxyChecked" :disabled="disabled" />
</template>
