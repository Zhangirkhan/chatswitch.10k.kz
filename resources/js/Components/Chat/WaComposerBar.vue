<script setup lang="ts">
withDefaults(
    defineProps<{
        recording?: boolean;
    }>(),
    {
        recording: false,
    },
);
</script>

<template>
    <div class="relative shrink-0">
        <slot name="attachments" />
        <div class="wa-input-bar">
            <slot v-if="recording" name="recording" />
            <slot v-else />
        </div>
    </div>
</template>
