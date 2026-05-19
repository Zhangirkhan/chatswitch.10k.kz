<script setup lang="ts">
defineProps<{
    active?: boolean;
}>();

const emit = defineEmits<{
    pointerdown: [event: PointerEvent];
}>();
</script>

<template>
    <div
        class="panel-resize-handle shrink-0"
        :class="{ 'panel-resize-handle--active': active }"
        role="separator"
        aria-orientation="vertical"
        aria-label="Изменить ширину панели"
        tabindex="0"
        @pointerdown="emit('pointerdown', $event)"
    />
</template>

<style scoped>
.panel-resize-handle {
    position: relative;
    z-index: 25;
    width: 6px;
    margin: 0 -3px;
    cursor: col-resize;
    touch-action: none;
    flex-shrink: 0;
}

.panel-resize-handle::after {
    content: '';
    position: absolute;
    top: 0;
    bottom: 0;
    left: 50%;
    width: 1px;
    transform: translateX(-50%);
    background: var(--wa-border, rgba(134, 150, 160, 0.35));
    opacity: 0.55;
    transition: opacity 0.15s ease, width 0.15s ease, background 0.15s ease;
}

.panel-resize-handle:hover::after,
.panel-resize-handle--active::after {
    width: 2px;
    opacity: 1;
    background: var(--wa-accent, #01b964);
}
</style>
