<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Баннер «Установить приложение». Появляется, когда браузер сигнализирует,
 * что PWA можно добавить на главный экран (`beforeinstallprompt`).
 * После принятия или отклонения скрывается и не показывается снова (localStorage).
 */

const STORAGE_KEY = 'chatswitch:pwa-install-dismissed';

const visible = ref(false);
const installing = ref(false);

let deferredPrompt: any = null;

function onBeforeInstall(e: Event) {
    if (localStorage.getItem(STORAGE_KEY)) return;
    e.preventDefault();
    deferredPrompt = e;
    visible.value = true;
}

async function install() {
    if (!deferredPrompt) return;
    installing.value = true;
    try {
        deferredPrompt.prompt();
        const { outcome } = await deferredPrompt.userChoice;
        if (outcome === 'accepted') {
            dismiss(false);
        }
    } finally {
        deferredPrompt = null;
        installing.value = false;
        visible.value = false;
    }
}

function dismiss(persist = true) {
    visible.value = false;
    if (persist) {
        localStorage.setItem(STORAGE_KEY, '1');
    }
}

onMounted(() => {
    window.addEventListener('beforeinstallprompt', onBeforeInstall);
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeinstallprompt', onBeforeInstall);
});
</script>

<template>
    <Transition name="pwa-slide">
        <div v-if="visible" class="pwa-banner" role="alert" aria-live="polite">
            <div class="pwa-banner-icon">
                <img src="/icons/icon-192.png" alt="ChatSwitch" width="40" height="40" />
            </div>
            <div class="pwa-banner-text">
                <div class="pwa-banner-title">Установить ChatSwitch</div>
                <div class="pwa-banner-sub">Быстрый доступ с главного экрана</div>
            </div>
            <button
                type="button"
                class="pwa-btn pwa-btn-install"
                :disabled="installing"
                @click="install"
            >
                {{ installing ? '…' : 'Установить' }}
            </button>
            <button
                type="button"
                class="pwa-btn-close"
                aria-label="Закрыть"
                @click="dismiss()"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </Transition>
</template>

<style scoped>
.pwa-banner {
    position: fixed;
    bottom: 1rem;
    left: 50%;
    transform: translateX(-50%);
    z-index: 9999;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.65rem 0.75rem 0.65rem 0.85rem;
    border-radius: 16px;
    background: var(--wa-panel);
    border: 1px solid var(--wa-border-strong);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.45);
    width: calc(100% - 2rem);
    max-width: 420px;
    color: var(--wa-text);
}

.pwa-banner-icon img {
    border-radius: 10px;
    flex-shrink: 0;
}

.pwa-banner-text {
    flex: 1;
    min-width: 0;
}

.pwa-banner-title {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--wa-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.pwa-banner-sub {
    font-size: 0.75rem;
    color: var(--wa-text-secondary);
    margin-top: 1px;
}

.pwa-btn-install {
    flex-shrink: 0;
    padding: 0.4rem 1rem;
    border-radius: 999px;
    background: var(--wa-accent);
    color: var(--wa-unread-text, #0b0d0e);
    font-size: 0.82rem;
    font-weight: 700;
    border: none;
    cursor: pointer;
    transition: filter 0.12s ease;
    white-space: nowrap;
}
.pwa-btn-install:hover:not(:disabled) { filter: brightness(1.08); }
.pwa-btn-install:disabled { opacity: 0.6; cursor: default; }

.pwa-btn-close {
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: transparent;
    border: none;
    cursor: pointer;
    color: var(--wa-text-secondary);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.1s;
}
.pwa-btn-close:hover { background: var(--wa-panel-hover); color: var(--wa-text); }

/* Slide-up animation */
.pwa-slide-enter-active,
.pwa-slide-leave-active {
    transition: opacity 0.25s ease, transform 0.25s ease;
}
.pwa-slide-enter-from,
.pwa-slide-leave-to {
    opacity: 0;
    transform: translateX(-50%) translateY(12px);
}
</style>
