<script setup lang="ts">
import SectionHeader from './SectionHeader.vue';
import SettingToggle from './SettingToggle.vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';

interface ModuleDef {
    key: string;
    settingKey: string;
    title: string;
    description: string;
}

const MODULES: readonly ModuleDef[] = [
    {
        key: 'tasks',
        settingKey: 'module_tasks',
        title: 'Задачи и отделы',
        description: 'Раздел «Организация»: отделы, задачи, комментарии и архив.',
    },
    {
        key: 'calendar',
        settingKey: 'module_calendar',
        title: 'Календарь записей',
        description: 'Записи с напоминаниями и повторениями (час, день, месяц).',
    },
    {
        key: 'funnels',
        settingKey: 'module_funnels',
        title: 'Воронки продаж',
        description: 'Этапы и статусы сделок, аналитика воронок.',
    },
    {
        key: 'products',
        settingKey: 'module_products',
        title: 'Товары',
        description: 'Каталог товаров в базе знаний для AI.',
    },
    {
        key: 'services',
        settingKey: 'module_services',
        title: 'Услуги',
        description: 'Услуги, цены и условия в базе знаний для AI.',
    },
    {
        key: 'knowledge',
        settingKey: 'module_knowledge',
        title: 'База знаний',
        description: 'Правила и инструкции для ответов AI.',
    },
    {
        key: 'ai_quality',
        settingKey: 'module_ai_quality',
        title: 'AI и качество',
        description: 'Журнал ошибок AI и оценки сгенерированных ответов.',
    },
] as const;

const page = usePage<any>();
const isAdmin = computed<boolean>(() => Array.isArray(page.props.auth?.user?.roles)
    && page.props.auth.user.roles.includes('administrator'));

// Локальная карта переключателей. Источник правды — `page.props.modules`,
// которое перезагружается после каждого PATCH через router.reload(only: ['modules']).
const local = reactive<Record<string, boolean>>(
    Object.fromEntries(MODULES.map((m) => [m.key, Boolean(page.props.modules?.[m.key] ?? true)])),
);

const saving = ref<Record<string, boolean>>({});
const errorText = ref<string | null>(null);

async function setModule(mod: ModuleDef, value: boolean): Promise<void> {
    if (!isAdmin.value) return;
    if (saving.value[mod.key]) return;

    const previous = local[mod.key];
    local[mod.key] = value;
    saving.value[mod.key] = true;
    errorText.value = null;

    try {
        await axios.post(route('settings.system.update'), {
            settings: { [mod.settingKey]: value ? 'on' : 'off' },
        });
        router.reload({ only: ['modules', 'orgOpenTasksCount'] });
    } catch (err: any) {
        local[mod.key] = previous;
        errorText.value = err?.response?.data?.message || 'Не удалось сохранить настройку модуля.';
    } finally {
        saving.value[mod.key] = false;
    }
}
</script>

<template>
    <div class="h-full flex flex-col">
        <SectionHeader title="Модули" />

        <div class="flex-1 overflow-y-auto wa-scrollbar py-2">
            <div v-if="!isAdmin" class="px-6 py-4 text-sm text-[var(--wa-text-secondary)]">
                Управлять модулями может только администратор.
            </div>

            <template v-else>
                <div class="px-6 pt-2 pb-4 text-xs text-[var(--wa-text-secondary)] leading-relaxed">
                    Включайте или отключайте функционал для всей компании.
                    При выключении соответствующие разделы и действия станут недоступны для всех пользователей.
                </div>

                <SettingToggle
                    v-for="mod in MODULES"
                    :key="mod.key"
                    :model-value="local[mod.key]"
                    :title="mod.title"
                    :description="mod.description"
                    @update:model-value="(v: boolean) => setModule(mod, v)"
                />

                <div v-if="errorText" class="px-6 pt-2 text-xs" :style="{ color: 'var(--wa-danger)' }">
                    {{ errorText }}
                </div>
            </template>
        </div>
    </div>
</template>
