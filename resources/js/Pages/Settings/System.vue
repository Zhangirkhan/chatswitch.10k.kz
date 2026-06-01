<script setup lang="ts">
import EntityMemoryPanel from '@/Components/Memory/EntityMemoryPanel.vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import axios from 'axios';
import { useToastStore } from '@/stores/toast';

const { show: showToast } = useToastStore();

const page = usePage();
const tenantCompanyId = computed(() => (page.props as { tenantCompanyId?: number }).tenantCompanyId ?? 1);

const slaEnabledKey = 'chats.sla_reminders.enabled';
const slaMinutesKey = 'chats.sla_reminder_minutes';

const props = defineProps<{
    settings: Record<string, string>;
}>();

const form = ref<Record<string, string>>(
    Object.fromEntries(
        Object.entries(props.settings).filter(([key]) => !key.startsWith('module_')),
    ),
);
const isSaving = ref(false);
const saved = ref(false);
const quickReactionKey = 'chat.quick_reaction_emojis';
const defaultQuickReactions = ['👍', '❤️', '😂', '😮', '😢'];
const quickReactions = ref<string[]>(parseQuickReactions(form.value[quickReactionKey]));

const settingsConfig = [
    { key: 'company_name', label: 'Название компании', type: 'text' },
    { key: 'auto_assign_chats', label: 'Авто-назначение чатов', type: 'select', options: ['off', 'round-robin', 'least-busy'] },
    { key: 'notification_sound', label: 'Звук уведомлений', type: 'select', options: ['on', 'off'] },
    {
        key: 'analytics.sla_first_response_seconds',
        label: 'SLA первого ответа (секунды), для аналитики диалогов',
        type: 'number',
    },
];

const reminderLeadTimeOptions: { value: string; label: string }[] = [
    { value: '15', label: 'за 15 минут' },
    { value: '30', label: 'за 30 минут' },
    { value: '60', label: 'за 1 час' },
    { value: '120', label: 'за 2 часа' },
    { value: '1440', label: 'за 1 день' },
];

function parseQuickReactions(raw: string | undefined): string[] {
    if (!raw) {
        return [...defaultQuickReactions];
    }

    try {
        const decoded = JSON.parse(raw);
        if (Array.isArray(decoded)) {
            return normalizeQuickReactions(decoded);
        }
    } catch {
        // Support manually edited space/comma separated settings.
    }

    return normalizeQuickReactions(raw.split(/[\s,]+/u));
}

function normalizeQuickReactions(values: unknown[]): string[] {
    const normalized = values
        .filter((value): value is string => typeof value === 'string')
        .map((value) => value.trim())
        .filter((value, index, list) => value !== '' && list.indexOf(value) === index)
        .slice(0, 5);

    for (const emoji of defaultQuickReactions) {
        if (normalized.length >= 5) {
            break;
        }
        if (!normalized.includes(emoji)) {
            normalized.push(emoji);
        }
    }

    return normalized.slice(0, 5);
}

function remindersEnabled(): boolean {
    return (form.value['appointment_reminders.enabled'] ?? 'on') !== 'off';
}

function toggleReminders(): void {
    form.value['appointment_reminders.enabled'] = remindersEnabled() ? 'off' : 'on';
}

function slaRemindersEnabled(): boolean {
    return (form.value[slaEnabledKey] ?? 'on') !== 'off';
}

function toggleSlaReminders(): void {
    form.value[slaEnabledKey] = slaRemindersEnabled() ? 'off' : 'on';
}

async function save() {
    isSaving.value = true;
    saved.value = false;
    try {
        form.value[quickReactionKey] = JSON.stringify(normalizeQuickReactions(quickReactions.value));
        await axios.post(route('settings.system.update'), { settings: form.value });
        saved.value = true;
        setTimeout(() => saved.value = false, 3000);
    } catch (err: any) {
        showToast({ message: err.response?.data?.message || 'Ошибка сохранения', type: 'warning' });
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
                class="ui-settings-section ui-settings-section--narrow"
            >
                <h2 class="text-sm font-semibold mb-4" :style="{ color: 'var(--ui-text)' }">Общие настройки</h2>
                <div class="space-y-4">
                    <div v-for="cfg in settingsConfig" :key="cfg.key">
                        <label class="block text-sm text-[var(--ui-text-secondary)] mb-1">{{ cfg.label }}</label>
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

            <!-- Быстрые реакции -->
            <div
                class="ui-settings-section ui-settings-section--narrow"
            >
                <h2 class="text-sm font-semibold mb-1" :style="{ color: 'var(--ui-text)' }">Быстрые реакции</h2>
                <p class="text-xs mb-4" :style="{ color: 'var(--ui-text-secondary)' }">
                    Эти 5 эмодзи будут показываться в быстрой панели реакций для всей компании.
                </p>

                <div class="grid grid-cols-5 gap-2 max-w-md">
                    <label
                        v-for="(_, index) in quickReactions"
                        :key="index"
                        class="quick-reaction-field"
                    >
                        <span class="sr-only">Быстрая реакция {{ index + 1 }}</span>
                        <input
                            v-model="quickReactions[index]"
                            type="text"
                            maxlength="16"
                            class="quick-reaction-input"
                            inputmode="text"
                        />
                    </label>
                </div>
                <p class="mt-2 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                    Лучше ставить по одному эмодзи в каждое поле. Кнопка “+” для полного выбора эмодзи останется в чате отдельно.
                </p>
            </div>

            <!-- SLA в чатах -->
            <div
                class="ui-settings-section ui-settings-section--narrow"
            >
                <h2 class="text-sm font-semibold mb-1" :style="{ color: 'var(--ui-text)' }">SLA в чатах</h2>
                <p class="text-xs mb-4" :style="{ color: 'var(--ui-text-secondary)' }">
                    Внутренние напоминания в ленте организации, если клиент ждёт ответ дольше заданного времени.
                </p>
                <div class="space-y-4">
                    <div class="module-row" :class="{ 'module-row-on': slaRemindersEnabled() }">
                        <div class="module-info">
                            <span class="module-label">SLA-напоминания</span>
                            <span class="module-desc">Создавать задачу в организации при долгом ожидании клиента.</span>
                        </div>
                        <button
                            type="button"
                            class="toggle-btn"
                            :class="{ 'toggle-btn-on': slaRemindersEnabled() }"
                            @click="toggleSlaReminders"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-[var(--ui-text-secondary)] mb-1">Клиент ждёт ответ более (минут)</label>
                        <input
                            v-model="form[slaMinutesKey]"
                            type="number"
                            min="5"
                            max="120"
                            step="1"
                            class="settings-input max-w-[10rem]"
                            :disabled="!slaRemindersEnabled()"
                        />
                        <p class="mt-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                            От 5 до 120 минут. Cron проверяет чаты каждые 5 минут.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Записи и напоминания -->
            <div
                class="ui-settings-section ui-settings-section--narrow"
            >
                <h2 class="text-sm font-semibold mb-1" :style="{ color: 'var(--ui-text)' }">Записи и напоминания</h2>
                <p class="text-xs mb-4" :style="{ color: 'var(--ui-text-secondary)' }">
                    Настройки применяются к новым записям, которые AI создаёт в календаре.
                </p>

                <div class="space-y-4">
                    <div
                        class="module-row"
                        :class="{ 'module-row-on': remindersEnabled() }"
                    >
                        <div class="module-info">
                            <span class="module-label">Напоминание клиенту о записи</span>
                            <span class="module-desc">Автоматически создаёт отложенное сообщение после записи в календарь.</span>
                        </div>
                        <button
                            type="button"
                            class="toggle-btn"
                            :class="{ 'toggle-btn-on': remindersEnabled() }"
                            @click="toggleReminders"
                            :title="remindersEnabled() ? 'Выключить напоминания' : 'Включить напоминания'"
                        >
                            <span class="toggle-thumb"></span>
                        </button>
                    </div>

                    <div>
                        <label class="block text-sm text-[var(--ui-text-secondary)] mb-1">Когда отправлять напоминание</label>
                        <div class="grid gap-3 sm:grid-cols-[1fr_9rem]">
                            <select
                                v-model="form['appointment_reminders.lead_time_minutes']"
                                class="settings-input"
                                :disabled="!remindersEnabled()"
                            >
                                <option v-for="opt in reminderLeadTimeOptions" :key="opt.value" :value="opt.value">
                                    {{ opt.label }}
                                </option>
                            </select>
                            <input
                                v-model="form['appointment_reminders.lead_time_minutes']"
                                type="number"
                                min="5"
                                max="10080"
                                step="5"
                                class="settings-input"
                                :disabled="!remindersEnabled()"
                                aria-label="Своё время напоминания в минутах"
                            />
                        </div>
                        <p class="mt-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                            Можно указать своё значение в минутах: от 5 минут до 7 дней. Если выбранное время уже прошло, напоминание для этой записи не создаётся.
                        </p>
                    </div>
                </div>
            </div>


            <EntityMemoryPanel
                v-if="tenantCompanyId"
                subject-type="tenant"
                :subject-id="tenantCompanyId"
                class="max-w-3xl mb-8"
            />

            <!-- Кнопка сохранить -->
            <div class="flex items-center gap-3 max-w-3xl">
                <button
                    @click="save"
                    :disabled="isSaving"
                    class="ui-btn ui-btn--primary"
                >
                    {{ isSaving ? 'Сохранение...' : 'Сохранить' }}
                </button>
                <span v-if="saved" class="text-sm" :style="{ color: 'var(--ui-accent)' }">Сохранено!</span>
            </div>
        </div>
    </SettingsLayout>
</template>

<style scoped>
.quick-reaction-field {
    display: block;
}
.quick-reaction-input {
    width: 100%;
    height: 3rem;
    border-radius: 0.75rem;
    border: 1px solid var(--ui-border-strong);
    background: var(--ui-bg);
    color: var(--ui-text);
    font-size: 1.5rem;
    line-height: 1;
    text-align: center;
    transition: border-color 0.15s ease, background 0.15s ease;
}
.quick-reaction-input:focus {
    outline: none;
    border-color: var(--ui-accent);
    background: var(--ui-surface-muted);
}

/* Toggle switch */
.toggle-btn {
    position: relative;
    width: 2.75rem;
    height: 1.5rem;
    border-radius: 9999px;
    background: var(--ui-border-strong);
    border: none;
    cursor: pointer;
    flex-shrink: 0;
    transition: background 0.2s;
}
.toggle-btn-on {
    background: var(--ui-accent);
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
