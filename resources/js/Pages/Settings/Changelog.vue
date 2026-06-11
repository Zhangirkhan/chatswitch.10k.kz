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

        <div class="max-w-3xl space-y-4">
            <p v-if="items.length === 0" class="rounded-xl border border-[var(--ui-border)] bg-[var(--ui-surface-muted)] px-4 py-6 text-sm text-[var(--ui-text-secondary)]">
                {{ t('settings.changelog.empty') }}
            </p>

            <article
                v-for="entry in items"
                :key="entry.id"
                class="rounded-xl border border-[var(--ui-border)] bg-[var(--ui-surface-muted)] px-5 py-4"
            >
                <time class="text-xs font-medium uppercase tracking-wide text-[var(--ui-text-muted)]">
                    {{ entry.date }}
                </time>
                <h2 class="mt-1 text-base font-semibold text-[var(--ui-text)]">
                    {{ entry.title }}
                </h2>
                <p class="mt-2 whitespace-pre-wrap text-sm leading-relaxed text-[var(--ui-text-secondary)]">
                    {{ entry.body }}
                </p>
            </article>
        </div>
    </SettingsLayout>
</template>
