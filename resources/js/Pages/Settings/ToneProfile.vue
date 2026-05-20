<script setup lang="ts">
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type ProfilePayload = {
    id: number;
    summary: string | null;
    phrases: string[];
    use_manual_override: boolean;
    manual_summary: string | null;
    manual_phrases: string[];
    analyzed_at: string | null;
    metadata: Record<string, unknown>;
};

type EmployeeProfile = {
    id: number;
    user_name: string;
    summary: string | null;
    phrases: string[];
    analyzed_at: string | null;
};

const props = defineProps<{
    profile: ProfilePayload;
    employee_profiles: EmployeeProfile[];
}>();

const form = useForm({
    use_manual_override: props.profile.use_manual_override,
    manual_summary: props.profile.manual_summary ?? props.profile.summary ?? '',
    manual_phrases: [...(props.profile.manual_phrases.length ? props.profile.manual_phrases : props.profile.phrases)],
});

const newPhrase = ref('');
const reanalyzing = ref(false);

const analyzedLabel = computed(() => {
    if (!props.profile.analyzed_at) {
        return 'Автоанализ ещё не выполнялся';
    }

    return `Обновлено: ${new Date(props.profile.analyzed_at).toLocaleString('ru-RU')}`;
});

function addPhrase(): void {
    const value = newPhrase.value.trim();
    if (!value) {
        return;
    }
    form.manual_phrases = [...form.manual_phrases, value];
    newPhrase.value = '';
}

function removePhrase(index: number): void {
    form.manual_phrases = form.manual_phrases.filter((_, i) => i !== index);
}

function submit(): void {
    form.put(route('settings.tone-profile.update'), { preserveScroll: true });
}

function reanalyze(): void {
    if (reanalyzing.value) {
        return;
    }
    reanalyzing.value = true;
    router.post(route('settings.tone-profile.reanalyze'), {}, {
        preserveScroll: true,
        onFinish: () => {
            reanalyzing.value = false;
        },
    });
}
</script>

<template>
    <Head title="Профиль тона" />

    <SettingsLayout title="Профиль тона" subtitle="Как AI формулирует ответы от имени компании">
        <div class="w-full space-y-6 px-6 py-6">
            <section class="ui-settings-section space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold" :style="{ color: 'var(--ui-text)' }">Ручная настройка</h3>
                        <p class="mt-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                            Если включено, AI использует ваш текст вместо автоанализа переписки.
                        </p>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm cursor-pointer" :style="{ color: 'var(--ui-text)' }">
                        <UiCheckbox v-model="form.use_manual_override" />
                        Использовать ручной профиль
                    </label>
                </div>

                <label class="block space-y-1">
                    <span class="text-xs" :style="{ color: 'var(--ui-text-secondary)' }">Описание стиля</span>
                    <textarea
                        v-model="form.manual_summary"
                        rows="5"
                        class="settings-input w-full min-h-[120px]"
                        placeholder="Коротко: тон, длина ответов, обращение к клиенту…"
                    />
                </label>

                <div class="space-y-2">
                    <span class="text-xs" :style="{ color: 'var(--ui-text-secondary)' }">Типичные фразы</span>
                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="(phrase, index) in form.manual_phrases"
                            :key="`${phrase}-${index}`"
                            class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs"
                            :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                        >
                            {{ phrase }}
                            <button type="button" class="opacity-60 hover:opacity-100" @click="removePhrase(index)">×</button>
                        </span>
                    </div>
                    <div class="flex gap-2">
                        <input
                            v-model="newPhrase"
                            type="text"
                            class="settings-input flex-1"
                            placeholder="Добавить фразу…"
                            @keydown.enter.prevent="addPhrase"
                        />
                        <button
                            type="button"
                            class="ui-btn ui-btn--secondary ui-btn--sm"
                            @click="addPhrase"
                        >
                            Добавить
                        </button>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 pt-1">
                    <button
                        type="button"
                        class="ui-btn ui-btn--primary"
                        :disabled="form.processing"
                        @click="submit"
                    >
                        {{ form.processing ? 'Сохранение…' : 'Сохранить' }}
                    </button>
                    <button
                        type="button"
                        class="ui-btn ui-btn--ghost"
                        :disabled="reanalyzing"
                        @click="reanalyze"
                    >
                        {{ reanalyzing ? 'Запуск…' : 'Пересобрать из переписки' }}
                    </button>
                </div>
            </section>

            <section class="ui-settings-section">
                <h3 class="text-sm font-semibold" :style="{ color: 'var(--ui-text)' }">Автоанализ компании</h3>
                <p class="mt-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">{{ analyzedLabel }}</p>
                <p class="mt-3 text-sm whitespace-pre-wrap" :style="{ color: 'var(--ui-text)' }">
                    {{ profile.summary || 'Пока нет данных — нажмите «Пересобрать из переписки».' }}
                </p>
                <ul v-if="profile.phrases?.length" class="mt-3 space-y-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                    <li v-for="phrase in profile.phrases" :key="phrase">• {{ phrase }}</li>
                </ul>
            </section>

            <section
                v-if="employee_profiles.length"
                class="ui-settings-section"
            >
                <h3 class="text-sm font-semibold mb-3" :style="{ color: 'var(--ui-text)' }">Профили сотрудников (авто)</h3>
                <ul class="space-y-3">
                    <li
                        v-for="row in employee_profiles"
                        :key="row.id"
                        class="ui-panel px-3 py-2 text-sm"
                    >
                        <div class="font-medium" :style="{ color: 'var(--ui-text)' }">{{ row.user_name }}</div>
                        <p class="mt-1 text-xs line-clamp-2" :style="{ color: 'var(--ui-text-secondary)' }">{{ row.summary || '—' }}</p>
                    </li>
                </ul>
            </section>
        </div>
    </SettingsLayout>
</template>
