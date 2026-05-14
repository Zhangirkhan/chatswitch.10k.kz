<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { Head } from '@inertiajs/vue3';

type FailedLog = {
    id: number;
    created_at: string | null;
    status: string;
    mode: string | null;
    error: string | null;
    chat: string;
    company: string | null;
};

type ProblemRating = {
    id: number;
    rating: string;
    created_at: string | null;
    user: string | null;
    chat: string;
    body_preview: string;
};

const props = defineProps<{
    failed_logs: FailedLog[];
    problem_ratings: ProblemRating[];
}>();

const ratingLabels: Record<string, string> = {
    style: 'Стиль / тон',
    facts: 'Факты',
    long: 'Слишком длинно',
    context: 'Нет в базе знаний',
};

function formatWhen(iso: string | null): string {
    if (!iso) {
        return '—';
    }
    try {
        return new Intl.DateTimeFormat('ru-RU', {
            dateStyle: 'short',
            timeStyle: 'short',
        }).format(new Date(iso));
    } catch {
        return iso;
    }
}
</script>

<template>
    <Head title="AI и качество" />

    <SettingsLayout title="AI и качество" subtitle="Сбои генерации и негативные оценки ответов операторов">
        <div class="w-full space-y-8 px-6 py-6">
            <section
                class="rounded-xl border p-5"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <h2 class="text-sm font-semibold" :style="{ color: 'var(--wa-text)' }">Сбои и блокировки AI</h2>
                <p class="mt-1 text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                    Последние записи со статусом «failed» или «blocked» в журнале ответов AI.
                </p>

                <div v-if="failed_logs.length === 0" class="mt-4 text-sm" :style="{ color: 'var(--wa-text-secondary)' }">
                    Записей пока нет — это хороший знак.
                </div>

                <ul v-else class="mt-4 space-y-3">
                    <li
                        v-for="row in failed_logs"
                        :key="row.id"
                        class="rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--wa-border)', color: 'var(--wa-text)' }"
                    >
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="font-medium">{{ row.chat }}</span>
                            <span class="text-xs" :style="{ color: 'var(--wa-text-secondary)' }">{{ formatWhen(row.created_at) }}</span>
                        </div>
                        <div class="mt-1 flex flex-wrap gap-2 text-xs">
                            <span class="rounded bg-black/5 px-1.5 py-0.5">{{ row.status }}</span>
                            <span v-if="row.mode" class="rounded bg-black/5 px-1.5 py-0.5">{{ row.mode }}</span>
                            <span v-if="row.company">{{ row.company }}</span>
                        </div>
                        <p v-if="row.error" class="mt-2 whitespace-pre-wrap break-words text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                            {{ row.error }}
                        </p>
                    </li>
                </ul>
            </section>

            <section
                class="rounded-xl border p-5"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <h2 class="text-sm font-semibold" :style="{ color: 'var(--wa-text)' }">Оценки «нужно улучшить»</h2>
                <p class="mt-1 text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                    Операторы отмечают AI-сообщения в чате; здесь собраны не «Ок», а проблемные категории.
                </p>

                <div v-if="problem_ratings.length === 0" class="mt-4 text-sm" :style="{ color: 'var(--wa-text-secondary)' }">
                    Пока нет таких оценок.
                </div>

                <ul v-else class="mt-4 space-y-3">
                    <li
                        v-for="row in problem_ratings"
                        :key="row.id"
                        class="rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--wa-border)', color: 'var(--wa-text)' }"
                    >
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="font-medium">{{ ratingLabels[row.rating] ?? row.rating }}</span>
                            <span class="text-xs" :style="{ color: 'var(--wa-text-secondary)' }">{{ formatWhen(row.created_at) }}</span>
                        </div>
                        <div class="mt-1 text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                            {{ row.chat }} · {{ row.user ?? 'Оператор' }}
                        </div>
                        <p class="mt-2 whitespace-pre-wrap break-words text-xs opacity-90">{{ row.body_preview }}</p>
                    </li>
                </ul>
            </section>
        </div>
    </SettingsLayout>
</template>
