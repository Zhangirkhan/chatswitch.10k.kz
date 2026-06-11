<script setup lang="ts">
import AccelMark from '@/Components/AccelMark.vue';
import LandingLocaleSwitcher from '@/Components/Landing/LandingLocaleSwitcher.vue';
import { useLandingLocale } from '@/composables/useLandingLocale';
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = withDefaults(defineProps<{
    androidApkUrl?: string;
    mode?: 'marketing' | 'minimal';
}>(), {
    androidApkUrl: '/apk/app-release.apk',
    mode: 'marketing',
});

const emit = defineEmits<{
    request: [];
}>();

const { t } = useLandingLocale();
const mobileNavOpen = ref(false);

function closeMobileNav(): void {
    mobileNavOpen.value = false;
}

function openRequest(): void {
    emit('request');
    closeMobileNav();
}
</script>

<template>
    <header class="landing__header landing__header--row">
        <a href="/" class="landing__brand">
            <AccelMark :size="28" variant="badge" class="landing__brand-mark" />
            <span>Accel</span>
        </a>
        <button
            type="button"
            class="landing__nav-toggle"
            :aria-expanded="mobileNavOpen"
            :aria-label="t('landing.navMenuAria')"
            @click="mobileNavOpen = !mobileNavOpen"
        >
            <svg v-if="!mobileNavOpen" class="landing__nav-toggle-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" />
            </svg>
            <svg v-else class="landing__nav-toggle-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
        <nav
            class="landing__nav"
            :class="{ 'landing__nav--open': mobileNavOpen }"
        >
            <template v-if="mode === 'marketing'">
                <a href="#features" class="landing__nav-link" @click="closeMobileNav">{{ t('landing.navFeatures') }}</a>
                <a href="#pricing" class="landing__nav-link" @click="closeMobileNav">{{ t('landing.navPricing') }}</a>
                <div class="landing__download-menu">
                    <button
                        type="button"
                        class="landing__nav-link landing__download-trigger"
                        aria-haspopup="true"
                    >
                        {{ t('landing.navDownload') }}
                        <svg class="landing__download-chevron" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                            <path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                    <div class="landing__download-popover" role="menu">
                        <div class="landing__download-popover-panel">
                            <button
                                type="button"
                                class="landing__store-option landing__store-option--disabled"
                                role="menuitem"
                                disabled
                                :title="t('landing.downloadAppStoreSoon')"
                            >
                                <span class="landing__store-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z" />
                                    </svg>
                                </span>
                                <span class="landing__store-label">
                                    <span class="landing__store-label-small">{{ t('landing.storeDownloadOn') }}</span>
                                    <span class="landing__store-label-main">App Store</span>
                                </span>
                                <span class="landing__store-badge">{{ t('landing.downloadAppStoreSoon') }}</span>
                            </button>
                            <a
                                :href="androidApkUrl"
                                class="landing__store-option"
                                role="menuitem"
                                download="accel.apk"
                                @click="closeMobileNav"
                            >
                                <span class="landing__store-icon landing__store-icon--android" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M17.523 15.341l1.044-1.806a.75.75 0 10-1.3-.75l-1.05 1.818a8.946 8.946 0 01-4.434 0l-1.05-1.818a.75.75 0 10-1.3.75l1.044 1.806a8.98 8.98 0 00-3.095 5.127h12.186a8.98 8.98 0 00-3.095-5.127zM12 2.25c-1.313 0-2.55.312-3.645.864l1.313 2.273a6.704 6.704 0 014.664 0l1.313-2.273A8.214 8.214 0 0012 2.25zM4.125 9.75a.75.75 0 00-.75.75v6c0 .414.336.75.75.75h.375v3.375c0 .621.504 1.125 1.125 1.125H7.5v-4.875h9v4.875h1.875c.621 0 1.125-.504 1.125-1.125V17.25h.375a.75.75 0 00.75-.75v-6a.75.75 0 00-.75-.75H4.125z" />
                                    </svg>
                                </span>
                                <span class="landing__store-label">
                                    <span class="landing__store-label-small">{{ t('landing.downloadAndroidHint') }}</span>
                                    <span class="landing__store-label-main">Android</span>
                                </span>
                            </a>
                        </div>
                    </div>
                </div>
                <button type="button" class="landing__header-cta" @click="openRequest">
                    {{ t('landing.ctaButton') }}
                </button>
            </template>
            <Link v-else href="/" class="landing__nav-link" @click="closeMobileNav">{{ t('landing.backHome') }}</Link>
            <LandingLocaleSwitcher />
        </nav>
    </header>
</template>

<style scoped>
.landing__header {
    z-index: 2;
    padding: 1.5rem clamp(1.5rem, 5vw, 3rem);
}

.landing__header--row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.landing__brand {
    display: inline-flex;
    align-items: center;
    gap: 0.625rem;
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--landing-text);
    text-decoration: none;
    letter-spacing: -0.02em;
}

.landing__nav {
    display: flex;
    align-items: center;
    gap: 1.25rem;
}

.landing__nav-link {
    font-size: 0.875rem;
    color: var(--landing-muted);
    text-decoration: none;
}

.landing__nav-link:hover {
    color: var(--landing-accent);
}

.landing__download-menu {
    position: relative;
}

.landing__download-trigger {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0;
    font-family: inherit;
    font-size: inherit;
    background: none;
    border: none;
    cursor: pointer;
}

.landing__download-chevron {
    width: 0.75rem;
    height: 0.75rem;
    opacity: 0.65;
    transition: transform 0.2s ease, opacity 0.15s ease;
}

.landing__download-menu:hover .landing__download-chevron,
.landing__download-menu:focus-within .landing__download-chevron {
    transform: rotate(180deg);
    opacity: 1;
}

.landing__download-popover {
    position: absolute;
    top: 100%;
    right: 0;
    min-width: 13.5rem;
    padding-top: 0.625rem;
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transform: translateY(-0.25rem) scale(0.98);
    transform-origin: top right;
    transition:
        opacity 0.18s ease,
        visibility 0.18s ease,
        transform 0.22s cubic-bezier(0.34, 1.4, 0.64, 1);
    z-index: 20;
}

.landing__download-popover-panel {
    position: relative;
    padding: 0.375rem;
    background: var(--landing-surface-raised);
    border: 1px solid var(--landing-border);
    border-radius: 0.875rem;
    box-shadow:
        0 4px 6px -1px rgba(0, 0, 0, 0.35),
        0 12px 28px -8px rgba(0, 0, 0, 0.55);
}

.landing__download-popover-panel::before {
    content: '';
    position: absolute;
    top: -0.375rem;
    right: 1.25rem;
    width: 0.625rem;
    height: 0.625rem;
    background: var(--landing-surface-raised);
    border-top: 1px solid var(--landing-border);
    border-left: 1px solid var(--landing-border);
    transform: rotate(45deg);
}

.landing__download-menu:hover .landing__download-popover,
.landing__download-menu:focus-within .landing__download-popover {
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
    transform: translateY(0) scale(1);
}

.landing__store-option {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    width: 100%;
    padding: 0.625rem 0.75rem;
    font-family: inherit;
    text-align: left;
    text-decoration: none;
    color: var(--landing-text);
    background: transparent;
    border: none;
    border-radius: 0.625rem;
    cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease;
}

.landing__store-option:hover:not(.landing__store-option--disabled) {
    background: rgba(1, 185, 100, 0.1);
    color: var(--landing-accent);
}

.landing__store-option--disabled {
    opacity: 0.42;
    cursor: not-allowed;
}

.landing__store-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 2rem;
    height: 2rem;
    color: var(--landing-muted);
}

.landing__store-icon svg {
    width: 1.375rem;
    height: 1.375rem;
}

.landing__store-icon--android {
    color: var(--landing-accent);
}

.landing__store-label {
    display: flex;
    flex-direction: column;
    gap: 0.0625rem;
    min-width: 0;
    flex: 1;
}

.landing__store-label-small {
    font-size: 0.625rem;
    line-height: 1.2;
    color: var(--landing-muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.landing__store-label-main {
    font-size: 0.9375rem;
    font-weight: 600;
    line-height: 1.2;
    letter-spacing: -0.01em;
}

.landing__store-badge {
    flex-shrink: 0;
    padding: 0.125rem 0.4375rem;
    font-size: 0.625rem;
    font-weight: 600;
    color: var(--landing-muted);
    background: rgba(134, 150, 160, 0.12);
    border-radius: 999px;
}

.landing__header-cta {
    padding: 0.5rem 1.125rem;
    font-size: 0.8125rem;
    font-weight: 600;
    font-family: inherit;
    color: #fff;
    background: var(--landing-accent);
    border: none;
    border-radius: 999px;
    cursor: pointer;
    white-space: nowrap;
}

.landing__header-cta:hover {
    background: var(--landing-accent-hover);
}

.landing__nav-toggle {
    display: none;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    padding: 0.25rem;
    background: none;
    border: none;
    color: var(--landing-muted);
    cursor: pointer;
}

.landing__nav-toggle-icon {
    width: 1.5rem;
    height: 1.5rem;
}

@media (max-width: 640px) {
    .landing__header--row {
        flex-wrap: nowrap;
        position: relative;
    }

    .landing__nav-toggle {
        display: flex;
    }

    .landing__nav {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        flex-direction: column;
        align-items: stretch;
        gap: 0.75rem;
        margin-top: 0.75rem;
        padding: 0.75rem clamp(1.5rem, 5vw, 3rem) 1rem;
        background: #111;
        border-bottom: 1px solid var(--landing-border);
        z-index: 10;
    }

    .landing__nav--open {
        display: flex;
    }

    .landing__nav-link {
        padding: 0.625rem 0;
        font-size: 1rem;
        border-bottom: 1px solid var(--landing-border);
    }

    .landing__download-menu {
        width: 100%;
    }

    .landing__download-trigger {
        width: 100%;
        justify-content: space-between;
    }

    .landing__download-popover {
        position: static;
        min-width: 0;
        padding: 0;
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
        transform: none;
    }

    .landing__download-popover-panel {
        padding: 0.25rem 0 0.5rem;
        background: transparent;
        border: none;
        box-shadow: none;
    }

    .landing__download-popover-panel::before {
        display: none;
    }

    .landing__header-cta {
        width: 100%;
        justify-content: center;
        padding: 0.75rem 1rem;
    }
}
</style>
