<script setup lang="ts">
import axios from 'axios';
import { onBeforeUnmount, ref, watch } from 'vue';

interface WaSession {
    id: number;
    session_name: string;
    phone_number: string | null;
    display_name: string | null;
    status: string;
    desired_state: string;
    is_active: boolean;
    connected_at: string | null;
}

const props = defineProps<{
    companyId: number;
    sessions: WaSession[];
    whatsappServiceReachable: boolean;
    maxSessions: number;
    active?: boolean;
}>();

const qrById = ref<Record<number, string>>({});
const statusById = ref<Record<number, string>>({});
const busyId = ref<number | null>(null);
let timer: ReturnType<typeof setInterval> | null = null;

const statusLabels: Record<string, string> = {
    connected: 'Подключён',
    qr: 'Ожидает QR',
    initializing: 'Инициализация',
    disconnected: 'Отключён',
    error: 'Ошибка',
};

async function refreshSession(session: WaSession): Promise<void> {
    if (!props.whatsappServiceReachable) return;
    busyId.value = session.id;
    try {
        const { data } = await axios.get(
            `/companies/${props.companyId}/whatsapp-sessions/${session.id}/status`,
        );
        const st = data?.status ?? data?.session?.status ?? session.status;
        statusById.value[session.id] = typeof st === 'string' ? st : session.status;

        if (st === 'qr' || data?.hasQR) {
            const qrRes = await axios.get(
                `/companies/${props.companyId}/whatsapp-sessions/${session.id}/qr`,
            );
            const qr = qrRes.data?.qr ?? qrRes.data?.qrCode ?? qrRes.data?.dataUrl;
            if (typeof qr === 'string' && qr.length > 0) {
                qrById.value[session.id] = qr.startsWith('data:') ? qr : `data:image/png;base64,${qr}`;
            }
        }
    } catch {
        statusById.value[session.id] = 'error';
    } finally {
        busyId.value = null;
    }
}

async function refreshAll(): Promise<void> {
    for (const s of props.sessions) {
        if (s.status === 'qr' || s.desired_state === 'active') {
            await refreshSession(s);
        }
    }
}

function startPolling(): void {
    if (timer !== null) return;
    refreshAll();
    timer = setInterval(refreshAll, 20000);
}

function stopPolling(): void {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
}

watch(
    () => props.active,
    (isActive) => {
        if (isActive) {
            startPolling();
        } else {
            stopPolling();
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    stopPolling();
});

function displayStatus(s: WaSession): string {
    return statusLabels[statusById.value[s.id] ?? s.status] ?? (statusById.value[s.id] ?? s.status);
}
</script>

<template>
    <div class="space-y-4">
        <div class="ui-panel px-4 py-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <span class="text-sm text-ui-text-secondary">Номеров подключено</span>
                    <span class="ml-2 font-semibold text-ui-text">{{ sessions.length }} / {{ maxSessions }}</span>
                </div>
                <span
                    class="ui-badge"
                    :class="whatsappServiceReachable ? 'ui-badge--success' : 'ui-badge--admin'"
                >
                    {{ whatsappServiceReachable ? 'Сервис доступен' : 'Сервис недоступен' }}
                </span>
            </div>
            <p class="mt-2 text-xs text-ui-text-muted">
                Управление подключением — в настройках тенанта. Здесь статус и QR для супер-админа.
            </p>
        </div>

        <div v-if="sessions.length === 0" class="ui-empty-state ui-empty-state--dashed">
            WhatsApp-сессий нет
        </div>

        <div v-else class="grid gap-4 md:grid-cols-2">
            <div v-for="s in sessions" :key="s.id" class="ui-settings-section">
                <div class="mb-2 flex items-start justify-between gap-2">
                    <div>
                        <div class="font-medium">{{ s.display_name || s.session_name }}</div>
                        <div class="font-mono text-xs text-ui-text-muted">{{ s.session_name }}</div>
                        <div v-if="s.phone_number" class="text-sm text-ui-text-secondary">{{ s.phone_number }}</div>
                    </div>
                    <span class="ui-badge ui-badge--neutral">{{ displayStatus(s) }}</span>
                </div>
                <button
                    type="button"
                    class="ui-btn ui-btn--ghost ui-btn--sm mb-3"
                    :disabled="busyId === s.id || !whatsappServiceReachable"
                    @click="refreshSession(s)"
                >
                    {{ busyId === s.id ? '…' : 'Обновить статус / QR' }}
                </button>
                <img
                    v-if="qrById[s.id]"
                    :src="qrById[s.id]"
                    alt="QR код WhatsApp"
                    class="mx-auto max-h-48 rounded-lg border border-ui-border bg-white p-2"
                />
            </div>
        </div>
    </div>
</template>
