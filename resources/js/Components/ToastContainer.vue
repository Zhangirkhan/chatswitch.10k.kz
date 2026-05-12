<script setup lang="ts">
import { useToastStore } from '@/stores/toast';

const { state, dismiss } = useToastStore();

async function onAction(id: number, handler: () => void | Promise<void>) {
    dismiss(id);
    await handler();
}
</script>

<template>
    <teleport to="body">
        <div class="toast-container">
            <TransitionGroup
                tag="div"
                class="toast-stack"
                enter-active-class="toast-enter-active"
                enter-from-class="toast-enter-from"
                enter-to-class="toast-enter-to"
                leave-active-class="toast-leave-active"
                leave-from-class="toast-leave-from"
                leave-to-class="toast-leave-to"
            >
                <div
                    v-for="toast in state.items"
                    :key="toast.id"
                    class="toast-pill"
                >
                    <svg class="toast-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="9" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.5 12.5l2.5 2.5 4.5-5" />
                    </svg>
                    <span class="toast-message">{{ toast.message }}</span>
                    <button
                        v-if="toast.action"
                        type="button"
                        class="toast-action"
                        @click="onAction(toast.id, toast.action.handler)"
                    >
                        {{ toast.action.label }}
                    </button>
                </div>
            </TransitionGroup>
        </div>
    </teleport>
</template>

<style scoped>
.toast-container {
    position: fixed;
    left: 72px;
    bottom: 16px;
    z-index: 200;
    max-width: calc(100vw - 88px);
    display: flex;
    justify-content: flex-start;
    pointer-events: none;
}
@media (max-width: 900px) {
    .toast-container {
        left: 12px;
        max-width: calc(100vw - 24px);
    }
}
.toast-stack {
    display: flex;
    flex-direction: column-reverse;
    align-items: flex-start;
    gap: 8px;
    pointer-events: none;
}
.toast-pill {
    pointer-events: auto;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 6px 6px 6px 14px;
    min-height: 42px;
    max-width: 360px;
    border-radius: 9999px;
    background: var(--wa-selected);
    border: 1px solid var(--wa-border);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.55);
    color: var(--wa-text);
    font-size: 0.8125rem;
}
.toast-icon {
    width: 18px;
    height: 18px;
    color: var(--wa-text-secondary);
    flex-shrink: 0;
}
.toast-message {
    flex: 1;
    line-height: 1.2;
    padding-right: 4px;
}
.toast-action {
    padding: 7px 16px;
    border-radius: 9999px;
    background: var(--wa-border-strong);
    color: var(--wa-text);
    font-size: 0.8125rem;
    font-weight: 500;
    transition: background-color 0.12s ease;
    white-space: nowrap;
}
.toast-action:hover {
    background: var(--wa-text-muted);
}

.toast-enter-active,
.toast-leave-active {
    transition: transform 0.18s ease, opacity 0.18s ease;
}
.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateY(8px) scale(0.98);
}
.toast-enter-to,
.toast-leave-from {
    opacity: 1;
    transform: translateY(0) scale(1);
}
</style>
