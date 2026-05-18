<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import type { WhatsappSession } from '@/types';

type SessionStatus = WhatsappSession['status'];

type QrResponse = {
    qr?: string;
    qrCode?: string;
    dataUrl?: string;
    success?: boolean;
    error?: string;
    message?: string;
};

type StatusResponse = {
    session?: WhatsappSession;
    success?: boolean;
    isReady?: boolean;
    hasQR?: boolean;
    isInitializing?: boolean;
    error?: string;
    message?: string;
};

const props = defineProps<{
    sessions: WhatsappSession[];
    whatsappServiceReachable: boolean;
}>();

const localSessions = ref<WhatsappSession[]>([...props.sessions]);
const isCreating = ref(false);
const busySessionId = ref<number | null>(null);
const editingSessionId = ref<number | null>(null);
const editedDisplayName = ref('');
const editedDisplayColor = ref('');
const qrBySessionId = ref<Record<number, string>>({});
const qrUpdatedAtBySessionId = ref<Record<number, string>>({});
const message = ref<string | null>(null);
let qrRefreshTimer: ReturnType<typeof setInterval> | null = null;

const hasSessions = computed(() => localSessions.value.length > 0);

watch(
    () => props.sessions,
    (sessions) => {
        localSessions.value = [...sessions];
    },
);

onMounted(() => {
    refreshVisibleQrCodes();
    qrRefreshTimer = setInterval(refreshVisibleQrCodes, 15000);
});

onBeforeUnmount(() => {
    if (qrRefreshTimer !== null) {
        clearInterval(qrRefreshTimer);
    }
});

const statusLabels: Record<SessionStatus, string> = {
    connected: 'Подключено',
    connecting: 'Подключается',
    qr_pending: 'Ожидает QR',
    disconnected: 'Отключено',
};

const statusClasses: Record<SessionStatus, string> = {
    connected: 'bg-[var(--wa-accent)]',
    connecting: 'bg-amber-500',
    qr_pending: 'bg-amber-500',
    disconnected: 'bg-red-500',
};

function sessionLabel(session: WhatsappSession): string {
    return session.display_name?.trim() || session.session_name;
}

const dayInMs = 24 * 60 * 60 * 1000;
const fullDateFormatter = new Intl.DateTimeFormat('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});
const timeOnlyFormatter = new Intl.DateTimeFormat('ru-RU', {
    hour: '2-digit',
    minute: '2-digit',
});
const dateThisYearFormatter = new Intl.DateTimeFormat('ru-RU', {
    day: 'numeric',
    month: 'long',
    hour: '2-digit',
    minute: '2-digit',
});

/**
 * Человеко-читаемый формат для меток подключения:
 * «сегодня в 14:19», «вчера в 14:19», «14 мая, 14:19», «14.05.2025, 14:19».
 */
function formatDateTime(value: string | null | undefined): string {
    if (!value) return '—';

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';

    const now = new Date();
    const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
    const startOfTarget = new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
    const diffDays = Math.round((startOfToday - startOfTarget) / dayInMs);

    if (diffDays === 0) {
        return `сегодня в ${timeOnlyFormatter.format(date)}`;
    }
    if (diffDays === 1) {
        return `вчера в ${timeOnlyFormatter.format(date)}`;
    }
    if (date.getFullYear() === now.getFullYear()) {
        return dateThisYearFormatter.format(date);
    }

    return fullDateFormatter.format(date);
}

function fullDateTimeTitle(value: string | null | undefined): string {
    if (!value) return '';

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';

    return fullDateFormatter.format(date);
}

function errorMessage(err: unknown, fallback = 'Ошибка выполнения действия'): string {
    const e = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } }; message?: string };
    const errors = e.response?.data?.errors;
    if (errors) {
        return Object.values(errors).flat().join('\n');
    }
    return e.response?.data?.message || e.message || fallback;
}

function updateSession(session: WhatsappSession): void {
    const index = localSessions.value.findIndex((item) => item.id === session.id);
    if (index === -1) {
        localSessions.value.push(session);
        return;
    }
    localSessions.value[index] = session;
}

function shouldRefreshQr(session: WhatsappSession): boolean {
    return props.whatsappServiceReachable && session.status !== 'connected';
}

function refreshVisibleQrCodes(): void {
    localSessions.value
        .filter(shouldRefreshQr)
        .forEach((session) => {
            void loadQr(session, { silent: true });
        });
}

function normalizeQr(payload: QrResponse): string | null {
    const value = payload.qr || payload.qrCode || payload.dataUrl;
    if (!value) {
        return null;
    }

    return value.startsWith('data:image') ? value : `data:image/png;base64,${value}`;
}

async function createSession(): Promise<void> {
    if (!props.whatsappServiceReachable) {
        message.value = 'Сервис WhatsApp недоступен. Проверьте запуск whatsapp-service.';
        return;
    }

    isCreating.value = true;
    message.value = null;

    try {
        const { data } = await axios.post<{ session: WhatsappSession }>(route('settings.connections.store'));
        updateSession(data.session);
        await loadQr(data.session, { silent: true });
    } catch (err: unknown) {
        message.value = errorMessage(err, 'Не удалось создать подключение');
    } finally {
        isCreating.value = false;
    }
}

async function initialize(session: WhatsappSession): Promise<void> {
    busySessionId.value = session.id;
    message.value = null;

    try {
        await axios.post(route('settings.connections.initialize', session.id));
        updateSession({ ...session, status: 'connecting' });
        setTimeout(() => void loadQr(session, { silent: true }), 1500);
    } catch (err: unknown) {
        message.value = errorMessage(err, 'Не удалось запустить подключение');
    } finally {
        busySessionId.value = null;
    }
}

async function loadQr(session: WhatsappSession, options: { silent?: boolean } = {}): Promise<void> {
    const silent = options.silent === true;

    if (session.status === 'connected') {
        delete qrBySessionId.value[session.id];
        delete qrUpdatedAtBySessionId.value[session.id];
        return;
    }

    if (!silent) {
        busySessionId.value = session.id;
        message.value = null;
    }

    try {
        const { data } = await axios.get<QrResponse>(route('settings.connections.qr', session.id));
        const qr = normalizeQr(data);

        if (qr) {
            qrBySessionId.value = { ...qrBySessionId.value, [session.id]: qr };
            qrUpdatedAtBySessionId.value = {
                ...qrUpdatedAtBySessionId.value,
                [session.id]: timeOnlyFormatter.format(new Date()),
            };
            return;
        }

        if (!silent) {
            message.value = data.message || data.error || 'QR-код ещё не готов. Повторите через несколько секунд.';
        }
    } catch (err: unknown) {
        if (!silent) {
            message.value = errorMessage(err, 'Не удалось получить QR-код');
        }
    } finally {
        if (!silent) {
            busySessionId.value = null;
        }
    }
}

async function refreshStatus(session: WhatsappSession): Promise<void> {
    busySessionId.value = session.id;
    message.value = null;

    try {
        const { data } = await axios.get<StatusResponse>(route('settings.connections.status', session.id));
        if (data.session) {
            updateSession(data.session);
            if (data.session.status === 'connected') {
                delete qrBySessionId.value[session.id];
                delete qrUpdatedAtBySessionId.value[session.id];
            }
        }
        if (data.hasQR) {
            await loadQr(session, { silent: true });
        }
    } catch (err: unknown) {
        message.value = errorMessage(err, 'Не удалось обновить статус');
    } finally {
        busySessionId.value = null;
    }
}

async function verify(session: WhatsappSession): Promise<void> {
    busySessionId.value = session.id;
    message.value = null;

    try {
        const { data } = await axios.post<StatusResponse>(route('settings.connections.verify', session.id));
        if (data.session) {
            updateSession(data.session);
            if (data.session.status === 'connected') {
                delete qrBySessionId.value[session.id];
                delete qrUpdatedAtBySessionId.value[session.id];
            }
        }
    } catch (err: unknown) {
        message.value = errorMessage(err, 'Не удалось проверить подключение');
    } finally {
        busySessionId.value = null;
    }
}

function startEdit(session: WhatsappSession): void {
    editingSessionId.value = session.id;
    editedDisplayName.value = session.display_name || '';
    editedDisplayColor.value = session.display_color || '';
}

async function saveDisplayName(session: WhatsappSession): Promise<void> {
    const displayName = editedDisplayName.value.trim();
    if (!displayName) {
        message.value = 'Укажите название подключения.';
        return;
    }

    busySessionId.value = session.id;
    message.value = null;

    try {
        const { data } = await axios.patch<{ session: WhatsappSession }>(
            route('settings.connections.update', session.id),
            { display_name: displayName, display_color: editedDisplayColor.value || null },
        );
        updateSession(data.session);
        editingSessionId.value = null;
    } catch (err: unknown) {
        message.value = errorMessage(err, 'Не удалось сохранить название');
    } finally {
        busySessionId.value = null;
    }
}

async function logout(session: WhatsappSession): Promise<void> {
    if (!confirm(`Отключить "${sessionLabel(session)}"?`)) {
        return;
    }

    busySessionId.value = session.id;
    message.value = null;

    try {
        await axios.post(route('settings.connections.logout', session.id));
        updateSession({ ...session, status: 'disconnected', disconnected_at: new Date().toISOString() });
        delete qrBySessionId.value[session.id];
    } catch (err: unknown) {
        message.value = errorMessage(err, 'Не удалось отключить подключение');
    } finally {
        busySessionId.value = null;
    }
}

async function remove(session: WhatsappSession): Promise<void> {
    if (!confirm(`Удалить подключение "${sessionLabel(session)}"? Связанные чаты останутся без этого номера.`)) {
        return;
    }

    busySessionId.value = session.id;
    message.value = null;

    try {
        await axios.delete(route('settings.connections.destroy', session.id));
        localSessions.value = localSessions.value.filter((item) => item.id !== session.id);
        delete qrBySessionId.value[session.id];
    } catch (err: unknown) {
        message.value = errorMessage(err, 'Не удалось удалить подключение');
    } finally {
        busySessionId.value = null;
    }
}

function reloadPage(): void {
    router.reload({ only: ['sessions', 'whatsappServiceReachable'] });
}
</script>

<template>
    <Head title="Подключения WhatsApp" />

    <SettingsLayout title="Подключения WhatsApp" subtitle="Номера, QR-коды и состояние whatsapp-service">
        <template #actions>
            <button
                type="button"
                class="px-4 py-2 text-sm rounded-lg transition hover:brightness-95 disabled:opacity-50"
                :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                :disabled="isCreating || !whatsappServiceReachable"
                @click="createSession"
            >
                {{ isCreating ? 'Создание...' : '+ Добавить подключение' }}
            </button>
        </template>

        <div class="w-full px-6 py-6 space-y-4">
            <div
                v-if="!whatsappServiceReachable"
                class="rounded-lg border px-4 py-3 text-sm text-amber-800 bg-amber-50 border-amber-200"
            >
                Сервис WhatsApp недоступен. Проверьте `WHATSAPP_SERVICE_URL`, `WHATSAPP_SERVICE_TOKEN` и процесс whatsapp-service.
            </div>

            <div
                v-if="message"
                class="rounded-lg border px-4 py-3 text-sm text-red-700 bg-red-50 border-red-200 whitespace-pre-line"
            >
                {{ message }}
            </div>

            <div
                v-if="!hasSessions"
                class="rounded-lg border p-10 text-center"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <h3 class="text-[var(--wa-text)] font-medium">Подключений пока нет</h3>
                <p class="text-sm text-[var(--wa-text-secondary)] mt-1">
                    Добавьте WhatsApp-подключение и отсканируйте QR-код с телефона.
                </p>
                <button
                    type="button"
                    class="mt-5 px-5 py-2 text-sm rounded-lg transition hover:brightness-95 disabled:opacity-50"
                    :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                    :disabled="isCreating || !whatsappServiceReachable"
                    @click="createSession"
                >
                    Создать первое подключение
                </button>
            </div>

            <div v-else class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                <div
                    v-for="session in localSessions"
                    :key="session.id"
                    class="rounded-lg border p-5 space-y-4"
                    :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <div v-if="editingSessionId === session.id" class="flex gap-2 items-center">
                                <input
                                    v-model="editedDisplayName"
                                    type="text"
                                    class="settings-input"
                                    maxlength="100"
                                    @keyup.enter="saveDisplayName(session)"
                                />
                                <input
                                    :value="editedDisplayColor || '#01b964'"
                                    type="color"
                                    class="h-[38px] w-[42px] rounded-md border"
                                    :style="{ borderColor: 'var(--wa-border)', background: 'transparent' }"
                                    title="Цвет бейджа"
                                    @input="editedDisplayColor = ($event.target as HTMLInputElement).value"
                                    @change="saveDisplayName(session)"
                                />
                                <button
                                    type="button"
                                    class="settings-button text-white"
                                    :style="{ background: 'var(--wa-accent)' }"
                                    :disabled="busySessionId === session.id"
                                    @click="saveDisplayName(session)"
                                >
                                    OK
                                </button>
                            </div>
                            <div v-else>
                                <div class="flex items-center gap-2 min-w-0">
                                    <div class="text-left font-medium text-[var(--wa-text)] truncate max-w-full">
                                        {{ sessionLabel(session) }}
                                    </div>
                                    <button
                                        type="button"
                                        class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)] shrink-0"
                                        title="Редактировать (название и цвет)"
                                        @click="startEdit(session)"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                </div>
                                <p class="text-xs text-[var(--wa-text-secondary)] truncate">
                                    {{ session.session_name }}
                                </p>
                            </div>
                        </div>

                        <span class="inline-flex items-center gap-2 text-xs text-[var(--wa-text-secondary)] whitespace-nowrap">
                            <span class="w-2 h-2 rounded-full" :class="statusClasses[session.status]"></span>
                            {{ statusLabels[session.status] }}
                        </span>
                    </div>

                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-xs text-[var(--wa-text-secondary)]">Номер WhatsApp</dt>
                            <dd class="text-[var(--wa-text)]">{{ session.phone_number || '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-[var(--wa-text-secondary)]">Имя WhatsApp</dt>
                            <dd class="text-[var(--wa-text)]">{{ session.wa_name || '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-[var(--wa-text-secondary)]">Платформа</dt>
                            <dd class="text-[var(--wa-text)]">{{ session.wa_platform || '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-[var(--wa-text-secondary)]">Подключено</dt>
                            <dd
                                class="text-[var(--wa-text)]"
                                :title="fullDateTimeTitle(session.connected_at)"
                            >
                                {{ formatDateTime(session.connected_at) }}
                            </dd>
                        </div>
                        <div v-if="session.disconnected_at && session.status !== 'connected'">
                            <dt class="text-xs text-[var(--wa-text-secondary)]">Отключено</dt>
                            <dd
                                class="text-[var(--wa-text)]"
                                :title="fullDateTimeTitle(session.disconnected_at)"
                            >
                                {{ formatDateTime(session.disconnected_at) }}
                            </dd>
                        </div>
                    </dl>

                    <div
                        v-if="qrBySessionId[session.id]"
                        class="rounded-lg border p-4 flex flex-col sm:flex-row gap-4 items-center"
                        :style="{ background: 'var(--wa-bg)', borderColor: 'var(--wa-border-strong)' }"
                    >
                        <img
                            :key="qrUpdatedAtBySessionId[session.id]"
                            :src="qrBySessionId[session.id]"
                            alt="WhatsApp QR"
                            class="w-44 h-44 rounded bg-white p-2"
                        />
                        <div class="text-sm text-[var(--wa-text-secondary)] space-y-2">
                            <p>Откройте WhatsApp на телефоне, выберите «Связанные устройства» и отсканируйте QR-код.</p>
                            <p v-if="qrUpdatedAtBySessionId[session.id]" class="text-xs">
                                QR обновлён: {{ qrUpdatedAtBySessionId[session.id] }}. Если телефон не принимает код,
                                подождите 10–15 секунд или нажмите «Обновить QR».
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="settings-button"
                            :disabled="busySessionId === session.id || !whatsappServiceReachable"
                            @click="initialize(session)"
                        >
                            Подключить
                        </button>
                        <button
                            type="button"
                            class="settings-button"
                            :disabled="busySessionId === session.id || !whatsappServiceReachable"
                            @click="loadQr(session)"
                        >
                            Обновить QR
                        </button>
                        <button
                            type="button"
                            class="settings-button"
                            :disabled="busySessionId === session.id || !whatsappServiceReachable"
                            @click="refreshStatus(session)"
                        >
                            Статус
                        </button>
                        <button
                            type="button"
                            class="settings-button"
                            :disabled="busySessionId === session.id || !whatsappServiceReachable"
                            @click="verify(session)"
                        >
                            Проверить
                        </button>
                        <button
                            type="button"
                            class="settings-button text-red-600"
                            :disabled="busySessionId === session.id"
                            @click="logout(session)"
                        >
                            Выйти
                        </button>
                        <button
                            type="button"
                            class="settings-button text-red-600"
                            :disabled="busySessionId === session.id"
                            @click="remove(session)"
                        >
                            Удалить
                        </button>
                    </div>
                </div>
            </div>

            <button
                type="button"
                class="text-xs text-[var(--wa-text-secondary)] hover:underline"
                @click="reloadPage"
            >
                Обновить данные страницы
            </button>
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
.settings-button {
    padding: 0.5rem 0.75rem;
    border-radius: 0.5rem;
    font-size: 0.75rem;
    color: var(--wa-text);
    background: var(--wa-bg);
    border: 1px solid var(--wa-border-strong);
    transition: background-color 0.15s ease, opacity 0.15s ease;
}
.settings-button:hover {
    background: var(--wa-panel-hover);
}
.settings-button:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}
</style>
