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
];

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
        <div class="w-full px-6 py-6">
            <div
                class="rounded-lg border p-6 max-w-3xl"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
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

                <div class="flex items-center gap-3 mt-6">
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
</style>
