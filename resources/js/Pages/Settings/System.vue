<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import axios from 'axios';

const props = defineProps<{
    settings: Record<string, string>;
}>();

const form = ref<Record<string, string>>({ ...props.settings });
const isSaving = ref(false);
const saved = ref(false);

const settingsConfig = [
    { key: 'company_name', label: 'Название компании', type: 'text' },
    { key: 'auto_assign_chats', label: 'Авто-назначение чатов', type: 'select', options: ['off', 'round-robin', 'least-busy'] },
    { key: 'max_sessions', label: 'Макс. количество WhatsApp номеров', type: 'number' },
    { key: 'notification_sound', label: 'Звук уведомлений', type: 'select', options: ['on', 'off'] },
    {
        key: 'analytics.sla_first_response_seconds',
        label: 'SLA первого ответа (секунды), для аналитики диалогов',
        type: 'number',
    },
];

const modulesConfig: { key: string; label: string; description: string }[] = [
    {
        key: 'module_calendar',
        label: 'Календарь записей',
        description: 'Позволяет сотрудникам создавать записи с повторениями (час, день, месяц).',
    },
];

function moduleEnabled(key: string): boolean {
    return (form.value[key] ?? 'on') !== 'off';
}

function toggleModule(key: string): void {
    form.value[key] = moduleEnabled(key) ? 'off' : 'on';
}

async function save() {
    isSaving.value = true;
    saved.value = false;
    try {
        await axios.post(route('settings.system.update'), { settings: form.value });
        saved.value = true;
        setTimeout(() => saved.value = false, 3000);
    } catch (err: any) {
        alert(err.response?.data?.message || 'Ошибка сохранения');
    } finally {
        isSaving.value = false;
    }
}
</script>

<template>
    <Head title="Настройки системы" />
    <SettingsLayout title="Настройки системы" subtitle="Общие параметры рабочего пространства">
        <div class="w-full px-6 py-6 space-y-6">

            <!-- Общие настройки -->
            <div
                class="rounded-lg border p-6 max-w-3xl"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <h2 class="text-sm font-semibold mb-4" :style="{ color: 'var(--wa-text)' }">Общие настройки</h2>
                <div class="space-y-4">
                    <div v-for="cfg in settingsConfig" :key="cfg.key">
                        <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">{{ cfg.label }}</label>
                        <input
                            v-if="cfg.type === 'text' || cfg.type === 'number'"
                            v-model="form[cfg.key]"
                            :type="cfg.type"
                            class="settings-input"
                        />
                        <select
                            v-else-if="cfg.type === 'select'"
                            v-model="form[cfg.key]"
                            class="settings-input"
                        >
                            <option v-for="opt in cfg.options" :key="opt" :value="opt">{{ opt }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Модули -->
            <div
                class="rounded-lg border p-6 max-w-3xl"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <h2 class="text-sm font-semibold mb-4" :style="{ color: 'var(--wa-text)' }">Модули</h2>
                <div class="space-y-3">
                    <div
                        v-for="mod in modulesConfig"
                        :key="mod.key"
                        class="module-row"
                        :class="{ 'module-row-on': moduleEnabled(mod.key) }"
                    >
                        <div class="module-info">
                            <span class="module-label">{{ mod.label }}</span>
                            <span class="module-desc">{{ mod.description }}</span>
                        </div>
                        <button
                            type="button"
                            class="toggle-btn"
                            :class="{ 'toggle-btn-on': moduleEnabled(mod.key) }"
                            @click="toggleModule(mod.key)"
                            :title="moduleEnabled(mod.key) ? 'Выключить' : 'Включить'"
                        >
                            <span class="toggle-thumb"></span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Кнопка сохранить -->
            <div class="flex items-center gap-3 max-w-3xl">
                <button
                    @click="save"
                    :disabled="isSaving"
                    class="px-6 py-2 text-sm rounded-lg transition hover:brightness-95 disabled:opacity-50"
                    :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                >
                    {{ isSaving ? 'Сохранение...' : 'Сохранить' }}
                </button>
                <span v-if="saved" class="text-sm" :style="{ color: 'var(--wa-accent)' }">Сохранено!</span>
            </div>
        </div>
    </SettingsLayout>
</template>

<style scoped>
.settings-input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border-radius: 0.5rem;
    font-size: 0.875rem;
    background: var(--wa-bg);
    color: var(--wa-text);
    border: 1px solid var(--wa-border-strong);
    transition: border-color 0.15s ease;
}
.settings-input:focus {
    outline: none;
    border-color: var(--wa-accent);
}

/* Modules */
.module-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.75rem 1rem;
    border-radius: 0.5rem;
    border: 1px solid var(--wa-border);
    background: var(--wa-bg);
    transition: border-color 0.2s;
}
.module-row-on {
    border-color: var(--wa-accent);
}
.module-info {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}
.module-label {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--wa-text);
}
.module-desc {
    font-size: 0.75rem;
    color: var(--wa-text-secondary);
}

/* Toggle switch */
.toggle-btn {
    position: relative;
    width: 2.75rem;
    height: 1.5rem;
    border-radius: 9999px;
    background: var(--wa-border-strong);
    border: none;
    cursor: pointer;
    flex-shrink: 0;
    transition: background 0.2s;
}
.toggle-btn-on {
    background: var(--wa-accent);
}
.toggle-thumb {
    position: absolute;
    top: 0.2rem;
    left: 0.2rem;
    width: 1.1rem;
    height: 1.1rem;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,.25);
    transition: transform 0.2s;
}
.toggle-btn-on .toggle-thumb {
    transform: translateX(1.25rem);
}
</style>
