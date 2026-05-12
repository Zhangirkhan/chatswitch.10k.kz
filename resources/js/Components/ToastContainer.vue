<script setup lang="ts">
import { useToastStore } from '@/stores/toast';
import { router } from '@inertiajs/vue3';

const { state, dismiss } = useToastStore();

async function onAction(id: number, handler: () => void | Promise<void>) {
    dismiss(id);
    await handler();
}

function onMessageClick(id: number, chatId?: number) {
    dismiss(id);
    if (chatId) {
        router.visit(route('chats.show', chatId));
    }
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
                <!-- ── Incoming message notification toast ── -->
                <div
                    v-for="toast in state.items"
                    :key="toast.id"
                    :class="['toast-pill', toast.type === 'message' ? 'toast-pill-msg' : '']"
                    :style="toast.type === 'message' ? 'cursor:pointer' : ''"
                    @click="toast.type === 'message' ? onMessageClick(toast.id, toast.chatId) : undefined"
                >
                    <!-- Message-type: avatar + structured layout -->
                    <template v-if="toast.type === 'message'">
                        <div class="toast-avatar">
                            <img v-if="toast.iconUrl" :src="toast.iconUrl" class="toast-avatar-img" alt="" />
                            <svg v-else class="toast-avatar-icon" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M19.005 3.175H4.674C3.642 3.175 3 3.789 3 4.821V21.02l3.544-3.514h12.461c1.033 0 2.064-1.06 2.064-2.093V4.821c-.001-1.032-1.032-1.646-2.064-1.646zm-4.989 9.869H7.041V11.1h6.975v1.944zm3-4H7.041V7.1h9.975v1.944z"/>
                            </svg>
                        </div>
                        <div class="toast-msg-body">
                            <div v-if="toast.title" class="toast-msg-title">{{ toast.title }}</div>
                            <div class="toast-msg-text">{{ toast.message }}</div>
                        </div>
                        <button
                            type="button"
                            class="toast-close"
                            title="Закрыть"
                            @click.stop="dismiss(toast.id)"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </template>

                    <!-- Default toast -->
                    <template v-else>
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
                    </template>
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

/* Message notification toast */
.toast-pill-msg {
    border-radius: 1rem;
    padding: 10px 10px 10px 12px;
    gap: 10px;
    max-width: 340px;
    border-color: var(--wa-accent);
    transition: opacity 0.12s;
}
.toast-pill-msg:hover {
    opacity: 0.92;
}
.toast-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: var(--wa-border-strong);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    overflow: hidden;
    color: var(--wa-icon);
}
.toast-avatar-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.toast-avatar-icon {
    width: 20px;
    height: 20px;
}
.toast-msg-body {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 1px;
}
.toast-msg-title {
    font-weight: 600;
    font-size: 0.8rem;
    color: var(--wa-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.toast-msg-text {
    font-size: 0.78rem;
    color: var(--wa-text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.toast-close {
    width: 24px;
    height: 24px;
    flex-shrink: 0;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--wa-icon);
    transition: background-color 0.12s;
}
.toast-close:hover {
    background: var(--wa-panel-hover);
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
