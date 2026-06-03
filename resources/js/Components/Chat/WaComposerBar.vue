<script setup lang="ts">
withDefaults(
    defineProps<{
        recording?: boolean;
        /** unified = single pill (ChatInput); split = WhatsApp [+] [field pill] [mic] */
        variant?: 'split' | 'unified';
    }>(),
    {
        recording: false,
        variant: 'split',
    },
);
</script>

<template>
    <div class="wa-composer-root">
        <slot name="attachments" />
        <div class="wa-input-bar" :class="{ 'wa-input-bar--unified': variant === 'unified' }">
            <slot v-if="recording" name="recording" />
            <slot v-else />
        </div>
    </div>
</template>
