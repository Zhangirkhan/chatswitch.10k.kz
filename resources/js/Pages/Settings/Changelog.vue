<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { useI18n } from '@/composables/useI18n';
import type { AppLocale } from '@/i18n/types';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

interface ChangelogEntry {
    id: number;
    published_at: string;
    title: Record<string, string>;
    body: Record<string, string>;
}

const props = defineProps<{
    entries: ChangelogEntry[];
}>();

const { t, locale } = useI18n();

function localized(map: Record<string, string> | undefined, current: AppLocale): string {
    if (!map) return '';
    const value = map[current] ?? map.ru ?? map.en ?? map.kk ?? '';
    return value.trim();
}

function formatDate(iso: string): string {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return iso;
    return date.toLocaleDateString(locale.value === 'en' ? 'en-US' : locale.value === 'kk' ? 'kk-KZ' : 'ru-RU', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

const items = computed(() =>
    props.entries.map((entry) => ({
        id: entry.id,
        date: formatDate(entry.published_at),
        title: localized(entry.title, locale.value),
        body: localized(entry.body, locale.value),
    })),
);
</script>

<template>
    <SettingsLayout :title="t('settings.changelog.title')" :subtitle="t('settings.changelog.subtitle')">
        <Head :title="t('settings.changelog.title')" />

        <div class="w-full px-6 py-6">
            <div class="changelog-page mx-auto w-full max-w-2xl">
                <p
                    v-if="items.length === 0"
                    class="ui-empty-state text-sm text-[var(--ui-text-secondary)]"
                >
                    {{ t('settings.changelog.empty') }}
                </p>

                <ol v-else class="changelog-page__list">
                    <li v-for="entry in items" :key="entry.id">
                        <article class="ui-settings-section changelog-page__entry">
                            <time class="changelog-page__date">
                                {{ entry.date }}
                            </time>
                            <h2 class="changelog-page__title">
                                {{ entry.title }}
                            </h2>
                            <p class="changelog-page__body">
                                {{ entry.body }}
                            </p>
                        </article>
                    </li>
                </ol>
            </div>
        </div>
    </SettingsLayout>
</template>

<style scoped>
.changelog-page__list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.changelog-page__entry {
    padding: 1.25rem 1.5rem;
}

.changelog-page__date {
    display: block;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--ui-text-muted);
}

.changelog-page__title {
    margin-top: 0.625rem;
    font-size: 1.0625rem;
    font-weight: 600;
    line-height: 1.35;
    color: var(--ui-text);
}

.changelog-page__body {
    margin-top: 0.75rem;
    max-width: 42rem;
    white-space: pre-wrap;
    font-size: 0.9375rem;
    line-height: 1.6;
    color: var(--ui-text-secondary);
}
</style>
