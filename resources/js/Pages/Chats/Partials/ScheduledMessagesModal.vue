<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import axios from 'axios';
import type { ScheduledMessage } from '@/types';

const props = defineProps<{
    open: boolean;
    chatId: number;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const items = ref<ScheduledMessage[]>([]);
const loading = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);
const editingId = ref<number | null>(null);
const body = ref('');
const scheduledAt = ref('');
const textareaRef = ref<HTMLTextAreaElement | null>(null);

const pendingItems = computed(() => items.value.filter((m) => m.status === 'pending' || m.status === 'failed'));
const sentItems = computed(() => items.value.filter((m) => m.status === 'sent'));

function almatyDateTimeInput(date = new Date()): string {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Almaty',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).formatToParts(date);
    const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '';
    return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
}

function minScheduleValue(): string {
    return almatyDateTimeInput(new Date(Date.now() + 60_000));
}

function resetForm(): void {
    editingId.value = null;
    body.value = '';
    scheduledAt.value = minScheduleValue();
    error.value = null;
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await axios.get(route('chats.scheduled-messages.index', props.chatId));
        items.value = Array.isArray(data?.scheduled_messages) ? data.scheduled_messages : [];
    } catch (e: any) {
        error.value = e?.response?.data?.message || 'Не удалось загрузить отложенные сообщения.';
    } finally {
        loading.value = false;
    }
}

async function submit(): Promise<void> {
    const text = body.value.trim();
    if (!text || !scheduledAt.value || saving.value) return;
    saving.value = true;
    error.value = null;
    try {
        const payload = {
            body: text,
            display_body: text,
            scheduled_at: scheduledAt.value,
            timezone: 'Asia/Almaty',
        };
        if (editingId.value) {
            await axios.put(route('chats.scheduled-messages.update', [props.chatId, editingId.value]), payload);
        } else {
            await axios.post(route('chats.scheduled-messages.store', props.chatId), payload);
        }
        resetForm();
        await load();
    } catch (e: any) {
        error.value = e?.response?.data?.message || 'Не удалось сохранить отложенное сообщение.';
    } finally {
        saving.value = false;
    }
}

function edit(item: ScheduledMessage): void {
    if (item.status !== 'pending' && item.status !== 'failed') return;
    editingId.value = item.id;
    body.value = item.body || '';
    scheduledAt.value = item.scheduled_at || minScheduleValue();
    nextTick(() => textareaRef.value?.focus());
}

async function cancel(item: ScheduledMessage): Promise<void> {
    if (item.status !== 'pending' && item.status !== 'failed') return;
    if (!confirm('Отменить это отложенное сообщение?')) return;
    error.value = null;
    try {
        await axios.delete(route('chats.scheduled-messages.destroy', [props.chatId, item.id]));
        if (editingId.value === item.id) resetForm();
        await load();
    } catch (e: any) {
        error.value = e?.response?.data?.message || 'Не удалось отменить сообщение.';
    }
}

function close(): void {
    emit('close');
}

function statusLabel(status: ScheduledMessage['status']): string {
    if (status === 'pending') return 'Ожидает';
    if (status === 'sending') return 'Отправляется';
    if (status === 'sent') return 'Отправлено';
    if (status === 'failed') return 'Ошибка';
    return 'Отменено';
}

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        resetForm();
        load();
        nextTick(() => textareaRef.value?.focus());
    },
    { immediate: true },
);
</script>

<template>
    <Teleport to="body">
        <transition name="sched-fade">
            <div
                v-if="open"
                class="sched-overlay"
                @click.self="close"
            >
                <div class="sched-sheet" role="dialog" aria-modal="true">
                    <div class="sched-header">
                        <button class="sched-icon-btn" type="button" title="Закрыть" @click="close">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                        <div>
                            <h3 class="sched-title">Отложенные сообщения</h3>
                            <p class="sched-subtitle">Время отправки: UTC+5 / Алматы</p>
                        </div>
                    </div>

                    <div class="sched-body wa-scrollbar">
                        <form class="sched-form" @submit.prevent="submit">
                            <textarea
                                ref="textareaRef"
                                v-model="body"
                                class="sched-textarea wa-scrollbar"
                                rows="4"
                                maxlength="4096"
                                placeholder="Напишите сообщение..."
                                :disabled="saving"
                            ></textarea>
                            <div class="sched-form-row">
                                <label class="sched-date-label">
                                    <span>Когда отправить</span>
                                    <input
                                        v-model="scheduledAt"
                                        class="sched-date-input"
                                        type="datetime-local"
                                        :min="minScheduleValue()"
                                        :disabled="saving"
                                    />
                                </label>
                                <button
                                    class="sched-save-btn"
                                    type="submit"
                                    :disabled="saving || !body.trim() || !scheduledAt"
                                >
                                    {{ editingId ? 'Сохранить' : 'Запланировать' }}
                                </button>
                                <button
                                    v-if="editingId"
                                    class="sched-cancel-edit-btn"
                                    type="button"
                                    :disabled="saving"
                                    @click="resetForm"
                                >
                                    Сбросить
                                </button>
                            </div>
                        </form>

                        <div v-if="error" class="sched-error">{{ error }}</div>

                        <div class="sched-section">
                            <div class="sched-section-title">
                                Ожидают отправки
                                <span v-if="loading">Загрузка...</span>
                            </div>
                            <div v-if="!loading && pendingItems.length === 0" class="sched-empty">
                                Нет отложенных сообщений.
                            </div>
                            <div v-for="item in pendingItems" :key="item.id" class="sched-card">
                                <div class="sched-card-top">
                                    <span class="sched-status" :class="{ 'sched-status-error': item.status === 'failed' }">
                                        {{ statusLabel(item.status) }}
                                    </span>
                                    <span class="sched-time">{{ item.scheduled_at_label }}</span>
                                </div>
                                <div class="sched-card-text">{{ item.body }}</div>
                                <div v-if="item.error" class="sched-card-error">{{ item.error }}</div>
                                <div class="sched-card-actions">
                                    <button type="button" @click="edit(item)">Редактировать</button>
                                    <button type="button" class="sched-danger" @click="cancel(item)">Отменить</button>
                                </div>
                            </div>
                        </div>

                        <div v-if="sentItems.length > 0" class="sched-section">
                            <div class="sched-section-title">Недавно отправленные</div>
                            <div v-for="item in sentItems.slice(0, 10)" :key="item.id" class="sched-card sched-card-sent">
                                <div class="sched-card-top">
                                    <span class="sched-status">{{ statusLabel(item.status) }}</span>
                                    <span class="sched-time">{{ item.scheduled_at_label }}</span>
                                </div>
                                <div class="sched-card-text">{{ item.body }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </transition>
    </Teleport>
</template>

<style scoped>
.sched-overlay {
    position: fixed;
    inset: 0;
    z-index: 1200;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(0, 0, 0, 0.62);
}
.sched-sheet {
    width: min(560px, 100%);
    max-height: min(760px, 100%);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border-radius: 14px;
    background: var(--wa-panel-header);
    color: var(--wa-text);
    border: 1px solid var(--wa-border-strong);
    box-shadow: 0 24px 70px rgba(0, 0, 0, 0.45);
}
.sched-header {
    min-height: 64px;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    border-bottom: 1px solid var(--wa-border);
}
.sched-icon-btn {
    width: 38px;
    height: 38px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    color: var(--wa-icon);
}
.sched-icon-btn:hover { background: var(--wa-panel-hover); }
.sched-title {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
}
.sched-subtitle {
    margin: 2px 0 0;
    font-size: 12px;
    color: var(--wa-text-secondary);
}
.sched-body {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 14px;
}
.sched-form {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 12px;
    border-radius: 12px;
    background: var(--wa-panel);
    border: 1px solid var(--wa-border);
}
.sched-textarea {
    width: 100%;
    resize: vertical;
    min-height: 96px;
    max-height: 220px;
    border: 0;
    border-radius: 10px;
    padding: 10px 12px;
    background: var(--wa-panel-input);
    color: var(--wa-text);
    font-size: 14px;
    line-height: 20px;
}
.sched-textarea:focus,
.sched-date-input:focus {
    outline: none;
    box-shadow: 0 0 0 1px var(--wa-accent) inset;
}
.sched-form-row {
    display: flex;
    align-items: end;
    gap: 8px;
    flex-wrap: wrap;
}
.sched-date-label {
    flex: 1;
    min-width: 220px;
    display: flex;
    flex-direction: column;
    gap: 5px;
    color: var(--wa-text-secondary);
    font-size: 12px;
}
.sched-date-input {
    height: 40px;
    border: 0;
    border-radius: 10px;
    padding: 0 10px;
    background: var(--wa-panel-input);
    color: var(--wa-text);
}
.sched-save-btn,
.sched-cancel-edit-btn {
    height: 40px;
    border-radius: 9999px;
    padding: 0 16px;
    font-size: 14px;
    font-weight: 600;
}
.sched-save-btn {
    color: var(--wa-accent-on);
    background: var(--wa-accent);
}
.sched-save-btn:disabled,
.sched-cancel-edit-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.sched-cancel-edit-btn {
    color: var(--wa-text);
    background: var(--wa-panel-hover);
}
.sched-error,
.sched-card-error {
    margin-top: 10px;
    padding: 9px 10px;
    border-radius: 10px;
    color: #ffd7dc;
    background: rgba(239, 68, 68, 0.16);
    font-size: 13px;
}
.sched-section {
    margin-top: 16px;
}
.sched-section-title {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 8px;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: var(--wa-text-secondary);
}
.sched-empty {
    padding: 18px;
    text-align: center;
    color: var(--wa-text-secondary);
    font-size: 14px;
    border: 1px dashed var(--wa-border-strong);
    border-radius: 12px;
}
.sched-card {
    padding: 10px 12px;
    border-radius: 12px;
    background: var(--wa-panel);
    border: 1px solid var(--wa-border);
}
.sched-card + .sched-card { margin-top: 8px; }
.sched-card-sent { opacity: 0.72; }
.sched-card-top {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 6px;
    font-size: 12px;
    color: var(--wa-text-secondary);
}
.sched-status {
    color: var(--wa-accent);
    font-weight: 600;
}
.sched-status-error { color: #fca5a5; }
.sched-time {
    font-variant-numeric: tabular-nums;
}
.sched-card-text {
    white-space: pre-wrap;
    word-break: break-word;
    font-size: 14px;
    line-height: 20px;
}
.sched-card-actions {
    display: flex;
    gap: 8px;
    margin-top: 8px;
}
.sched-card-actions button {
    padding: 6px 10px;
    border-radius: 9999px;
    font-size: 12px;
    color: var(--wa-accent);
    background: color-mix(in srgb, var(--wa-accent) 10%, transparent);
}
.sched-card-actions .sched-danger {
    color: #fca5a5;
    background: rgba(239, 68, 68, 0.12);
}
.sched-fade-enter-active,
.sched-fade-leave-active { transition: opacity 0.16s ease; }
.sched-fade-enter-from,
.sched-fade-leave-to { opacity: 0; }
</style>

