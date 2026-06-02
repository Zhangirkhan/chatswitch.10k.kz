<script setup lang="ts">
import { computed } from 'vue';
import { useConnectionStatus, type ConnectionOverlayMode } from '@/composables/useConnectionStatus';
import { useI18n } from '@/composables/useI18n';

const { visible, mode, retrying, retry } = useConnectionStatus();
const { t } = useI18n();

const copy = computed(() => {
    const texts: Record<
        ConnectionOverlayMode,
        { title: string; description: string; hint: string }
    > = {
        offline: {
            title: t('chats.connection.offlineTitle'),
            description: t('chats.connection.offlineDescription'),
            hint: t('chats.connection.offlineHint'),
        },
        reconnecting: {
            title: t('chats.connection.reconnectingTitle'),
            description: t('chats.connection.reconnectingDescription'),
            hint: t('chats.connection.reconnectingHint'),
        },
        server: {
            title: t('chats.connection.serverTitle'),
            description: t('chats.connection.serverDescription'),
            hint: t('chats.connection.serverHint'),
        },
    };

    return texts[mode.value];
});
</script>

<template>
    <Teleport to="body">
        <Transition name="connection-overlay">
            <div
                v-if="visible"
                class="connection-overlay"
                role="alertdialog"
                aria-modal="true"
                aria-labelledby="connection-overlay-title"
                aria-describedby="connection-overlay-desc"
            >
                <div class="connection-overlay__backdrop" aria-hidden="true"></div>
                <div class="connection-overlay__glow connection-overlay__glow--left" aria-hidden="true"></div>
                <div class="connection-overlay__glow connection-overlay__glow--right" aria-hidden="true"></div>

                <div class="connection-overlay__panel">
                    <div
                        class="connection-overlay__icon-wrap"
                        :class="{
                            'connection-overlay__icon-wrap--pulse': mode === 'reconnecting',
                        }"
                    >
                        <svg
                            class="connection-overlay__icon"
                            viewBox="0 0 64 64"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                            aria-hidden="true"
                        >
                            <path
                                d="M10 28c8.8-8.8 23.2-8.8 32 0"
                                stroke="currentColor"
                                stroke-width="3.5"
                                stroke-linecap="round"
                            />
                            <path
                                d="M18 36c5.2-5.2 13.8-5.2 19 0"
                                stroke="currentColor"
                                stroke-width="3.5"
                                stroke-linecap="round"
                                opacity="0.75"
                            />
                            <path
                                d="M26 44c2.4-2.4 6.6-2.4 9 0"
                                stroke="currentColor"
                                stroke-width="3.5"
                                stroke-linecap="round"
                                opacity="0.55"
                            />
                            <circle cx="31" cy="50" r="2.5" fill="currentColor" />
                            <path
                                d="M14 14l36 36"
                                stroke="currentColor"
                                stroke-width="3.5"
                                stroke-linecap="round"
                            />
                        </svg>
                    </div>

                    <h2 id="connection-overlay-title" class="connection-overlay__title">
                        {{ copy.title }}
                    </h2>
                    <p id="connection-overlay-desc" class="connection-overlay__desc">
                        {{ copy.description }}
                    </p>

                    <div class="connection-overlay__actions">
                        <button
                            type="button"
                            class="connection-overlay__btn"
                            :disabled="retrying"
                            @click="retry()"
                        >
                            <span
                                v-if="retrying"
                                class="connection-overlay__spinner"
                                aria-hidden="true"
                            ></span>
                            {{ retrying ? t('chats.connection.connecting') : t('chats.connection.retry') }}
                        </button>
                    </div>

                    <p class="connection-overlay__hint">
                        {{ copy.hint }}
                    </p>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.connection-overlay {
    position: fixed;
    inset: 0;
    z-index: 10050;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
    pointer-events: auto;
}

.connection-overlay__backdrop {
    position: absolute;
    inset: 0;
    background:
        radial-gradient(circle at 50% 20%, color-mix(in srgb, var(--wa-accent) 10%, transparent), transparent 55%),
        color-mix(in srgb, var(--wa-page-bg) 88%, #0f172a);
    backdrop-filter: blur(14px) saturate(120%);
}

.connection-overlay__glow {
    position: absolute;
    width: min(420px, 70vw);
    height: min(420px, 70vw);
    border-radius: 999px;
    filter: blur(60px);
    opacity: 0.35;
    pointer-events: none;
}

.connection-overlay__glow--left {
    left: -8%;
    top: 18%;
    background: color-mix(in srgb, #ef4444 55%, transparent);
}

.connection-overlay__glow--right {
    right: -10%;
    bottom: 10%;
    background: color-mix(in srgb, var(--wa-accent) 45%, transparent);
}

.connection-overlay__panel {
    position: relative;
    width: min(440px, 100%);
    padding: 2rem 1.75rem 1.65rem;
    border-radius: 1.35rem;
    border: 1px solid color-mix(in srgb, var(--wa-border-strong) 80%, #ef4444 20%);
    background:
        linear-gradient(
            165deg,
            color-mix(in srgb, var(--wa-panel) 94%, white 6%),
            color-mix(in srgb, var(--wa-panel-header) 88%, var(--wa-page-bg))
        );
    box-shadow:
        0 28px 80px rgba(0, 0, 0, 0.28),
        0 0 0 1px color-mix(in srgb, white 8%, transparent) inset;
    text-align: center;
}

.connection-overlay__icon-wrap {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 4.75rem;
    height: 4.75rem;
    margin: 0 auto 1.15rem;
    border-radius: 999px;
    color: #ef4444;
    background: color-mix(in srgb, #ef4444 12%, var(--wa-panel));
    box-shadow: 0 0 0 1px color-mix(in srgb, #ef4444 22%, transparent);
}

.connection-overlay__icon-wrap--pulse {
    animation: connection-pulse 1.8s ease-in-out infinite;
}

.connection-overlay__icon {
    width: 2.6rem;
    height: 2.6rem;
}

.connection-overlay__title {
    margin: 0;
    font-size: 1.25rem;
    line-height: 1.3;
    font-weight: 650;
    letter-spacing: -0.02em;
    color: var(--wa-text);
}

.connection-overlay__desc {
    margin: 0.75rem 0 0;
    font-size: 0.875rem;
    line-height: 1.55;
    color: var(--wa-text-secondary);
}

.connection-overlay__actions {
    margin-top: 1.35rem;
}

.connection-overlay__btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    min-width: 9.5rem;
    height: 2.65rem;
    padding: 0 1.25rem;
    border: 0;
    border-radius: 999px;
    background: var(--wa-accent);
    color: #fff;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: transform 0.15s ease, opacity 0.15s ease, box-shadow 0.15s ease;
    box-shadow: 0 10px 24px color-mix(in srgb, var(--wa-accent) 35%, transparent);
}

.connection-overlay__btn:hover:not(:disabled) {
    transform: translateY(-1px);
}

.connection-overlay__btn:disabled {
    opacity: 0.72;
    cursor: wait;
}

.connection-overlay__spinner {
    width: 1rem;
    height: 1rem;
    border-radius: 999px;
    border: 2px solid rgba(255, 255, 255, 0.35);
    border-top-color: #fff;
    animation: connection-spin 0.75s linear infinite;
}

.connection-overlay__hint {
    margin: 1rem 0 0;
    font-size: 0.72rem;
    line-height: 1.45;
    color: color-mix(in srgb, var(--wa-text-secondary) 88%, transparent);
}

@keyframes connection-pulse {
    0%,
    100% {
        transform: scale(1);
        box-shadow: 0 0 0 0 color-mix(in srgb, #ef4444 18%, transparent);
    }
    50% {
        transform: scale(1.03);
        box-shadow: 0 0 0 10px transparent;
    }
}

@keyframes connection-spin {
    to {
        transform: rotate(360deg);
    }
}

.connection-overlay-enter-active,
.connection-overlay-leave-active {
    transition: opacity 0.28s ease;
}

.connection-overlay-enter-active .connection-overlay__panel,
.connection-overlay-leave-active .connection-overlay__panel {
    transition: transform 0.32s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.28s ease;
}

.connection-overlay-enter-from,
.connection-overlay-leave-to {
    opacity: 0;
}

.connection-overlay-enter-from .connection-overlay__panel,
.connection-overlay-leave-to .connection-overlay__panel {
    opacity: 0;
    transform: translateY(12px) scale(0.97);
}
</style>
