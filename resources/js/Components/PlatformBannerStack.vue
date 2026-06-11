<script setup lang="ts">
import { usePlatformBannerVisibility } from '@/composables/usePlatformBannerVisibility';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const { visibleBanners, dismiss } = usePlatformBannerVisibility();

const impersonationOffsetRem = computed(() => (page.props.impersonation ? 2.25 : 0));
</script>

<template>
    <div v-if="visibleBanners.length > 0" class="platform-banner-stack" aria-live="polite">
        <div
            v-for="(banner, index) in visibleBanners"
            :key="banner.id"
            class="platform-banner"
            role="status"
            :style="{
                top: `${impersonationOffsetRem + index * 2.25}rem`,
                background: banner.background_color,
                color: banner.text_color,
                zIndex: 190 - index,
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
    pointer-events: none;
}

.platform-banner {
    position: fixed;
    left: 0;
    right: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1rem;
    min-height: 2.25rem;
    padding: 0.5rem 2.75rem 0.5rem 1rem;
    font-size: 0.8125rem;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.25);
    pointer-events: auto;
}

.platform-banner__text {
    margin: 0;
    text-align: center;
    line-height: 1.4;
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
    background: rgba(0, 0, 0, 0.2);
    color: inherit;
    cursor: pointer;
}

.platform-banner__close:hover {
    background: rgba(0, 0, 0, 0.35);
}
</style>
