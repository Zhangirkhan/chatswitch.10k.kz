<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import Avatar from '@/Components/Avatar.vue';
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import type { WhatsappSession } from '@/types';
import {
    WHATSAPP_SESSION_RING_PALETTE,
    normalizeHexColor,
    whatsappSessionRingColor,
} from '@/utils/whatsappSessionColor';
import { whatsappStatusChannel as buildWhatsappStatusChannel } from '@/utils/tenantChannels';

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

type BootstrapResponse = {
    whatsappServiceReachable: boolean;
    sessions: WhatsappSession[];
};

const props = defineProps<{
    sessions: WhatsappSession[];
    whatsappServiceReachable: boolean | null;
    sessionLimits: {
        global_max: number;
        global_count: number;
        tenant_max: number;
        tenant_count: number;
        remaining: number;
        can_create: boolean;
    };
}>();

const { t } = useI18n();
const page = usePage<any>();
const tenantCompanyId = computed(() => Number(page.props.tenantCompanyId || 0));

const localSessions = ref<WhatsappSession[]>([...props.sessions]);
const whatsappServiceReachable = ref<boolean | null>(props.whatsappServiceReachable);
const isBootstrapping = ref(props.whatsappServiceReachable === null);
const isCreating = ref(false);
const busySessionId = ref<number | null>(null);
const editingSessionId = ref<number | null>(null);
const editedDisplayName = ref('');
const editedDisplayColor = ref('');
const qrBySessionId = ref<Record<number, string>>({});
const qrUpdatedAtBySessionId = ref<Record<number, string>>({});
const message = ref<string | null>(null);
let qrRefreshTimer: ReturnType<typeof setInterval> | null = null;
let whatsappStatusChannel: any = null;

function onWhatsappStatusChanged(raw: unknown): void {
    if (!raw || typeof raw !== 'object') return;

    const payload = raw as Record<string, unknown>;
    const sessionName = typeof payload.session === 'string' ? payload.session : null;
    const status = typeof payload.status === 'string' ? payload.status : null;
    if (!sessionName || !status) return;

    const allowed: SessionStatus[] = ['disconnected', 'connecting', 'qr_pending', 'connected'];
    if (!allowed.includes(status as SessionStatus)) return;

    const index = localSessions.value.findIndex((s) => s.session_name === sessionName);
    if (index === -1) return;

    localSessions.value[index] = {
        ...localSessions.value[index],
        status: status as SessionStatus,
    };

    if (status === 'qr_pending' || status === 'connecting') {
        void loadQr(localSessions.value[index], { silent: true });
    }
}

function setupWhatsappStatusEcho(): void {
    const Echo = (window as any).Echo;
    if (!Echo) return;

    try {
        whatsappStatusChannel = Echo.private(buildWhatsappStatusChannel(tenantCompanyId.value));
        whatsappStatusChannel.listen('.status.changed', onWhatsappStatusChanged);
    } catch {
        whatsappStatusChannel = null;
    }
}

function teardownWhatsappStatusEcho(): void {
    try {
        whatsappStatusChannel?.stopListening('.status.changed', onWhatsappStatusChanged);
    } catch {
        /* ignore */
    }
    whatsappStatusChannel = null;
}

const hasSessions = computed(() => localSessions.value.length > 0);
const hasMultipleSessions = computed(() => localSessions.value.length > 1);
const canCreateSession = computed(
    () => props.sessionLimits.can_create && whatsappServiceReachable.value === true,
);
const sessionLimitLabel = computed(
    () => `${props.sessionLimits.tenant_count} / ${props.sessionLimits.tenant_max}`,
);

const editedRingPreviewColor = computed(() => {
    const normalized = normalizeHexColor(editedDisplayColor.value);
    if (normalized) {
        return normalized;
    }
    const editing = localSessions.value.find((s) => s.id === editingSessionId.value);

    return editing ? whatsappSessionRingColor(editing) : WHATSAPP_SESSION_RING_PALETTE[0];
});

watch(
    () => props.sessions,
    (sessions) => {
        localSessions.value = [...sessions];
    },
);

watch(
    () => props.whatsappServiceReachable,
    (reachable) => {
        whatsappServiceReachable.value = reachable;
        isBootstrapping.value = reachable === null;
    },
);

onMounted(async () => {
    if (props.whatsappServiceReachable === null) {
        await bootstrapConnections();
    }

    refreshVisibleQrCodes();
    qrRefreshTimer = setInterval(refreshVisibleQrCodes, 15000);
    setupWhatsappStatusEcho();
});

onBeforeUnmount(() => {
    if (qrRefreshTimer !== null) {
        clearInterval(qrRefreshTimer);
    }
    teardownWhatsappStatusEcho();
});

const statusLabels = computed<Record<SessionStatus, string>>(() => ({
    connected: t('whatsapp.status.connected'),
    connecting: t('whatsapp.status.connecting'),
    qr_pending: t('whatsapp.status.qrPending'),
    disconnected: t('whatsapp.status.disconnected'),
}));

const statusClasses: Record<SessionStatus, string> = {
    connected: 'bg-wa-accent',
    connecting: 'bg-amber-500',
    qr_pending: 'bg-amber-500',
    disconnected: 'bg-red-500',
};

function sessionLabel(session: WhatsappSession): string {
    return session.display_name?.trim() || session.session_name;
}

type ConnConfirmAction = 'logout' | 'remove';

const connConfirmOpen = ref(false);
const connConfirmAction = ref<ConnConfirmAction | null>(null);
const connConfirmSession = ref<WhatsappSession | null>(null);
const connConfirmBusy = ref(false);

const connConfirmTitle = computed(() =>
    connConfirmAction.value === 'logout'
        ? t('settings.connections.confirmLogoutTitle')
        : t('settings.connections.confirmRemoveTitle'),
);

const connConfirmDescription = computed(() => {
    const s = connConfirmSession.value;
    if (!s) return '';
    const label = sessionLabel(s);
    return connConfirmAction.value === 'logout'
        ? t('settings.connections.confirmLogoutDescription', { label })
        : t('settings.connections.confirmRemoveDescription', { label });
});

const connConfirmLabel = computed(() =>
    connConfirmAction.value === 'logout'
        ? t('settings.connections.confirmLogout')
        : t('settings.connections.confirmRemove'),
);

function requestLogout(session: WhatsappSession): void {
    connConfirmAction.value = 'logout';
    connConfirmSession.value = session;
    connConfirmOpen.value = true;
}

function requestRemove(session: WhatsappSession): void {
    connConfirmAction.value = 'remove';
    connConfirmSession.value = session;
    connConfirmOpen.value = true;
}

function closeConnConfirm(): void {
    if (connConfirmBusy.value) return;
    connConfirmOpen.value = false;
    connConfirmSession.value = null;
    connConfirmAction.value = null;
}

async function confirmConnAction(): Promise<void> {
    const session = connConfirmSession.value;
    const action = connConfirmAction.value;
    if (!session || !action) return;
    const kind = action;

    connConfirmBusy.value = true;
    try {
        connConfirmOpen.value = false;
        connConfirmSession.value = null;
        connConfirmAction.value = null;

        busySessionId.value = session.id;
        message.value = null;
        if (kind === 'logout') {
            await axios.post(route('settings.connections.logout', session.id));
            updateSession({ ...session, status: 'disconnected', disconnected_at: new Date().toISOString() });
            delete qrBySessionId.value[session.id];
        } else {
            await axios.delete(route('settings.connections.destroy', session.id));
            localSessions.value = localSessions.value.filter((item) => item.id !== session.id);
            delete qrBySessionId.value[session.id];
        }
    } catch (err: unknown) {
        const fallback =
            kind === 'logout' ? t('settings.connections.errorLogout') : t('settings.connections.errorRemove');
        message.value = errorMessage(err, fallback);
    } finally {
        busySessionId.value = null;
        connConfirmBusy.value = false;
    }
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
        return t('settings.connectionsExtras.dateToday', { time: timeOnlyFormatter.format(date) });
    }
    if (diffDays === 1) {
        return t('settings.connectionsExtras.dateYesterday', { time: timeOnlyFormatter.format(date) });
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

function errorMessage(err: unknown, fallback = t('settings.connections.errorGeneric')): string {
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

async function bootstrapConnections(): Promise<void> {
    isBootstrapping.value = true;

    try {
        const { data } = await axios.get<BootstrapResponse>(route('settings.connections.bootstrap'));
        whatsappServiceReachable.value = data.whatsappServiceReachable;
        localSessions.value = [...data.sessions];
    } catch (err: unknown) {
        message.value = errorMessage(err, t('settings.connections.serviceUnavailable'));
        whatsappServiceReachable.value = null;
    } finally {
        isBootstrapping.value = false;
    }
}

function shouldRefreshQr(session: WhatsappSession): boolean {
    return whatsappServiceReachable.value === true && session.status !== 'connected';
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
    if (whatsappServiceReachable.value !== true) {
        message.value = t('settings.connections.serviceUnavailableAction');
        return;
    }

    if (!props.sessionLimits.can_create) {
        message.value = t('settings.connectionsExtras.limitReached', {
            tenantMax: props.sessionLimits.tenant_max,
            globalCount: props.sessionLimits.global_count,
            globalMax: props.sessionLimits.global_max,
        });
        return;
    }

    isCreating.value = true;
    message.value = null;

    try {
        const { data } = await axios.post<{ session: WhatsappSession }>(route('settings.connections.store'));
        updateSession(data.session);
        await loadQr(data.session, { silent: true });
    } catch (err: unknown) {
        message.value = errorMessage(err, t('settings.connections.errorCreate'));
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
        message.value = errorMessage(err, t('settings.connections.errorInitialize'));
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
            message.value = data.message || data.error || t('settings.connectionsExtras.qrNotReady');
        }
    } catch (err: unknown) {
        if (!silent) {
            message.value = errorMessage(err, t('settings.connections.errorQr'));
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
        message.value = errorMessage(err, t('settings.connections.errorStatus'));
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
        message.value = errorMessage(err, t('settings.connections.errorVerify'));
    } finally {
        busySessionId.value = null;
    }
}

function sessionRingColor(session: WhatsappSession): string {
    return whatsappSessionRingColor(session) ?? WHATSAPP_SESSION_RING_PALETTE[0];
}

function startEdit(session: WhatsappSession): void {
    editingSessionId.value = session.id;
    editedDisplayName.value = session.display_name || '';
    editedDisplayColor.value = session.display_color || sessionRingColor(session);
}

function cancelEdit(): void {
    if (busySessionId.value !== null) {
        return;
    }
    editingSessionId.value = null;
}

function pickPaletteColor(hex: string): void {
    editedDisplayColor.value = hex;
}

async function saveDisplayName(session: WhatsappSession): Promise<void> {
    const displayName = editedDisplayName.value.trim();
    if (!displayName) {
        message.value = t('settings.connections.errorDisplayNameRequired');
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
        message.value = errorMessage(err, t('settings.connections.errorSaveName'));
    } finally {
        busySessionId.value = null;
    }
}


async function reloadPage(): Promise<void> {
    await router.reload({ only: ['sessions', 'whatsappServiceReachable'] });
    if (props.whatsappServiceReachable === null) {
        await bootstrapConnections();
    } else {
        whatsappServiceReachable.value = props.whatsappServiceReachable;
    }
}
</script>

<template>
    <Head :title="t('settings.connections.title')" />

    <SettingsLayout
        :title="t('settings.connections.title')"
        :subtitle="t('settings.connections.subtitle')"
    >
        <template #actions>
            <button
                type="button"
                class="ui-btn ui-btn--primary"
                :disabled="isCreating || !canCreateSession"
                @click="createSession"
            >
                {{ isCreating ? t('settings.connections.creating') : t('settings.connections.addConnection') }}
            </button>
        </template>

        <div class="w-full px-6 py-6 space-y-4">
            <div class="ui-panel px-4 py-3 text-sm text-ui-text-secondary">
                {{ t('settings.connections.limitsCount') }} <span class="font-semibold text-ui-text">{{ sessionLimitLabel }}</span>
                <span class="mx-2 text-ui-text-muted">·</span>
                {{ t('settings.connections.limitsServer', { global: sessionLimits.global_count, max: sessionLimits.global_max }) }}
                <p v-if="!sessionLimits.can_create" class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                    {{ t('settings.connections.limitsExhausted') }}
                </p>
            </div>
            <div
                v-if="isBootstrapping"
                class="ui-alert ui-alert--info text-sm"
            >
                {{ t('settings.connections.bootstrapping') }}
            </div>
            <div
                v-else-if="whatsappServiceReachable === false"
                class="ui-alert ui-alert--warn"
            >
                {{ t('settings.connections.serviceUnavailable') }}
            </div>

            <div
                v-if="message"
                class="ui-alert ui-alert--danger"
            >
                {{ message }}
            </div>

            <div
                v-if="!hasSessions"
                class="ui-empty-state"
            >
                <h3 class="text-[var(--ui-text)] font-medium">{{ t('settings.connections.emptyTitle') }}</h3>
                <p class="text-sm text-[var(--ui-text-secondary)] mt-1">
                    {{ t('settings.connections.emptyHint') }}
                </p>
                <button
                    type="button"
                    class="ui-btn ui-btn--primary mt-5"
                    :disabled="isCreating || !canCreateSession"
                    @click="createSession"
                >
                    {{ t('settings.connections.createFirst') }}
                </button>
            </div>

            <template v-else>
            <div
                v-if="hasMultipleSessions"
                class="ui-alert ui-alert--info text-sm leading-relaxed"
            >
                <strong class="font-medium text-[var(--ui-text)]">{{ t('settings.connections.multiSessionsTitle') }}</strong>
                <p class="mt-1 text-[var(--ui-text-secondary)]">
                    {{ t('settings.connections.multiSessionsHint') }}
                </p>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                <div
                    v-for="session in localSessions"
                    :key="session.id"
                    class="ui-panel p-5 space-y-4"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 min-w-0">
                                <div class="text-left font-medium text-[var(--ui-text)] truncate max-w-full">
                                    {{ sessionLabel(session) }}
                                </div>
                                <span class="inline-flex items-center gap-2 text-xs text-[var(--ui-text-secondary)] whitespace-nowrap sm:hidden">
                                    <span class="w-2 h-2 rounded-full" :class="statusClasses[session.status]"></span>
                                    {{ statusLabels[session.status] }}
                                </span>
                            </div>
                            <p class="text-xs text-[var(--ui-text-secondary)] truncate mt-0.5">
                                {{ session.session_name }}
                            </p>
                        </div>

                        <span class="hidden sm:inline-flex items-center gap-2 text-xs text-[var(--ui-text-secondary)] whitespace-nowrap">
                            <span class="w-2 h-2 rounded-full" :class="statusClasses[session.status]"></span>
                            {{ statusLabels[session.status] }}
                        </span>
                    </div>

                    <div
                        class="session-color-block rounded-xl border p-3 space-y-3"
                        :style="{ borderColor: 'var(--ui-border)', background: 'var(--ui-surface)' }"
                    >
                        <div class="flex items-start gap-3">
                            <Avatar
                                :name="sessionLabel(session)"
                                :size="44"
                                :ring-color="editingSessionId === session.id ? editedRingPreviewColor : sessionRingColor(session)"
                            />
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-[var(--ui-text)]">
                                    {{ hasMultipleSessions ? t('settings.connections.colorLabelMulti') : t('settings.connections.colorLabelSingle') }}
                                </p>
                                <p class="text-xs text-[var(--ui-text-secondary)] mt-0.5 leading-relaxed">
                                    <template v-if="hasMultipleSessions">
                                        {{ t('settings.connectionsExtras.colorHintMulti') }}
                                    </template>
                                    <template v-else>
                                        {{ t('settings.connectionsExtras.colorHintSingle') }}
                                    </template>
                                </p>
                            </div>
                        </div>

                        <div v-if="editingSessionId === session.id" class="space-y-3 pt-1 border-t" :style="{ borderColor: 'var(--ui-border)' }">
                            <div>
                                <label class="block text-xs font-medium text-[var(--ui-text-secondary)] mb-1">
                                    {{ t('settings.connectionsExtras.systemName') }}
                                </label>
                                <input
                                    v-model="editedDisplayName"
                                    type="text"
                                    class="settings-input w-full"
                                    maxlength="100"
                                    :placeholder="t('settings.connections.displayNamePlaceholder')"
                                    @keyup.enter="saveDisplayName(session)"
                                />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-[var(--ui-text-secondary)] mb-1">
                                    {{ t('settings.connectionsExtras.ringColor') }}
                                </label>
                                <div class="flex flex-wrap items-center gap-2">
                                    <input
                                        :value="editedDisplayColor || '#01b964'"
                                        type="color"
                                        class="session-color-input"
                                        :aria-label="t('settings.connections.pickRingColor')"
                                        @input="editedDisplayColor = ($event.target as HTMLInputElement).value"
                                    />
                                    <span class="text-xs text-[var(--ui-text-secondary)]">{{ t('settings.connectionsExtras.orQuickPick') }}</span>
                                    <div class="flex flex-wrap gap-1.5" role="group" :aria-label="t('settings.connections.presetColors')">
                                        <button
                                            v-for="hex in WHATSAPP_SESSION_RING_PALETTE"
                                            :key="hex"
                                            type="button"
                                            class="session-color-swatch"
                                            :class="{ 'session-color-swatch--active': editedRingPreviewColor === hex }"
                                            :style="{ background: hex }"
                                            :title="hex"
                                            :aria-label="t('settings.connectionsExtras.colorAria', { hex })"
                                            @click="pickPaletteColor(hex)"
                                        />
                                    </div>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    class="ui-btn ui-btn--primary ui-btn--sm"
                                    :disabled="busySessionId === session.id"
                                    @click="saveDisplayName(session)"
                                >
                                    {{ busySessionId === session.id ? t('settings.connections.saving') : t('common.save') }}
                                </button>
                                <button
                                    type="button"
                                    class="ui-btn ui-btn--secondary ui-btn--sm"
                                    :disabled="busySessionId === session.id"
                                    @click="cancelEdit"
                                >
                                    {{ t('common.cancel') }}
                                </button>
                            </div>
                        </div>
                        <button
                            v-else
                            type="button"
                            class="ui-btn ui-btn--secondary ui-btn--sm"
                            @click="startEdit(session)"
                        >
                            {{ t('settings.connectionsExtras.editNameAndColor') }}
                        </button>
                    </div>

                    <div
                        v-if="session.status === 'qr_pending'"
                        class="ui-alert ui-alert--warn text-sm leading-relaxed"
                    >
                        {{ t('settings.connectionsExtras.qrRequiredAlert') }}
                        <p v-if="session.last_disconnect_reason" class="mt-1 text-xs opacity-90">
                            {{ t('settings.connectionsExtras.disconnectReason') }}: {{ session.last_disconnect_reason }}
                        </p>
                        <p v-if="session.qr_required_at" class="mt-1 text-xs opacity-90">
                            {{ t('settings.connectionsExtras.qrRequiredSince') }}: {{ formatDateTime(session.qr_required_at) }}
                        </p>
                    </div>

                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-xs text-[var(--ui-text-secondary)]">{{ t('settings.connectionsExtras.whatsappNumber') }}</dt>
                            <dd class="text-[var(--ui-text)]">{{ session.phone_number || '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-[var(--ui-text-secondary)]">{{ t('settings.connectionsExtras.whatsappName') }}</dt>
                            <dd class="text-[var(--ui-text)]">{{ session.wa_name || '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-[var(--ui-text-secondary)]">{{ t('settings.connectionsExtras.platform') }}</dt>
                            <dd class="text-[var(--ui-text)]">{{ session.wa_platform || '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-[var(--ui-text-secondary)]">{{ t('settings.connectionsExtras.connected') }}</dt>
                            <dd
                                class="text-[var(--ui-text)]"
                                :title="fullDateTimeTitle(session.connected_at)"
                            >
                                {{ formatDateTime(session.connected_at) }}
                            </dd>
                        </div>
                        <div v-if="session.disconnected_at && session.status !== 'connected'">
                            <dt class="text-xs text-[var(--ui-text-secondary)]">{{ t('settings.connectionsExtras.disconnected') }}</dt>
                            <dd
                                class="text-[var(--ui-text)]"
                                :title="fullDateTimeTitle(session.disconnected_at)"
                            >
                                {{ formatDateTime(session.disconnected_at) }}
                            </dd>
                        </div>
                    </dl>

                    <div
                        v-if="qrBySessionId[session.id]"
                        class="ui-panel p-4 flex flex-col sm:flex-row gap-4 items-center"
                    >
                        <img
                            :key="qrUpdatedAtBySessionId[session.id]"
                            :src="qrBySessionId[session.id]"
                            alt="WhatsApp QR"
                            class="w-44 h-44 rounded bg-white p-2"
                        />
                        <div class="text-sm text-[var(--ui-text-secondary)] space-y-2">
                            <p>{{ t('settings.connectionsExtras.qrHint') }}</p>
                            <p v-if="qrUpdatedAtBySessionId[session.id]" class="text-xs">
                                {{ t('settings.connectionsExtras.qrUpdated', { time: qrUpdatedAtBySessionId[session.id] }) }}
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="ui-btn ui-btn--secondary ui-btn--sm"
                            :disabled="busySessionId === session.id || !whatsappServiceReachable"
                            @click="initialize(session)"
                        >
                            {{ t('settings.connectionsExtras.connect') }}
                        </button>
                        <button
                            type="button"
                            class="ui-btn ui-btn--secondary ui-btn--sm"
                            :disabled="busySessionId === session.id || !whatsappServiceReachable"
                            @click="loadQr(session)"
                        >
                            {{ t('settings.connectionsExtras.refreshQr') }}
                        </button>
                        <button
                            type="button"
                            class="ui-btn ui-btn--secondary ui-btn--sm"
                            :disabled="busySessionId === session.id || !whatsappServiceReachable"
                            @click="refreshStatus(session)"
                        >
                            {{ t('settings.connectionsExtras.status') }}
                        </button>
                        <button
                            type="button"
                            class="ui-btn ui-btn--secondary ui-btn--sm"
                            :disabled="busySessionId === session.id || !whatsappServiceReachable"
                            @click="verify(session)"
                        >
                            {{ t('settings.connectionsExtras.verify') }}
                        </button>
                        <button
                            type="button"
                            class="ui-btn ui-btn--danger-ghost ui-btn--sm"
                            :disabled="busySessionId === session.id"
                            @click="requestLogout(session)"
                        >
                            {{ t('settings.connectionsExtras.logout') }}
                        </button>
                        <button
                            type="button"
                            class="ui-btn ui-btn--danger-ghost ui-btn--sm"
                            :disabled="busySessionId === session.id"
                            @click="requestRemove(session)"
                        >
                            {{ t('common.delete') }}
                        </button>
                    </div>
                </div>
            </div>

            <button
                type="button"
                class="text-xs text-[var(--ui-text-secondary)] hover:underline"
                @click="reloadPage"
            >
                {{ t('settings.connectionsExtras.refreshPage') }}
            </button>
            </template>
        </div>
    </SettingsLayout>

    <DangerConfirmModal
        :open="connConfirmOpen"
        :title="connConfirmTitle"
        :description="connConfirmDescription"
        :confirm-label="connConfirmLabel"
        :busy="connConfirmBusy"
        confirm-variant="danger"
        @close="closeConnConfirm"
        @confirm="confirmConnAction"
    />
</template>

<style scoped>
.session-color-input {
    width: 2.75rem;
    height: 2.75rem;
    padding: 0.15rem;
    border-radius: 0.5rem;
    border: 1px solid var(--ui-border);
    background: transparent;
    cursor: pointer;
}

.session-color-swatch {
    width: 1.75rem;
    height: 1.75rem;
    border-radius: 9999px;
    border: 2px solid transparent;
    cursor: pointer;
    transition: transform 0.12s ease, box-shadow 0.12s ease;
}

.session-color-swatch:hover {
    transform: scale(1.08);
}

.session-color-swatch--active {
    border-color: var(--ui-text);
    box-shadow: 0 0 0 2px var(--ui-surface), 0 0 0 3px var(--ui-text-secondary);
}
</style>

