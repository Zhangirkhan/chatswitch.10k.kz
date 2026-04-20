<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import type { WhatsappSession } from '@/types';

const props = defineProps<{
    sessions: WhatsappSession[];
}>();

const localSessions = ref<WhatsappSession[]>([...props.sessions]);
const showAddForm = ref(false);
const newSessionName = ref('');
const newDisplayName = ref('');
const qrImages = ref<Record<number, string | null>>({});
const pollingIntervals = ref<Record<number, ReturnType<typeof setInterval>>>({});

async function addSession() {
    if (!newSessionName.value.trim()) return;
    try {
        const { data } = await axios.post(route('settings.connections.store'), {
            session_name: newSessionName.value.trim(),
            display_name: newDisplayName.value.trim() || null,
        });
        if (data.session) {
            localSessions.value.push(data.session);
            newSessionName.value = '';
            newDisplayName.value = '';
            showAddForm.value = false;
        }
    } catch (err: any) {
        alert(err.response?.data?.message || 'Ошибка создания сессии');
    }
}

async function initializeSession(session: WhatsappSession) {
    session.status = 'connecting';
    await axios.post(route('settings.connections.initialize', session.id));
    startPolling(session);
}

async function fetchQR(session: WhatsappSession) {
    try {
        const { data } = await axios.get(route('settings.connections.qr', session.id));
        if (data.qr) {
            qrImages.value[session.id] = data.qr;
        }
    } catch (_) {}
}

async function checkStatus(session: WhatsappSession) {
    try {
        const { data } = await axios.get(route('settings.connections.status', session.id));
        if (data.session) {
            const idx = localSessions.value.findIndex((s) => s.id === session.id);
            if (idx !== -1) localSessions.value[idx] = data.session;
        }
        if (data.isReady) {
            stopPolling(session.id);
            qrImages.value[session.id] = null;
        } else if (data.hasQR) {
            await fetchQR(session);
        }
    } catch (_) {}
}

function startPolling(session: WhatsappSession) {
    stopPolling(session.id);
    pollingIntervals.value[session.id] = setInterval(() => checkStatus(session), 3000);
    setTimeout(() => fetchQR(session), 1500);
}

function stopPolling(id: number) {
    if (pollingIntervals.value[id]) {
        clearInterval(pollingIntervals.value[id]);
        delete pollingIntervals.value[id];
    }
}

async function logoutSession(session: WhatsappSession) {
    if (!confirm('Отключить этот WhatsApp номер?')) return;
    await axios.post(route('settings.connections.logout', session.id));
    session.status = 'disconnected';
    qrImages.value[session.id] = null;
    stopPolling(session.id);
}

async function deleteSession(session: WhatsappSession) {
    if (!confirm('Удалить эту сессию? Все данные будут потеряны.')) return;
    await axios.delete(route('settings.connections.destroy', session.id));
    localSessions.value = localSessions.value.filter((s) => s.id !== session.id);
    stopPolling(session.id);
}

function statusLabel(status: string): string {
    const map: Record<string, string> = {
        connected: 'Подключён',
        connecting: 'Подключение...',
        qr_pending: 'Ожидание сканирования QR',
        disconnected: 'Отключён',
    };
    return map[status] || status;
}

function statusColor(status: string): string {
    const map: Record<string, string> = {
        connected: 'bg-[#25d366]',
        connecting: 'bg-yellow-400',
        qr_pending: 'bg-yellow-400',
        disconnected: 'bg-red-400',
    };
    return map[status] || 'bg-gray-400';
}

onMounted(() => {
    if ((window as any).Echo) {
        (window as any).Echo.channel('whatsapp-status').listen('.status.changed', (e: any) => {
            const session = localSessions.value.find((s) => s.session_name === e.session);
            if (session) {
                session.status = e.status;
                if (e.phone_number) session.phone_number = e.phone_number;
                if (e.status === 'connected') {
                    qrImages.value[session.id] = null;
                    stopPolling(session.id);
                }
            }
        });
    }
});

onUnmounted(() => {
    Object.keys(pollingIntervals.value).forEach((k) => clearInterval(pollingIntervals.value[Number(k)]));
    if ((window as any).Echo) {
        (window as any).Echo.leave('whatsapp-status');
    }
});
</script>

<template>
    <Head title="Подключения WhatsApp" />
    <SettingsLayout title="Подключения WhatsApp" subtitle="Номера, QR-коды и статус сессий">
        <template #actions>
            <button
                @click="showAddForm = !showAddForm"
                class="px-4 py-2 text-sm rounded-lg transition hover:brightness-95"
                :style="{ background: 'var(--wa-accent)', color: '#fff' }"
            >
                + Добавить номер
            </button>
        </template>

        <div class="w-full px-6 py-6">
            <!-- Add form -->
            <div
                v-if="showAddForm"
                class="rounded-lg border p-5 mb-4"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Название сессии</label>
                        <input
                            v-model="newSessionName"
                            type="text"
                            placeholder="my-whatsapp-1"
                            class="settings-input"
                        />
                    </div>
                    <div>
                        <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Отображаемое имя</label>
                        <input
                            v-model="newDisplayName"
                            type="text"
                            placeholder="Основной номер"
                            class="settings-input"
                        />
                    </div>
                </div>
                <div class="flex gap-2 mt-3">
                    <button
                        @click="addSession"
                        class="px-4 py-2 text-sm rounded-lg transition hover:brightness-95"
                        :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                    >
                        Создать
                    </button>
                    <button
                        @click="showAddForm = false"
                        class="px-4 py-2 text-sm rounded-lg text-[var(--wa-text-secondary)] hover:bg-[var(--wa-panel-hover)]"
                    >
                        Отмена
                    </button>
                </div>
            </div>

            <!-- Sessions list -->
            <div class="space-y-3">
                <div
                    v-if="localSessions.length === 0"
                    class="rounded-lg border p-10 text-center"
                    :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
                >
                    <p class="text-[var(--wa-text-secondary)]">
                        Нет подключённых номеров. Нажмите «Добавить номер», чтобы начать.
                    </p>
                </div>

                <div
                    v-for="session in localSessions"
                    :key="session.id"
                    class="rounded-lg border overflow-hidden"
                    :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
                >
                    <div class="p-5">
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex items-center gap-3 min-w-0">
                                <div
                                    class="w-11 h-11 rounded-full flex items-center justify-center shrink-0"
                                    :style="{ background: 'var(--wa-accent)' }"
                                >
                                    <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
                                        <path d="M12 2C6.477 2 2 6.477 2 12c0 1.89.525 3.66 1.438 5.168L2 22l4.832-1.438A9.955 9.955 0 0012 22c5.523 0 10-4.477 10-10S17.523 2 12 2z"/>
                                    </svg>
                                </div>
                                <div class="min-w-0">
                                    <h3 class="font-medium text-[var(--wa-text)] truncate">
                                        {{ session.display_name || session.session_name }}
                                    </h3>
                                    <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                        <span class="w-2 h-2 rounded-full" :class="statusColor(session.status)"></span>
                                        <span class="text-xs text-[var(--wa-text-secondary)]">{{ statusLabel(session.status) }}</span>
                                        <span
                                            v-if="session.phone_number"
                                            class="text-xs font-medium"
                                            :style="{ color: 'var(--wa-accent)' }"
                                        >
                                            {{ session.phone_number }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                <button
                                    v-if="session.status === 'disconnected'"
                                    @click="initializeSession(session)"
                                    class="px-3 py-1.5 text-xs rounded-lg transition hover:brightness-95"
                                    :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                                >
                                    Подключить
                                </button>
                                <button
                                    v-if="session.status === 'connected'"
                                    @click="logoutSession(session)"
                                    class="px-3 py-1.5 text-xs rounded-lg text-[var(--wa-text-secondary)] hover:bg-[var(--wa-panel-hover)] border"
                                    :style="{ borderColor: 'var(--wa-border-strong)' }"
                                >
                                    Отключить
                                </button>
                                <button
                                    @click="deleteSession(session)"
                                    class="p-1.5 text-red-400 hover:text-red-600 hover:bg-red-500/10 rounded transition"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- QR Code -->
                        <div v-if="qrImages[session.id]" class="mt-4 flex justify-center">
                            <div
                                class="p-4 rounded-lg border text-center"
                                :style="{ background: 'var(--wa-bg)', borderColor: 'var(--wa-border)' }"
                            >
                                <p class="text-sm text-[var(--wa-text-secondary)] mb-3">
                                    Отсканируйте QR код в WhatsApp
                                </p>
                                <img :src="qrImages[session.id]!" class="w-64 h-64 mx-auto bg-white p-2 rounded" alt="QR Code" />
                                <p class="text-xs text-[var(--wa-text-secondary)] mt-2">
                                    WhatsApp → Настройки → Связанные устройства
                                </p>
                            </div>
                        </div>
                    </div>
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
