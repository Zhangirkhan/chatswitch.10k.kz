<script setup lang="ts">
import EntityMemoryPanel from '@/Components/Memory/EntityMemoryPanel.vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import axios from 'axios';
import { useToastStore } from '@/stores/toast';

const { show: showToast } = useToastStore();
const { t } = useI18n();

const page = usePage();
const tenantCompanyId = computed(() => (page.props as { tenantCompanyId?: number }).tenantCompanyId ?? 1);

const slaEnabledKey = 'chats.sla_reminders.enabled';
const slaMinutesKey = 'chats.sla_reminder_minutes';

const props = defineProps<{
    settings: Record<string, string>;
    modules: Array<{
        key: string;
        label: string;
        description: string;
        enabled: boolean;
    }>;
}>();

const form = ref<Record<string, string>>(
    Object.fromEntries(
        Object.entries(props.settings).filter(([key]) => !key.startsWith('module_')),
    ),
);
const isSaving = ref(false);
const saved = ref(false);
const moduleForm = ref<Record<string, boolean>>(
    Object.fromEntries(props.modules.map((module) => [module.key, module.enabled])),
);
const isSavingModules = ref(false);
const modulesSaved = ref(false);
const quickReactionKey = 'chat.quick_reaction_emojis';
const defaultQuickReactions = ['👍', '❤️', '😂', '😮', '😢'];
const quickReactions = ref<string[]>(parseQuickReactions(form.value[quickReactionKey]));

const settingsConfig = computed(() => [
    { key: 'company_name', label: t('settings.system.fieldCompanyName'), type: 'text' as const },
    {
        key: 'auto_assign_chats',
        label: t('settings.system.fieldAutoAssign'),
        type: 'select' as const,
        options: ['off', 'round-robin', 'least-busy'] as const,
        optionLabels: {
            off: t('settings.system.autoAssignOff'),
            'round-robin': t('settings.system.autoAssignRoundRobin'),
            'least-busy': t('settings.system.autoAssignLeastBusy'),
        },
    },
    {
        key: 'notification_sound',
        label: t('settings.system.fieldNotificationSound'),
        type: 'select' as const,
        options: ['on', 'off'] as const,
        optionLabels: {
            on: t('settings.system.soundOn'),
            off: t('settings.system.soundOff'),
        },
    },
    {
        key: 'analytics.sla_first_response_seconds',
        label: t('settings.system.fieldSlaAnalytics'),
        type: 'number' as const,
    },
]);

const reminderLeadTimeOptions = computed(() => [
    { value: '15', label: t('settings.system.leadTime15') },
    { value: '30', label: t('settings.system.leadTime30') },
    { value: '60', label: t('settings.system.leadTime60') },
    { value: '120', label: t('settings.system.leadTime120') },
    { value: '1440', label: t('settings.system.leadTime1440') },
]);

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

function toggleModule(key: string): void {
    moduleForm.value[key] = !moduleForm.value[key];
}

async function saveModules() {
    isSavingModules.value = true;
    modulesSaved.value = false;
    try {
        await axios.post(route('settings.system.modules.update'), { modules: moduleForm.value });
        modulesSaved.value = true;
        setTimeout(() => modulesSaved.value = false, 3000);
    } catch (err: any) {
        showToast({ message: err.response?.data?.message || t('settings.system.modulesErrorSave'), type: 'warning' });
    } finally {
        isSavingModules.value = false;
    }
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
        showToast({ message: err.response?.data?.message || t('settings.system.errorSave'), type: 'warning' });
    } finally {
        isSaving.value = false;
    }
}
</script>

<template>
    <Head :title="t('settings.system.title')" />
    <SettingsLayout :title="t('settings.system.title')" :subtitle="t('settings.system.subtitle')">
        <div class="w-full px-6 py-6 space-y-6">

            <!-- Общие настройки -->
            <div
                class="ui-settings-section ui-settings-section--narrow"
            >
                <h2 class="text-sm font-semibold mb-4" :style="{ color: 'var(--ui-text)' }">{{ t('settings.system.sectionGeneral') }}</h2>
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
                            <option v-for="opt in cfg.options" :key="opt" :value="opt">
                                {{ cfg.optionLabels?.[opt] ?? opt }}
                            </option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Быстрые реакции -->
            <div
                class="ui-settings-section ui-settings-section--narrow"
            >
                <h2 class="text-sm font-semibold mb-1" :style="{ color: 'var(--ui-text)' }">{{ t('settings.system.sectionQuickReactions') }}</h2>
                <p class="text-xs mb-4" :style="{ color: 'var(--ui-text-secondary)' }">
                    {{ t('settings.system.quickReactionsHint') }}
                </p>

                <div class="grid grid-cols-5 gap-2 max-w-md">
                    <label
                        v-for="(_, index) in quickReactions"
                        :key="index"
                        class="quick-reaction-field"
                    >
                        <span class="sr-only">{{ t('settings.system.quickReactionField', { n: index + 1 }) }}</span>
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
                    {{ t('settings.system.quickReactionsNote') }}
                </p>
            </div>

            <!-- SLA в чатах -->
            <div
                class="ui-settings-section ui-settings-section--narrow"
            >
                <h2 class="text-sm font-semibold mb-1" :style="{ color: 'var(--ui-text)' }">{{ t('settings.system.sectionSla') }}</h2>
                <p class="text-xs mb-4" :style="{ color: 'var(--ui-text-secondary)' }">
                    {{ t('settings.system.slaHint') }}
                </p>
                <div class="space-y-4">
                    <div class="module-row" :class="{ 'module-row-on': slaRemindersEnabled() }">
                        <div class="module-info">
                            <span class="module-label">{{ t('settings.system.slaReminders') }}</span>
                            <span class="module-desc">{{ t('settings.system.slaRemindersDesc') }}</span>
                        </div>
                        <button
                            type="button"
                            class="toggle-btn"
                            :class="{ 'toggle-btn-on': slaRemindersEnabled() }"
                            @click="toggleSlaReminders"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-[var(--ui-text-secondary)] mb-1">{{ t('settings.system.slaMinutes') }}</label>
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
                            {{ t('settings.system.slaMinutesHint') }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Разделы приложения -->
            <div
                class="ui-settings-section ui-settings-section--narrow"
            >
                <h2 class="text-sm font-semibold mb-1" :style="{ color: 'var(--ui-text)' }">{{ t('settings.system.sectionModules') }}</h2>
                <p class="text-xs mb-4" :style="{ color: 'var(--ui-text-secondary)' }">
                    {{ t('settings.system.modulesHint') }}
                </p>

                <div class="space-y-3">
                    <div
                        v-for="mod in modules"
                        :key="mod.key"
                        class="module-row"
                        :class="{ 'module-row-on': moduleForm[mod.key] }"
                    >
                        <div class="module-info">
                            <span class="module-label">{{ mod.label }}</span>
                            <span class="module-desc">{{ mod.description }}</span>
                        </div>
                        <button
                            type="button"
                            class="toggle-btn"
                            :class="{ 'toggle-btn-on': moduleForm[mod.key] }"
                            :aria-pressed="moduleForm[mod.key]"
                            :title="moduleForm[mod.key] ? t('settings.system.modulesToggleOff') : t('settings.system.modulesToggleOn')"
                            @click="toggleModule(mod.key)"
                        >
                            <span class="toggle-thumb"></span>
                        </button>
                    </div>
                </div>

                <div class="mt-4 flex items-center gap-3">
                    <button
                        type="button"
                        class="ui-btn ui-btn--secondary"
                        :disabled="isSavingModules"
                        @click="saveModules"
                    >
                        {{ isSavingModules ? t('settings.system.saving') : t('settings.system.modulesSave') }}
                    </button>
                    <span v-if="modulesSaved" class="text-sm" :style="{ color: 'var(--ui-accent)' }">{{ t('settings.system.saved') }}</span>
                </div>
            </div>

            <!-- Записи и напоминания -->
            <div
                class="ui-settings-section ui-settings-section--narrow"
            >
                <h2 class="text-sm font-semibold mb-1" :style="{ color: 'var(--ui-text)' }">{{ t('settings.system.sectionAppointments') }}</h2>
                <p class="text-xs mb-4" :style="{ color: 'var(--ui-text-secondary)' }">
                    {{ t('settings.system.appointmentsHint') }}
                </p>

                <div class="space-y-4">
                    <div
                        class="module-row"
                        :class="{ 'module-row-on': remindersEnabled() }"
                    >
                        <div class="module-info">
                            <span class="module-label">{{ t('settings.system.appointmentReminder') }}</span>
                            <span class="module-desc">{{ t('settings.system.appointmentReminderDesc') }}</span>
                        </div>
                        <button
                            type="button"
                            class="toggle-btn"
                            :class="{ 'toggle-btn-on': remindersEnabled() }"
                            @click="toggleReminders"
                            :title="remindersEnabled() ? t('settings.system.remindersToggleOff') : t('settings.system.remindersToggleOn')"
                        >
                            <span class="toggle-thumb"></span>
                        </button>
                    </div>

                    <div>
                        <label class="block text-sm text-[var(--ui-text-secondary)] mb-1">{{ t('settings.system.leadTimeLabel') }}</label>
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
                                :aria-label="t('settings.system.leadTimeCustomAria')"
                            />
                        </div>
                        <p class="mt-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                            {{ t('settings.system.leadTimeHint') }}
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
                    {{ isSaving ? t('settings.system.saving') : t('common.save') }}
                </button>
                <span v-if="saved" class="text-sm" :style="{ color: 'var(--ui-accent)' }">{{ t('settings.system.saved') }}</span>
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

.module-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.875rem 1rem;
    border-radius: 0.75rem;
    border: 1px solid var(--ui-border);
    background: var(--ui-surface);
}
.module-row-on {
    border-color: var(--ui-accent-border);
    background: color-mix(in srgb, var(--ui-accent) 8%, var(--ui-surface));
}
.module-info {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
}
.module-label {
    font-size: 0.9375rem;
    font-weight: 500;
    color: var(--ui-text);
}
.module-desc {
    font-size: 0.75rem;
    color: var(--ui-text-secondary);
}
</style>
