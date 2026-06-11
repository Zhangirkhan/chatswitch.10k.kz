<script setup lang="ts">
import type { AppLocale } from '@/i18n/types';
import { useLandingLocale } from '@/composables/useLandingLocale';
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = withDefaults(defineProps<{
    variant?: 'compact' | 'full' | 'auto';
}>(), {
    variant: 'auto',
});

const LOCALE_CODES: Record<AppLocale, string> = {
    kk: 'KK',
    ru: 'RU',
    en: 'EN',
};

const { locale, locales, setLocale, t } = useLandingLocale();

const isDesktop = ref(
    typeof window !== 'undefined' && window.matchMedia('(min-width: 961px)').matches,
);

let mediaQuery: MediaQueryList | null = null;

function syncViewport(event?: MediaQueryListEvent): void {
    isDesktop.value = event?.matches ?? window.matchMedia('(min-width: 961px)').matches;
}

onMounted(() => {
    mediaQuery = window.matchMedia('(min-width: 961px)');
    mediaQuery.addEventListener('change', syncViewport);
});

onUnmounted(() => {
    mediaQuery?.removeEventListener('change', syncViewport);
});

const isCompact = computed(() => {
    if (props.variant === 'compact') {
        return true;
    }

    if (props.variant === 'full') {
        return false;
    }

    return isDesktop.value;
});

const orderedLocales = computed(() => {
    const order: AppLocale[] = ['kk', 'ru', 'en'];

    return order
        .map((value) => locales.find((option) => option.value === value))
        .filter((option) => option !== undefined);
});

function selectLocale(value: AppLocale): void {
    setLocale(value);
}

function localeCode(value: AppLocale): string {
    return LOCALE_CODES[value];
}
</script>

<template>
    <div
        class="landing-locale"
        :class="{ 'landing-locale--compact': isCompact, 'landing-locale--full': !isCompact }"
        role="group"
        :aria-label="t('landing.localeSwitcherAria')"
    >
        <button
            v-for="option in orderedLocales"
            :key="option.value"
            type="button"
            class="landing-locale__btn"
            :class="{ 'landing-locale__btn--active': locale === option.value }"
            :aria-pressed="locale === option.value"
            :title="option.label"
            @click="selectLocale(option.value)"
        >
            <span class="landing-locale__code">{{ localeCode(option.value) }}</span>
            <span class="landing-locale__label">{{ option.label }}</span>
        </button>
    </div>
</template>

<style scoped>
.landing-locale {
    display: inline-flex;
    align-items: center;
    gap: 0.125rem;
    padding: 0.125rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--landing-border, rgba(148, 163, 184, 0.18));
}

.landing-locale__btn {
    padding: 0.25rem 0.4rem;
    font-size: 0.75rem;
    font-weight: 600;
    font-family: inherit;
    line-height: 1.2;
    color: var(--landing-muted, #94a3b8);
    background: transparent;
    border: 1px solid transparent;
    border-radius: 999px;
    cursor: pointer;
    white-space: nowrap;
    transition: color 0.15s ease, background 0.15s ease, border-color 0.15s ease;
}

.landing-locale__btn:hover {
    color: var(--landing-text, #e2e8f0);
}

.landing-locale__btn--active {
    color: var(--landing-text, #e2e8f0);
    background: rgba(255, 255, 255, 0.08);
    border-color: var(--landing-border, rgba(148, 163, 184, 0.18));
}

.landing-locale--compact .landing-locale__label {
    display: none;
}

.landing-locale--full .landing-locale__code {
    display: none;
}

.landing-locale--full .landing-locale__btn {
    padding: 0.3rem 0.55rem;
    font-size: 0.6875rem;
}
</style>
