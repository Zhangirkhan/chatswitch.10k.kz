<script setup lang="ts">
import { usePlatformBannerRealtime, usePlatformBannerVisibility } from '@/composables/usePlatformBannerVisibility';

const { visibleBanners, dismiss } = usePlatformBannerVisibility();

usePlatformBannerRealtime();
</script>

<template>
    <div v-if="visibleBanners.length > 0" class="platform-banner-stack" aria-live="polite">
        <div
            v-for="banner in visibleBanners"
            :key="banner.id"
            class="platform-banner"
            role="status"
            :style="{
                background: banner.background_color,
                color: banner.text_color,
            }"
        >
            <p class="platform-banner__text">{{ banner.message }}</p>
            <button
                type="button"
                class="platform-banner__close"
                aria-label="Закрыть"
                @click="dismiss(banner.id)"
            >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
                </svg>
            </button>
        </div>
    </div>
</template>

<style scoped>
.platform-banner-stack {
    flex-shrink: 0;
}

.platform-banner {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1rem;
    min-height: 2.25rem;
    padding: 0.5rem 2.75rem 0.5rem 1rem;
    font-size: 0.8125rem;
    font-weight: 500;
    line-height: 1.4;
    border-bottom: 1px solid rgba(0, 0, 0, 0.12);
    box-shadow: 0 1px 0 rgba(255, 255, 255, 0.06) inset;
}

.platform-banner__text {
    margin: 0;
    max-width: 48rem;
    text-align: center;
}

.platform-banner__close {
    position: absolute;
    right: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    display: flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border: none;
    border-radius: 0.375rem;
    background: rgba(0, 0, 0, 0.18);
    color: inherit;
    cursor: pointer;
    transition: background-color 0.12s ease;
}

.platform-banner__close:hover {
    background: rgba(0, 0, 0, 0.32);
}
</style>
