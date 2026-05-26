<script setup lang="ts">
defineProps<{
    label?: string;
    wide?: boolean;
    inline?: boolean;
}>();
</script>

<template>
    <label
        class="ui-filter-field"
        :class="{
            'ui-filter-field--wide': wide,
            'ui-filter-field--inline': inline,
        }"
    >
        <span v-if="label" class="ui-filter-field__label">{{ label }}</span>
        <slot />
    </label>
</template>
