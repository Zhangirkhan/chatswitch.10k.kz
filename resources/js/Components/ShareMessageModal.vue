<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import type { Contact, Message } from '@/types';
import { formatPhone } from '@/utils/phone';

export type ShareColleagueTarget = {
    id: number;
    title: string;
    subtitle: string | null;
};

export type ShareWhatsappSession = {
    id: number;
    display_name?: string | null;
    session_name?: string | null;
    phone_number?: string | null;
};

export type ShareModalSource =
    | {
          kind: 'whatsapp';
          message?: Message;
          messageIds?: number[];
          whatsappSessionId: number | null;
      }
    | {
          kind: 'team';
          messageId: number;
      };

const props = defineProps<{
    open: boolean;
    source: ShareModalSource | null;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'sent', payload: { tab: 'clients' | 'colleagues'; count: number }): void;
}>();

type ShareTab = 'clients' | 'colleagues';

const activeTab = ref<ShareTab>('colleagues');
const caption = ref('');
const q = ref('');
const loading = ref(false);
const sending = ref(false);
const error = ref<string | null>(null);

const colleagues = ref<ShareColleagueTarget[]>([]);
const selectedColleagueIds = ref<Set<number>>(new Set());

const contacts = ref<Contact[]>([]);
const sessions = ref<ShareWhatsappSession[]>([]);
const sessionsBootstrapped = ref(false);
const selectedContactIds = ref<Set<number>>(new Set());
const selectedSessionId = ref<number | null>(null);

const isWhatsappSource = computed(() => props.source?.kind === 'whatsapp');
const isTeamSource = computed(() => props.source?.kind === 'team');
const isBulkWhatsapp = computed(() => {
    const ids = props.source?.kind === 'whatsapp' ? props.source.messageIds : undefined;
    return Array.isArray(ids) && ids.length > 0;
});

/** Блокируем вкладку только когда точно нет WA-номеров; иначе можно открыть и подгрузить сессии. */
const clientsTabDisabled = computed(() => {
    if (!props.source) return true;
    if (!sessionsBootstrapped.value) return false;
    return sessions.value.length === 0;
});

const clientsTabHint = computed(() => {
    if (!clientsTabDisabled.value) return '';
    if (!sessionsBootstrapped.value) return '';
    return 'Нет доступных номеров WhatsApp для отправки клиентам';
});

const clientsNeedSessionPick = computed(
    () => isTeamSource.value && sessionsBootstrapped.value && sessions.value.length > 0 && effectiveSessionId.value == null,
);

const effectiveSessionId = computed((): number | null => {
    const source = props.source;
    if (source?.kind === 'whatsapp' && source.whatsappSessionId != null) {
        return source.whatsappSessionId;
    }
    return selectedSessionId.value;
});

const selectedColleagueCount = computed(() => selectedColleagueIds.value.size);
const selectedContactCount = computed(() => selectedContactIds.value.size);

const selectedCount = computed(() =>
    activeTab.value === 'colleagues' ? selectedColleagueCount.value : selectedContactCount.value,
);

const selectedContacts = computed(() => contacts.value.filter((c) => selectedContactIds.value.has(c.id)));

function contactLabel(c: Contact): string {
    const preferred = (c.display_name || '').trim();
    if (preferred) return preferred;
    return c.name || (c.push_name ? `~ ${c.push_name}` : '') || formatPhone(c.phone_number) || '';
}

function sessionLabel(s: ShareWhatsappSession): string {
    const name = (s.display_name || s.session_name || '').trim();
    const phone = (s.phone_number || '').trim();
    if (name && phone) return `${name} · ${phone}`;
    return name || phone || `Сессия #${s.id}`;
}

const filteredColleagues = computed(() => {
    const needle = q.value.trim().toLowerCase();
    if (!needle) return colleagues.value;
    return colleagues.value.filter((c) => {
        const hay = `${c.title} ${c.subtitle ?? ''}`.toLowerCase();
        return hay.includes(needle);
    });
});

async function loadColleagues(): Promise<void> {
    try {
        const { data } = await axios.get(route('organization.team-chat.api.conversations'));
        const rows = (data.conversations ?? []) as { id: number; title: string; subtitle?: string | null }[];
        colleagues.value = rows.map((r) => ({
            id: r.id,
            title: r.title,
            subtitle: r.subtitle ?? null,
        }));
    } catch {
        colleagues.value = [];
    }
}

function applySessionsFromApi(loadedSessions: ShareWhatsappSession[]): void {
    sessions.value = loadedSessions;
    if (loadedSessions.length === 0) {
        return;
    }
    const src = props.source;
    const preferred =
        src?.kind === 'whatsapp' && src.whatsappSessionId != null
            ? src.whatsappSessionId
            : selectedSessionId.value ?? loadedSessions[0]!.id;
    const exists = loadedSessions.some((s) => s.id === preferred);
    selectedSessionId.value = exists ? preferred : loadedSessions[0]!.id;
}

async function bootstrapWhatsappSessions(): Promise<void> {
    try {
        const { data } = await axios.get(route('chats.contacts'));
        applySessionsFromApi((data.sessions || []) as ShareWhatsappSession[]);
    } catch {
        sessions.value = [];
    } finally {
        sessionsBootstrapped.value = true;
    }
}

async function loadClients(): Promise<void> {
    const sid = effectiveSessionId.value;
    try {
        const { data } = await axios.get(route('chats.contacts'), {
            params: {
                search: q.value.trim() || undefined,
                whatsapp_session_id: sid && sid > 0 ? sid : undefined,
            },
        });
        contacts.value = (data.contacts || []) as Contact[];
        applySessionsFromApi((data.sessions || []) as ShareWhatsappSession[]);
        sessionsBootstrapped.value = true;
    } catch {
        contacts.value = [];
    }
}

async function loadTabData(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        if (activeTab.value === 'colleagues') {
            await loadColleagues();
        } else {
            await loadClients();
        }
    } finally {
        loading.value = false;
    }
}

function resetState(): void {
    caption.value = '';
    q.value = '';
    error.value = null;
    selectedColleagueIds.value = new Set();
    selectedContactIds.value = new Set();
    colleagues.value = [];
    contacts.value = [];
    sessions.value = [];
    sessionsBootstrapped.value = false;
    const src = props.source;
    selectedSessionId.value = src?.kind === 'whatsapp' && src.whatsappSessionId != null ? src.whatsappSessionId : null;
    activeTab.value = props.source?.kind === 'team' ? 'colleagues' : 'clients';
}

function toggleColleague(id: number): void {
    const next = new Set(selectedColleagueIds.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    selectedColleagueIds.value = next;
}

function toggleContact(id: number): void {
    const next = new Set(selectedContactIds.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    selectedContactIds.value = next;
}

function removeSelectedContact(id: number): void {
    if (!selectedContactIds.value.has(id)) return;
    const next = new Set(selectedContactIds.value);
    next.delete(id);
    selectedContactIds.value = next;
}

let searchTimer: ReturnType<typeof setTimeout> | null = null;
watch(q, () => {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => void loadTabData(), 250);
});

watch(activeTab, () => {
    q.value = '';
    void loadTabData();
});

watch(selectedSessionId, () => {
    if (activeTab.value === 'clients' && isTeamSource.value) {
        void loadClients();
    }
});

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        resetState();
        void bootstrapWhatsappSessions();
        void loadTabData();
    },
);

async function sendToColleagues(): Promise<void> {
    const source = props.source;
    if (!source) return;

    const ids = Array.from(selectedColleagueIds.value);
    if (ids.length === 0) return;

    if (source.kind === 'whatsapp' && isBulkWhatsapp.value) {
        error.value = 'Сотрудникам можно отправить только одно сообщение. Снимите лишние из выбора.';
        return;
    }

    const cap = caption.value.trim();

    if (source.kind === 'team') {
        for (const conversationId of ids) {
            const payload: Record<string, unknown> = {
                forwarded_from_team_message_id: source.messageId,
                client_message_id: crypto.randomUUID(),
            };
            if (cap !== '') payload.body = cap;
            await axios.post(route('organization.team-chat.api.messages.store', conversationId), payload);
        }
        return;
    }

    const message = source.message;
    if (!message?.id) {
        error.value = 'Сообщение не выбрано';
        return;
    }

    await axios.post(route('messages.share-to-team', message.id), {
        team_conversation_ids: ids,
        body: cap !== '' ? cap : undefined,
    });
}

async function sendToClients(): Promise<void> {
    const source = props.source;
    if (!source) return;

    const contactIds = Array.from(selectedContactIds.value);
    const sessionId = effectiveSessionId.value;
    if (contactIds.length === 0 || sessionId == null) return;

    const cap = caption.value.trim();

    if (source.kind === 'team') {
        await axios.post(route('organization.team-chat.api.messages.share-to-clients', source.messageId), {
            contact_ids: contactIds,
            whatsapp_session_id: sessionId,
            body: cap !== '' ? cap : undefined,
        });
        return;
    }

    const bulkIds = (source.messageIds || []).filter((n: number) => typeof n === 'number');
    const isBulk = bulkIds.length > 0;
    const messageId = source.message?.id;
    if (!isBulk && messageId == null) {
        error.value = 'Сообщение не выбрано';
        return;
    }
    const url = isBulk ? route('messages.forward-bulk') : route('messages.forward', messageId!);
    const { data } = await axios.post(
        url,
        isBulk
            ? { message_ids: bulkIds, contact_ids: contactIds, whatsapp_session_id: sessionId }
            : { contact_ids: contactIds, whatsapp_session_id: sessionId },
    );
    if (!data?.success) {
        throw new Error(data?.error || 'Не удалось отправить');
    }
}

async function send(): Promise<void> {
    if (sending.value || selectedCount.value === 0) return;
    if (activeTab.value === 'clients' && (clientsTabDisabled.value || clientsNeedSessionPick.value)) {
        if (clientsNeedSessionPick.value) {
            error.value = 'Выберите номер WhatsApp';
        }
        return;
    }

    sending.value = true;
    error.value = null;
    try {
        if (activeTab.value === 'colleagues') {
            await sendToColleagues();
        } else {
            await sendToClients();
        }
        emit('sent', { tab: activeTab.value, count: selectedCount.value });
        emit('close');
    } catch (e: unknown) {
        const err = e as { response?: { status?: number; data?: { message?: string; error?: string } }; message?: string };
        const status = err?.response?.status;
        const resp = err?.response?.data;
        const msg =
            resp?.message ||
            resp?.error ||
            err?.message ||
            'Не удалось отправить';
        error.value = status ? `[${status}] ${msg}` : msg;
    } finally {
        sending.value = false;
    }
}

onMounted(() => {
    if (props.open) {
        resetState();
        void bootstrapWhatsappSessions();
        void loadTabData();
    }
});
</script>

<template>
    <teleport to="body">
        <div
            v-if="open && source"
            class="fixed inset-0 z-[300] flex items-center justify-center px-4"
            :style="{ background: 'rgba(0,0,0,.45)' }"
            role="dialog"
            aria-modal="true"
            @click.self="emit('close')"
        >
            <div
                class="w-full max-w-[520px] rounded-2xl border overflow-hidden"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
                @click.stop
            >
                <div class="px-5 py-4 flex items-center justify-between" :style="{ background: 'var(--wa-panel-header)' }">
                    <div class="text-sm font-medium" :style="{ color: 'var(--wa-text)' }">
                        Отправить в…
                    </div>
                    <button
                        type="button"
                        class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)]"
                        aria-label="Закрыть"
                        @click="emit('close')"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="flex border-b" :style="{ borderColor: 'var(--wa-border)' }">
                    <button
                        type="button"
                        class="flex-1 px-4 py-2.5 text-sm font-medium transition"
                        :style="{
                            color: activeTab === 'clients' ? 'var(--wa-accent)' : 'var(--wa-text-secondary)',
                            borderBottom: activeTab === 'clients' ? '2px solid var(--wa-accent)' : '2px solid transparent',
                            opacity: clientsTabDisabled ? 0.45 : 1,
                        }"
                        :disabled="clientsTabDisabled"
                        :title="clientsTabHint"
                        @click="!clientsTabDisabled && (activeTab = 'clients')"
                    >
                        Клиентам
                    </button>
                    <button
                        type="button"
                        class="flex-1 px-4 py-2.5 text-sm font-medium transition"
                        :style="{
                            color: activeTab === 'colleagues' ? 'var(--wa-accent)' : 'var(--wa-text-secondary)',
                            borderBottom: activeTab === 'colleagues' ? '2px solid var(--wa-accent)' : '2px solid transparent',
                        }"
                        @click="activeTab = 'colleagues'"
                    >
                        Сотрудникам
                    </button>
                </div>

                <div class="p-5">
                    <div v-if="isTeamSource && activeTab === 'clients' && sessions.length > 0" class="mb-3">
                        <label class="block text-xs mb-1" :style="{ color: 'var(--wa-text-secondary)' }">Номер WhatsApp</label>
                        <select
                            v-model="selectedSessionId"
                            class="w-full rounded-xl px-3 py-2 text-sm border-0 focus:ring-0 focus:outline-none"
                            :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-text)' }"
                        >
                            <option v-for="s in sessions" :key="s.id" :value="s.id">{{ sessionLabel(s) }}</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="block text-xs mb-1" :style="{ color: 'var(--wa-text-secondary)' }">Комментарий (необязательно)</label>
                        <textarea
                            v-model="caption"
                            rows="2"
                            class="w-full rounded-xl px-3 py-2 text-sm border-0 resize-none focus:ring-0 focus:outline-none"
                            :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-text)' }"
                            placeholder="Добавить текст к пересылке…"
                        />
                    </div>

                    <div class="relative">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <input
                            v-model="q"
                            type="text"
                            :placeholder="activeTab === 'colleagues' ? 'Поиск бесед…' : 'Поиск контактов…'"
                            class="w-full pl-10 pr-3 py-2 rounded-full text-sm border-0 focus:ring-0 focus:outline-none"
                            :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-text)' }"
                        />
                    </div>

                    <p
                        v-if="activeTab === 'clients' && clientsTabHint && clientsTabDisabled"
                        class="text-xs mt-2"
                        :style="{ color: 'var(--wa-text-secondary)' }"
                    >
                        {{ clientsTabHint }}
                    </p>

                    <p
                        v-if="activeTab === 'colleagues' && isBulkWhatsapp"
                        class="text-xs mt-2"
                        :style="{ color: 'var(--wa-text-secondary)' }"
                    >
                        Несколько сообщений можно отправить только клиентам. Для сотрудников выберите одно сообщение.
                    </p>

                    <div v-if="activeTab === 'clients' && selectedContacts.length" class="mt-3">
                        <div class="mb-2 text-xs" :style="{ color: 'var(--wa-text-secondary)' }">Кому:</div>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="c in selectedContacts"
                                :key="`selected-${c.id}`"
                                type="button"
                                class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs"
                                :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-text)' }"
                                @click="removeSelectedContact(c.id)"
                            >
                                <span class="max-w-[180px] truncate">{{ contactLabel(c) || 'Без имени' }}</span>
                                <span aria-hidden="true">×</span>
                            </button>
                        </div>
                    </div>

                    <div v-if="error" class="text-xs mt-3" style="color:#ff6b6b;">
                        {{ error }}
                    </div>

                    <div class="mt-4 max-h-[320px] overflow-y-auto wa-scrollbar">
                        <div v-if="loading" class="text-sm px-2 py-3" :style="{ color: 'var(--wa-text-secondary)' }">Загрузка…</div>

                        <template v-else-if="activeTab === 'colleagues'">
                            <button
                                v-for="c in filteredColleagues"
                                :key="c.id"
                                type="button"
                                class="w-full flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-[var(--wa-panel-hover)] transition"
                                @click="toggleColleague(c.id)"
                            >
                                <span
                                    class="w-5 h-5 rounded-md border flex items-center justify-center shrink-0"
                                    :style="{
                                        borderColor: 'var(--wa-border)',
                                        background: selectedColleagueIds.has(c.id) ? 'var(--wa-accent)' : 'transparent',
                                        color: selectedColleagueIds.has(c.id) ? '#fff' : 'transparent',
                                    }"
                                >
                                    ✓
                                </span>
                                <div class="flex-1 min-w-0 text-left">
                                    <div class="text-sm truncate" :style="{ color: 'var(--wa-text)' }">{{ c.title }}</div>
                                    <div v-if="c.subtitle" class="text-xs truncate" :style="{ color: 'var(--wa-text-secondary)' }">{{ c.subtitle }}</div>
                                </div>
                            </button>
                            <div v-if="filteredColleagues.length === 0" class="text-sm px-2 py-3" :style="{ color: 'var(--wa-text-secondary)' }">
                                Беседы не найдены
                            </div>
                        </template>

                        <template v-else>
                            <button
                                v-for="c in contacts"
                                :key="c.id"
                                type="button"
                                class="w-full flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-[var(--wa-panel-hover)] transition"
                                @click="toggleContact(c.id)"
                            >
                                <span
                                    class="w-5 h-5 rounded-md border flex items-center justify-center shrink-0"
                                    :style="{
                                        borderColor: 'var(--wa-border)',
                                        background: selectedContactIds.has(c.id) ? 'var(--wa-accent)' : 'transparent',
                                        color: selectedContactIds.has(c.id) ? '#fff' : 'transparent',
                                    }"
                                >
                                    ✓
                                </span>
                                <div class="flex-1 min-w-0 text-left">
                                    <div class="text-sm truncate" :style="{ color: 'var(--wa-text)' }">
                                        {{ contactLabel(c) || 'Без имени' }}
                                    </div>
                                    <div class="text-xs truncate" :style="{ color: 'var(--wa-text-secondary)' }">
                                        {{ formatPhone(c.phone_number) || '' }}
                                    </div>
                                </div>
                            </button>
                            <div v-if="contacts.length === 0" class="text-sm px-2 py-3" :style="{ color: 'var(--wa-text-secondary)' }">
                                Контакты не найдены
                            </div>
                        </template>
                    </div>

                    <div class="mt-4 flex items-center justify-between gap-3">
                        <div class="text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                            Выбрано: {{ selectedCount }}
                        </div>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="px-4 py-2 rounded-xl hover:bg-[var(--wa-panel-hover)]"
                                :style="{ color: 'var(--wa-text)' }"
                                @click="emit('close')"
                            >
                                Отмена
                            </button>
                            <button
                                type="button"
                                class="px-4 py-2 rounded-xl"
                                :disabled="sending || selectedCount === 0 || (activeTab === 'clients' && (clientsTabDisabled || clientsNeedSessionPick))"
                                :style="{
                                    background: 'var(--wa-accent)',
                                    color: '#fff',
                                    opacity:
                                        sending || selectedCount === 0 || (activeTab === 'clients' && (clientsTabDisabled || clientsNeedSessionPick))
                                            ? 0.6
                                            : 1,
                                }"
                                @click="send"
                            >
                                Отправить
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </teleport>
</template>
