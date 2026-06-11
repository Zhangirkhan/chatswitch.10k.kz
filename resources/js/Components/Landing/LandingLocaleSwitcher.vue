<script setup lang="ts">
import type { AppLocale } from '@/i18n/types';
import { useLandingLocale } from '@/composables/useLandingLocale';
import { computed } from 'vue';

const { locale, locales, setLocale, t } = useLandingLocale();

const orderedLocales = computed(() => {
    const order: AppLocale[] = ['kk', 'ru', 'en'];

    return order
        .map((value) => locales.find((option) => option.value === value))
        .filter((option) => option !== undefined);
});

function selectLocale(value: AppLocale): void {
    setLocale(value);
}
</script>

<template>
    <div class="landing-locale" role="group" :aria-label="t('landing.localeSwitcherAria')">
        <button
            v-for="option in orderedLocales"
            :key="option.value"
            type="button"
            class="landing-locale__btn"
            :class="{ 'landing-locale__btn--active': locale === option.value }"
            :aria-pressed="locale === option.value"
            @click="selectLocale(option.value)"
        >
            {{ option.label }}
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
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid var(--landing-border, rgba(148, 163, 184, 0.18));
}

.landing-locale__btn {
    padding: 0.3rem 0.55rem;
    font-size: 0.6875rem;
    font-weight: 600;
    font-family: inherit;
    line-height: 1.2;
    color: var(--landing-muted, #94a3b8);
    background: transparent;
    border: none;
    border-radius: 999px;
    cursor: pointer;
    white-space: nowrap;
    transition: color 0.15s ease, background 0.15s ease;
}

.landing-locale__btn:hover {
    color: var(--landing-text, #e2e8f0);
}

.landing-locale__btn--active {
    color: #04111d;
    background: var(--landing-accent, #10b981);
}
</style>
