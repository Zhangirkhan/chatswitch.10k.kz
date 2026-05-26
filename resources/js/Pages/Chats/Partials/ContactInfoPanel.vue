<script setup lang="ts">
import { ref, computed, onBeforeUnmount, watch, nextTick } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import ContactCardSkeleton from '@/Components/Contact/ContactCardSkeleton.vue';
import ContactCrmSections, { type ContactCrmPayload } from '@/Components/Contact/ContactCrmSections.vue';
import EntityMemoryPanel from '@/Components/Memory/EntityMemoryPanel.vue';
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import type { Chat, Message } from '@/types';
import { formatPhone } from '@/utils/phone';
import { stripWaMarkup } from '@/utils/waMarkup';
import { appendChatListOwnership } from '@/utils/chatListOwnershipUrl';
import { useToastStore } from '@/stores/toast';

const { show: showToast } = useToastStore();

type AiStatus = {
    label: string;
    status: string;
    message: string;
    hint: string | null;
    knowledge_context: {
        rules: number;
        products: number;
        services: number;
    } | null;
    tone_source: {
        label: string;
        hint: string;
    } | null;
    orchestrator_history?: Array<{
        id: number;
        label: string;
        reason: string | null;
        target_stage: string | null;
        task_title: string | null;
        confidence: number | null;
        completed_at: string | null;
    }>;
};

type SidebarInsights = {
    events: Array<{
        id: number;
        title: string;
        starts_at: string | null;
        ends_at: string | null;
        assignee: string | null;
        source: string | null;
    }>;
    tasks: Array<{
        id: number;
        title: string;
        body: string | null;
        status: string;
        created_at: string | null;
    }>;
};

const props = defineProps<{
    chat: Chat;
    messages?: Message[];
    aiStatus?: AiStatus | null;
    sidebarInsights?: SidebarInsights;
    /**
     * Другие чаты этого же контакта на ЛЮБЫХ других WA-номерах.
     * Используется для UI «общались на WA #1 и WA #2» —
     * единая клиентская база при раздельных чатах.
     */
    contactChats?: Chat[];
    panelWidth?: string;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'open-search'): void;
    (e: 'open-ai'): void;
}>();

const page = usePage<any>();
const orgTasksEnabled = computed(() => Boolean(page.props.modules?.org_tasks ?? false));

function contactChatHref(chatId: number): string {
    return appendChatListOwnership(route('chats.show', chatId), page.props.listOwnership as string | undefined);
}

const working = ref(false);
const clearChatDialogOpen = ref(false);
const editOpen = ref(false);
const editName = ref('');
const savingContact = ref(false);
const saveError = ref<string | null>(null);
const quickActionLoading = ref<string | null>(null);

type ContactCardPayload = {
    identity: {
        display_name: string;
        saved_name: string | null;
        push_name: string | null;
        phone_number: string | null;
        whatsapp_ids: string[];
        profile_picture_url: string | null;
        is_business: boolean;
        possible_names: string[];
    };
    activity: {
        chats_count: number;
        channels_count: number;
        first_message_at: string | null;
        last_message_at: string | null;
        last_client_message: { id: number; body: string | null; sender_name: string | null; at: string | null } | null;
        last_operator_message: { id: number; body: string | null; sender_name: string | null; at: string | null } | null;
        messages: { total: number; inbound: number; outbound: number };
        attachments: { media: number; documents: number; links: number };
    };
    channels: Array<{
        chat_id: number;
        session_label: string;
        session_phone: string | null;
        session_status: string | null;
        chat_name: string | null;
        last_message_text: string | null;
        last_message_at: string | null;
        unread_count: number;
        is_archived: boolean;
        open_url: string;
    }>;
    crm: ContactCrmPayload;
};

const contactCard = ref<ContactCardPayload | null>(null);
const contactCardLoading = ref(false);
const contactCardError = ref<string | null>(null);

const displayName = computed(() =>
    contactCard.value?.identity.display_name
        || props.chat.chat_name
        || props.chat.contact?.name
        || props.chat.contact?.push_name
        || formatPhone(props.chat.contact?.phone_number)
        || 'Без имени',
);

// For group chats there is no "phone number" to display; showing a numeric WA group id is confusing.
const phoneLabel = computed(() => (isGroup.value ? '' : formatPhone(props.chat.contact?.phone_number)));

const funnelProgressPercent = computed(() => {
    const progress = props.chat.funnel_progress as { stage_index?: number; stages_count?: number } | null | undefined;
    const stageIndex = Number(progress?.stage_index ?? -1);
    const stagesCount = Number(progress?.stages_count ?? 0);
    if (stageIndex < 0 || stagesCount <= 0) {
        return 0;
    }

    return Math.round(((stageIndex + 1) / stagesCount) * 100);
});

const aiPanelTone = computed(() => {
    if (props.chat.ai_orchestrator_status === 'failed' || props.aiStatus?.status === 'failed') {
        return 'error';
    }
    if (props.chat.ai_orchestrator_status === 'needs_manager' || props.aiStatus?.status === 'blocked') {
        return 'warning';
    }
    if (props.chat.ai_orchestrator_status === 'running' || props.chat.ai_orchestrator_status === 'pending') {
        return 'busy';
    }
    if (props.aiStatus?.status === 'generating' || props.aiStatus?.status === 'pending') {
        return 'busy';
    }
    if (props.chat.ai_enabled) {
        return 'ready';
    }

    return 'idle';
});

const aiPanelStatusLabel = computed(() => {
    if (props.chat.ai_orchestrator_status === 'needs_manager') return 'Нужен менеджер';
    if (props.chat.ai_orchestrator_status === 'running' || props.chat.ai_orchestrator_status === 'pending') return 'AI ведёт сделку';
    if (props.chat.ai_orchestrator_status === 'failed') return 'Ошибка оркестратора';
    if (props.aiStatus?.label) return props.aiStatus.label;

    return props.chat.ai_enabled ? 'AI включён' : 'AI выключен';
});

const latestOrchestratorStep = computed(() => props.aiStatus?.orchestrator_history?.[0] ?? null);
const sidebarEvents = computed(() => props.sidebarInsights?.events ?? []);
const sidebarTasks = computed(() => props.sidebarInsights?.tasks ?? []);

async function requestManagerAttention(): Promise<void> {
    if (quickActionLoading.value) return;
    quickActionLoading.value = 'manager';
    try {
        await axios.post(route('chats.manager-attention', props.chat.id), {
            note: 'Оператор вручную передал чат менеджеру из панели клиента.',
        });
        await router.reload({ only: ['sidebarInsights', 'chat'] });
    } catch (e: any) {
        showToast({ message: e?.response?.data?.message || 'Не удалось передать чат менеджеру.', type: 'warning' });
    } finally {
        quickActionLoading.value = null;
    }
}

async function createQuickTask(): Promise<void> {
    if (quickActionLoading.value) return;
    quickActionLoading.value = 'task';
    try {
        await axios.post(route('chats.quick-task', props.chat.id), {
            title: 'Проверить следующий шаг по клиенту',
            body: 'Создано из правой панели чата. Проверьте переписку, статус воронки и следующий шаг.',
        });
        await router.reload({ only: ['sidebarInsights', 'chat'] });
    } catch (e: any) {
        showToast({ message: e?.response?.data?.message || 'Не удалось создать задачу.', type: 'warning' });
    } finally {
        quickActionLoading.value = null;
    }
}

const firstInitial = computed(() => (displayName.value || '?').charAt(0).toUpperCase());

async function loadContactCard() {
    if (!props.chat.contact_id) {
        contactCard.value = null;
        return;
    }
    contactCardLoading.value = true;
    contactCardError.value = null;
    try {
        const { data } = await axios.get(route('contacts.card', props.chat.contact_id), {
            params: { chat_id: props.chat.id },
        });
        contactCard.value = data as ContactCardPayload;
    } catch (e: any) {
        contactCard.value = null;
        contactCardError.value = e?.response?.data?.message || e?.response?.data?.error || 'Не удалось загрузить карточку контакта';
    } finally {
        contactCardLoading.value = false;
    }
}

function shortMessagePreview(message: ContactCardPayload['activity']['last_client_message']): string {
    const text = stripWaMarkup((message?.body || '').trim());
    return text !== '' ? text : '—';
}

/** Диалоги того же контакта на других WA-сессиях (исключая текущий чат). */
const otherSessionChats = computed<Chat[]>(() =>
    (props.contactChats || []).filter((c) => c.id !== props.chat.id && !c.is_group),
);

function formatChatTime(dateStr?: string | null): string {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    const now = new Date();
    if (d.toDateString() === now.toDateString()) {
        return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    }
    return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' });
}

function formatDateTime(dateStr?: string | null): string {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    if (Number.isNaN(d.getTime())) return '—';

    return d.toLocaleString('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const mediaCount = computed(() => {
    const list = props.messages || [];
    return list.filter((m: any) => m?.media || (m as any)?.media_id || m.type === 'image' || m.type === 'video' || m.type === 'document').length;
});

type ChatSharedMedia = {
    id: number;
    message_id: number;
    mime_type: string;
    filename: string | null;
    file_size: number | null;
    url: string;
    download_url: string;
    message_at: string | null;
    direction: 'inbound' | 'outbound' | 'system';
};

type ChatSharedLink = {
    id: string;
    message_id: number;
    url: string;
    host: string;
    title: string;
    message_at: string | null;
    direction: 'inbound' | 'outbound' | 'system';
};

type SharedPayload = {
    media: ChatSharedMedia[];
    links: ChatSharedLink[];
    documents: ChatSharedMedia[];
    counts: { media: number; links: number; documents: number; total: number };
};

const mediaBrowserOpen = ref(false);
const sharedLoading = ref(false);
const sharedError = ref<string | null>(null);
const sharedTab = ref<'media' | 'links' | 'documents'>('media');
const sharedPayload = ref<SharedPayload>({
    media: [],
    links: [],
    documents: [],
    counts: { media: 0, links: 0, documents: 0, total: 0 },
});

const sharedTotalCount = computed(() => sharedPayload.value.counts.total || mediaCount.value);

async function openMediaBrowser() {
    mediaBrowserOpen.value = true;
    if (sharedPayload.value.counts.total > 0 || sharedLoading.value) {
        return;
    }
    await loadSharedMedia();
}

function closeMediaBrowser() {
    mediaBrowserOpen.value = false;
}

async function loadSharedMedia() {
    sharedLoading.value = true;
    sharedError.value = null;
    try {
        const { data } = await axios.get(route('chats.media-links-documents', props.chat.id));
        sharedPayload.value = data as SharedPayload;
    } catch (e: any) {
        sharedError.value = e?.response?.data?.message || e?.response?.data?.error || 'Не удалось загрузить медиа';
    } finally {
        sharedLoading.value = false;
    }
}

function isImage(item: ChatSharedMedia): boolean {
    return item.mime_type.toLowerCase().startsWith('image/');
}

function isVideo(item: ChatSharedMedia): boolean {
    return item.mime_type.toLowerCase().startsWith('video/');
}

function formatFileSize(size?: number | null): string {
    if (!size || size <= 0) return '';
    if (size < 1024) return `${size} Б`;
    if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} КБ`;
    return `${(size / 1024 / 1024).toFixed(1)} МБ`;
}

function formatSharedDate(dateStr?: string | null): string {
    if (!dateStr) return '';
    return formatChatTime(dateStr);
}

function documentIconLabel(item: ChatSharedMedia): string {
    const fn = (item.filename || '').toLowerCase();
    const mt = item.mime_type.toLowerCase();
    if (mt.includes('pdf') || fn.endsWith('.pdf')) return 'PDF';
    if (mt.includes('word') || fn.endsWith('.doc') || fn.endsWith('.docx')) return 'DOC';
    if (mt.includes('excel') || fn.endsWith('.xls') || fn.endsWith('.xlsx')) return 'XLS';
    if (mt.startsWith('audio/')) return 'AUD';
    return 'FILE';
}

type GroupParticipant = {
    id: string;
    number: string | null;
    name: string | null;
    pushname: string | null;
    saved_name?: string | null;
    isBusiness: boolean;
    isAdmin: boolean;
    isSuperAdmin: boolean;
};

const isGroup = computed(() => !!props.chat.is_group);
const participantsLoading = ref(false);
const participantsError = ref<string | null>(null);
const participants = ref<GroupParticipant[]>([]);
const participantMenuOpen = ref(false);
const participantMenu = ref<{ x: number; y: number; p: GroupParticipant } | null>(null);
const participantSaving = ref(false);
const participantSaveError = ref<string | null>(null);
const participantName = ref('');

function participantLabel(p: GroupParticipant): string {
    if (p.saved_name) return p.saved_name.toString();
    if (p.pushname && p.pushname.trim()) return `~ ${p.pushname}`.toString();
    return (p.name || p.number || p.id).toString();
}

function participantInitial(p: GroupParticipant): string {
    const raw = participantLabel(p).replace(/^~\s*/, '').trim();
    return (raw || '?').charAt(0).toUpperCase();
}

async function loadParticipants() {
    if (!isGroup.value) return;
    participantsLoading.value = true;
    participantsError.value = null;
    try {
        const { data } = await axios.get(route('chats.group-participants', props.chat.id));
        participants.value = (data.participants || []) as GroupParticipant[];
    } catch (e: any) {
        participants.value = [];
        participantsError.value = e?.response?.data?.error || 'Не удалось загрузить участников';
    } finally {
        participantsLoading.value = false;
    }
}

function openParticipantMenu(p: GroupParticipant, e: MouseEvent) {
    e.preventDefault();
    e.stopPropagation();
    participantSaveError.value = null;
    participantName.value = (p.saved_name || p.name || p.pushname || '').toString();
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    const MENU_WIDTH = 280;
    const MENU_HEIGHT_EST = 300;
    let x = e.clientX;
    let y = e.clientY;
    if (x + MENU_WIDTH + 8 > vw) x = vw - MENU_WIDTH - 8;
    if (y + MENU_HEIGHT_EST + 8 > vh) y = Math.max(8, vh - MENU_HEIGHT_EST - 8);
    participantMenu.value = { x, y, p };
    participantMenuOpen.value = true;
    nextTick(() => {});
}

function closeParticipantMenu() {
    participantMenuOpen.value = false;
    participantMenu.value = null;
    participantSaveError.value = null;
}

async function addParticipantToContacts() {
    const p = participantMenu.value?.p;
    if (!p?.number) return;
    const name = participantName.value.trim();
    participantSaving.value = true;
    participantSaveError.value = null;
    try {
        await axios.post(route('contacts.upsert'), { phone: p.number, name: name || null });
        await loadParticipants();
        closeParticipantMenu();
    } catch (e: any) {
        participantSaveError.value = e?.response?.data?.message || e?.response?.data?.error || 'Не удалось добавить контакт';
    } finally {
        participantSaving.value = false;
    }
}

function writePrivately() {
    const p = participantMenu.value?.p;
    if (!p?.number) return;
    closeParticipantMenu();
    router.post(route('chats.start'), { phone: p.number, whatsapp_session_id: props.chat.whatsapp_session_id }, {
        onFinish: () => emit('close'),
    });
}

watch(
    () => props.chat.id,
    () => {
        participants.value = [];
        participantsError.value = null;
        mediaBrowserOpen.value = false;
        sharedTab.value = 'media';
        sharedPayload.value = {
            media: [],
            links: [],
            documents: [],
            counts: { media: 0, links: 0, documents: 0, total: 0 },
        };
        sharedError.value = null;
        contactCard.value = null;
        contactCardError.value = null;
        loadContactCard();
        if (isGroup.value) loadParticipants();
    },
    { immediate: true },
);

function onEscape(e: KeyboardEvent) {
    if (e.key !== 'Escape') return;
    if (clearChatDialogOpen.value) {
        clearChatDialogOpen.value = false;
        return;
    }
    if (participantMenuOpen.value) {
        closeParticipantMenu();
        return;
    }
    if (mediaBrowserOpen.value) {
        closeMediaBrowser();
        return;
    }
    emit('close');
}
window.addEventListener('keydown', onEscape);
onBeforeUnmount(() => window.removeEventListener('keydown', onEscape));

async function togglePin() {
    if (working.value) return;
    working.value = true;
    try {
        await axios.post(route('chats.toggle-pin', props.chat.id));
        router.reload({ only: ['chat', 'chats', 'unreadChatsCount', 'unreadChatsCountMine', 'listOwnership', 'mineChatsTotal'] });
    } finally {
        working.value = false;
    }
}

function openClearChatDialog(): void {
    clearChatDialogOpen.value = true;
}

async function confirmClearChat(): Promise<void> {
    if (working.value) return;
    clearChatDialogOpen.value = false;
    working.value = true;
    try {
        await axios.post(route('chats.clear', props.chat.id));
        router.reload({ only: ['messages', 'chat', 'unreadChatsCount'] });
    } finally {
        working.value = false;
    }
}

function notImplemented(name: string) {
    showToast({ message: `«${name}» — скоро будет доступно.`, type: 'info' });
}

function preferredEditableName(): string {
    // Приоритет: то, что отображается в шапке чата (`chat_name`), должно также
    // подставляться в модалку редактирования, иначе вы увидите “старое” значение,
    // если contact.name не успел синхронизироваться на фронте.
    return (props.chat.chat_name || props.chat.contact?.name || props.chat.contact?.push_name || '').trim();
}

function openEdit() {
    saveError.value = null;
    editName.value = preferredEditableName();
    editOpen.value = true;
}

function closeEdit() {
    editOpen.value = false;
    editName.value = '';
    saveError.value = null;
}

async function saveContactName() {
    if (savingContact.value) return;
    saveError.value = null;
    const name = editName.value.trim();
    if (!name) {
        saveError.value = 'Введите имя контакта';
        return;
    }
    savingContact.value = true;
    try {
        await axios.post(route('chats.save-contact', props.chat.id), { name });
        closeEdit();
        router.reload({ only: ['chat', 'chats', 'messages', 'unreadChatsCount', 'unreadChatsCountMine', 'listOwnership', 'mineChatsTotal'] });
    } catch (e: any) {
        saveError.value = e?.response?.data?.message || e?.response?.data?.error || 'Не удалось сохранить контакт';
    } finally {
        savingContact.value = false;
    }
}
</script>

<template>
    <aside
        class="shrink-0 h-full flex flex-col border-l overflow-hidden"
        :style="{
            width: props.panelWidth ?? '400px',
            background: 'var(--wa-panel)',
            borderColor: 'var(--wa-border)',
        }"
    >
        <!-- Header -->
        <div
            class="h-[60px] px-4 flex items-center gap-5 shrink-0 min-w-0 isolate"
            :style="{ background: 'var(--wa-panel-header)' }"
        >
            <button
                @click="emit('close')"
                class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)]"
                title="Закрыть"
                type="button"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 class="text-base flex-1 min-w-0 truncate font-medium" :style="{ color: 'var(--wa-text)' }">
                Данные контакта
            </h2>
            <button
                @click="openEdit"
                class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)]"
                :title="chat.contact?.name ? 'Редактировать контакт' : 'Добавить в контакты'"
                type="button"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
            </button>
        </div>

        <!-- Edit contact modal -->
        <teleport to="body">
            <div
                v-if="editOpen"
                class="fixed inset-0 z-[200] flex items-center justify-center px-4"
                :style="{ background: 'rgba(0,0,0,.45)' }"
                @click.self="closeEdit"
            >
                <div class="w-full max-w-[420px] rounded-2xl border overflow-hidden"
                     :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
                >
                    <div class="px-5 py-4 flex items-center justify-between"
                         :style="{ background: 'var(--wa-panel-header)' }"
                    >
                        <div class="text-sm font-medium" :style="{ color: 'var(--wa-text)' }">
                            {{ chat.contact?.name ? 'Редактировать контакт' : 'Добавить контакт' }}
                        </div>
                        <button type="button" class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)]" @click="closeEdit">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div class="p-5">
                        <label class="block text-xs mb-2" :style="{ color: 'var(--wa-text-secondary)' }">Имя контакта</label>
                        <input
                            v-model="editName"
                            type="text"
                            class="w-full px-4 py-2.5 rounded-xl border-0 focus:ring-0 focus:outline-none"
                            :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-text)' }"
                            placeholder="Например: Айбек"
                            @keydown.enter.prevent="saveContactName"
                        />
                        <div v-if="saveError" class="text-xs mt-2" style="color: #ff6b6b;">
                            {{ saveError }}
                        </div>
                        <div class="mt-4 flex gap-2 justify-end">
                            <button type="button" class="px-4 py-2 rounded-xl hover:bg-[var(--wa-panel-hover)]"
                                    :style="{ color: 'var(--wa-text)' }"
                                    @click="closeEdit"
                            >
                                Отмена
                            </button>
                            <button
                                type="button"
                                class="px-4 py-2 rounded-xl"
                                :disabled="savingContact"
                                :style="{ background: 'var(--wa-accent)', color: 'white', opacity: savingContact ? 0.7 : 1 }"
                                @click="saveContactName"
                            >
                                Сохранить
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </teleport>

        <!-- Media / links / documents browser -->
        <div v-if="mediaBrowserOpen" class="flex-1 overflow-y-auto wa-scrollbar">
            <div class="h-[60px] px-4 flex items-center gap-4 sticky top-0 z-10" :style="{ background: 'var(--wa-panel-header)' }">
                <button
                    type="button"
                    class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)]"
                    title="Назад"
                    @click="closeMediaBrowser"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <div class="min-w-0 flex-1">
                    <div class="text-base truncate" :style="{ color: 'var(--wa-text)' }">Медиа, ссылки и документы</div>
                    <div class="text-xs" :style="{ color: 'var(--wa-text-secondary)' }">{{ sharedTotalCount }} элементов</div>
                </div>
            </div>

            <div class="px-4 py-3">
                <div class="grid grid-cols-3 gap-1 rounded-xl p-1" :style="{ background: 'var(--wa-panel-header)' }">
                    <button
                        type="button"
                        class="shared-tab"
                        :class="{ 'shared-tab-active': sharedTab === 'media' }"
                        @click="sharedTab = 'media'"
                    >
                        Медиа {{ sharedPayload.counts.media || 0 }}
                    </button>
                    <button
                        type="button"
                        class="shared-tab"
                        :class="{ 'shared-tab-active': sharedTab === 'links' }"
                        @click="sharedTab = 'links'"
                    >
                        Ссылки {{ sharedPayload.counts.links || 0 }}
                    </button>
                    <button
                        type="button"
                        class="shared-tab"
                        :class="{ 'shared-tab-active': sharedTab === 'documents' }"
                        @click="sharedTab = 'documents'"
                    >
                        Документы {{ sharedPayload.counts.documents || 0 }}
                    </button>
                </div>
            </div>

            <div v-if="sharedLoading" class="px-4 py-10 text-center text-sm" :style="{ color: 'var(--wa-text-secondary)' }">
                Загрузка…
            </div>
            <div v-else-if="sharedError" class="px-4 py-6 text-sm" :style="{ color: 'var(--wa-danger)' }">
                {{ sharedError }}
                <button type="button" class="block mt-3 underline" @click="loadSharedMedia">Повторить</button>
            </div>

            <div v-else class="px-4 pb-6">
                <div v-if="sharedTab === 'media'">
                    <div v-if="sharedPayload.media.length === 0" class="shared-empty">
                        В этом чате пока нет фото или видео.
                    </div>
                    <div v-else class="grid grid-cols-3 gap-1">
                        <a
                            v-for="item in sharedPayload.media"
                            :key="item.id"
                            :href="item.url"
                            target="_blank"
                            rel="noopener"
                            class="shared-media-tile"
                            :title="item.filename || item.mime_type"
                        >
                            <img
                                v-if="isImage(item)"
                                :src="item.url"
                                class="w-full h-full object-cover"
                                alt=""
                            />
                            <video
                                v-else-if="isVideo(item)"
                                :src="item.url"
                                class="w-full h-full object-cover"
                                muted
                                preload="metadata"
                            ></video>
                            <div v-else class="shared-media-fallback">{{ documentIconLabel(item) }}</div>
                            <span v-if="isVideo(item)" class="shared-media-play">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z" /></svg>
                            </span>
                        </a>
                    </div>
                </div>

                <div v-else-if="sharedTab === 'links'">
                    <div v-if="sharedPayload.links.length === 0" class="shared-empty">
                        В этом чате пока нет ссылок.
                    </div>
                    <div v-else class="space-y-2">
                        <a
                            v-for="item in sharedPayload.links"
                            :key="item.id"
                            :href="item.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="shared-list-row"
                        >
                            <div class="shared-link-icon">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 010 5.656l-1.414 1.414a4 4 0 01-5.657-5.657l1.414-1.414m2.829 2.829a4 4 0 010-5.657l1.414-1.414a4 4 0 015.657 5.657l-1.414 1.414" />
                                </svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="shared-row-title truncate">{{ item.host }}</div>
                                <div class="shared-row-subtitle truncate">{{ item.url }}</div>
                                <div class="shared-row-date">{{ formatSharedDate(item.message_at) }}</div>
                            </div>
                        </a>
                    </div>
                </div>

                <div v-else>
                    <div v-if="sharedPayload.documents.length === 0" class="shared-empty">
                        В этом чате пока нет документов.
                    </div>
                    <div v-else class="space-y-2">
                        <a
                            v-for="item in sharedPayload.documents"
                            :key="item.id"
                            :href="item.download_url"
                            class="shared-list-row"
                        >
                            <div class="shared-doc-icon">{{ documentIconLabel(item) }}</div>
                            <div class="min-w-0 flex-1">
                                <div class="shared-row-title truncate">{{ item.filename || 'Документ' }}</div>
                                <div class="shared-row-subtitle">
                                    {{ item.mime_type }}<span v-if="formatFileSize(item.file_size)"> · {{ formatFileSize(item.file_size) }}</span>
                                </div>
                                <div class="shared-row-date">{{ formatSharedDate(item.message_at) }}</div>
                            </div>
                            <svg class="w-4 h-4 shrink-0" :style="{ color: 'var(--wa-text-secondary)' }" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16" />
                            </svg>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Scrollable content -->
        <div v-else class="flex-1 overflow-y-auto wa-scrollbar">
            <!-- Avatar header -->
            <div class="py-8 flex flex-col items-center px-6">
                <div
                    v-if="chat.contact?.profile_picture_url"
                    class="w-[200px] h-[200px] rounded-full overflow-hidden mb-5 bg-[#6b7c85]"
                >
                    <img
                        :src="chat.contact.profile_picture_url"
                        class="w-full h-full object-cover"
                        alt=""
                    />
                </div>
                <div
                    v-else
                    class="w-[200px] h-[200px] rounded-full flex items-center justify-center text-white text-[80px] font-medium mb-5"
                    :class="chat.is_group ? 'bg-[var(--wa-accent)]' : 'bg-[#6b7c85]'"
                >
                    {{ firstInitial }}
                </div>
                <div class="text-[26px] text-center" :style="{ color: 'var(--wa-text)' }">
                    {{ displayName }}
                </div>
                <div
                    v-if="phoneLabel"
                    class="text-sm mt-1"
                    :style="{ color: 'var(--wa-text-secondary)' }"
                >
                    {{ phoneLabel }}
                </div>
            </div>

            <div class="px-4 pb-4">
                <div class="deal-card" :class="`deal-card-${aiPanelTone}`">
                    <div class="deal-card__head">
                        <div>
                            <div class="deal-card__eyebrow">AI и воронка</div>
                            <div class="deal-card__title">{{ aiPanelStatusLabel }}</div>
                        </div>
                        <span class="deal-card__badge">{{ funnelProgressPercent }}%</span>
                    </div>

                    <div class="deal-card__meter" aria-hidden="true">
                        <span :style="{ width: `${funnelProgressPercent}%`, background: chat.funnel_stage?.color || chat.funnel?.color || 'var(--wa-accent)' }"></span>
                    </div>

                    <div class="deal-card__facts">
                        <div>
                            <span>Воронка</span>
                            <strong>{{ chat.funnel?.name || 'Не выбрана' }}</strong>
                        </div>
                        <div>
                            <span>Этап</span>
                            <strong>{{ chat.funnel_stage?.name || 'Не выбран' }}</strong>
                        </div>
                        <div>
                            <span>Режим AI</span>
                            <strong>{{ chat.ai_enabled ? (chat.ai_mode === 'draft' ? 'Черновик' : 'Автоответ') : 'Выключен' }}</strong>
                        </div>
                    </div>

                    <div v-if="chat.ai_orchestrator_last_summary || latestOrchestratorStep" class="deal-card__note">
                        {{ latestOrchestratorStep?.reason || chat.ai_orchestrator_last_summary }}
                    </div>
                    <div v-if="latestOrchestratorStep?.target_stage || latestOrchestratorStep?.task_title" class="deal-card__chips">
                        <span v-if="latestOrchestratorStep?.target_stage">Этап: {{ latestOrchestratorStep.target_stage }}</span>
                        <span v-if="latestOrchestratorStep?.task_title">Задача: {{ latestOrchestratorStep.task_title }}</span>
                    </div>
                </div>
            </div>

            <!-- Auto contact card -->
            <div v-if="!isGroup" class="px-4 pb-4">
                <div class="contact-card">
                    <div class="contact-card__head">
                        <div>
                            <div class="contact-card__title">Карточка контакта</div>
                            <div class="contact-card__subtitle">Автоматически собрано из диалогов</div>
                        </div>
                        <button
                            type="button"
                            class="contact-card__refresh"
                            :disabled="contactCardLoading"
                            title="Обновить"
                            @click="loadContactCard"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v6h6M20 20v-6h-6M5 19A9 9 0 0019 5M19 5h-5M5 19h5" />
                            </svg>
                        </button>
                    </div>

                    <ContactCardSkeleton v-if="contactCardLoading" :show-deal="false" />
                    <div v-else-if="contactCardError" class="contact-card__error">{{ contactCardError }}</div>
                    <div v-else-if="contactCard" class="space-y-3">
                        <div class="contact-card__grid">
                            <div>
                                <div class="contact-card__label">Чатов</div>
                                <div class="contact-card__value">{{ contactCard.activity.chats_count }}</div>
                            </div>
                            <div>
                                <div class="contact-card__label">Каналов</div>
                                <div class="contact-card__value">{{ contactCard.activity.channels_count }}</div>
                            </div>
                            <div>
                                <div class="contact-card__label">Сообщений</div>
                                <div class="contact-card__value">{{ contactCard.activity.messages.total }}</div>
                            </div>
                            <div>
                                <div class="contact-card__label">Вложения</div>
                                <div class="contact-card__value">
                                    {{ contactCard.activity.attachments.media + contactCard.activity.attachments.documents + contactCard.activity.attachments.links }}
                                </div>
                            </div>
                        </div>

                        <div class="contact-card__facts">
                            <div>
                                <span>Первое сообщение:</span>
                                {{ formatChatTime(contactCard.activity.first_message_at) || '—' }}
                            </div>
                            <div>
                                <span>Последняя активность:</span>
                                {{ formatChatTime(contactCard.activity.last_message_at) || '—' }}
                            </div>
                            <div>
                                <span>От клиента:</span>
                                {{ contactCard.activity.messages.inbound }}
                            </div>
                            <div>
                                <span>От операторов:</span>
                                {{ contactCard.activity.messages.outbound }}
                            </div>
                        </div>

                        <div v-if="contactCard.activity.last_client_message" class="contact-card__last">
                            <div class="contact-card__label">Последняя реплика клиента</div>
                            <div class="contact-card__last-text">{{ shortMessagePreview(contactCard.activity.last_client_message) }}</div>
                        </div>

                        <div v-if="contactCard.identity.possible_names.length > 1" class="contact-card__chips">
                            <span
                                v-for="name in contactCard.identity.possible_names.slice(0, 5)"
                                :key="name"
                                class="contact-card__chip"
                            >
                                {{ name }}
                            </span>
                        </div>

                        <ContactCrmSections
                            v-if="contactCard.crm"
                            :crm="contactCard.crm"
                            :current-chat-id="chat.id"
                        />

                        <EntityMemoryPanel
                            v-if="chat.contact_id"
                            subject-type="contact"
                            :subject-id="chat.contact_id"
                            compact
                            class="mt-3"
                        />
                    </div>
                    <div v-else class="contact-card__muted">Нет данных для карточки.</div>
                </div>
            </div>

            <!-- Куда клиент писал: список WA-номеров, через которые велась переписка.
                 Показывается только если клиент общался более чем на одной сессии. -->
            <div v-if="otherSessionChats.length" class="px-4 pb-4">
                <div class="flex items-center gap-2 mb-2">
                    <svg class="w-4 h-4" :style="{ color: 'var(--wa-text-secondary)' }" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 5h2l3.6 7.59-1.35 2.44c-.15.27-.24.58-.24.92 0 1.05.94 1.96 2 1.96h12V16H8.42c-.13 0-.24-.11-.24-.24l.03-.12.9-1.64h7.45c.75 0 1.4-.41 1.75-1.03l3.58-6.5A1 1 0 0020.01 5H5.21L4.27 3H1v2h2zM7 20a2 2 0 104 0 2 2 0 00-4 0zm10 0a2 2 0 104 0 2 2 0 00-4 0z" />
                    </svg>
                    <span class="text-sm" :style="{ color: 'var(--wa-text-secondary)' }">
                        Этот клиент общался с вами на других номерах
                    </span>
                </div>
                <div class="flex flex-col gap-1.5">
                    <Link
                        v-for="c in otherSessionChats"
                        :key="c.id"
                        :href="contactChatHref(c.id)"
                        class="sibling-row"
                    >
                        <span
                            class="w-2 h-2 rounded-full shrink-0"
                            :class="c.whatsapp_session?.status === 'connected' ? 'bg-[var(--wa-accent)]' : 'bg-gray-400'"
                        ></span>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-1.5">
                                <span class="sibling-name truncate">
                                    {{ c.whatsapp_session?.display_name || c.whatsapp_session?.session_name }}
                                </span>
                                <span
                                    v-if="c.whatsapp_session?.phone_number"
                                    class="text-[11px] tabular-nums"
                                    :style="{ color: 'var(--wa-text-secondary)' }"
                                >
                                    {{ formatPhone(c.whatsapp_session.phone_number) }}
                                </span>
                            </div>
                            <div v-if="c.last_message_text" class="sibling-preview truncate">
                                {{ stripWaMarkup(c.last_message_text) }}
                            </div>
                        </div>
                        <div class="flex flex-col items-end shrink-0 gap-1">
                            <span class="text-[11px]" :style="{ color: 'var(--wa-text-secondary)' }">
                                {{ formatChatTime(c.last_message_at) }}
                            </span>
                            <span
                                v-if="c.unread_count && c.unread_count > 0"
                                class="min-w-[18px] h-[18px] rounded-full text-[10px] font-semibold flex items-center justify-center px-1"
                                :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                            >
                                {{ c.unread_count > 99 ? '99+' : c.unread_count }}
                            </span>
                        </div>
                    </Link>
                </div>
            </div>

            <div class="px-4 pb-4">
                <div class="quick-actions-card">
                    <div class="quick-actions-card__title">Быстрые действия</div>
                    <div class="quick-actions-card__grid">
                        <button type="button" class="quick-action-btn" @click="emit('open-ai')">
                            Открыть AI
                        </button>
                        <button
                            type="button"
                            class="quick-action-btn"
                            :disabled="quickActionLoading !== null"
                            @click="requestManagerAttention"
                        >
                            {{ quickActionLoading === 'manager' ? 'Передаём…' : 'Передать менеджеру' }}
                        </button>
                        <button
                            v-if="orgTasksEnabled"
                            type="button"
                            class="quick-action-btn quick-action-btn-primary"
                            :disabled="quickActionLoading !== null"
                            @click="createQuickTask"
                        >
                            {{ quickActionLoading === 'task' ? 'Создаём…' : 'Создать задачу' }}
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="sidebarEvents.length || sidebarTasks.length" class="px-4 pb-4">
                <div class="ops-card">
                    <div class="ops-card__head">
                        <div>
                            <div class="ops-card__title">Операционный контекст</div>
                            <div class="ops-card__subtitle">События календаря и задачи AI по этому чату</div>
                        </div>
                    </div>

                    <div v-if="sidebarEvents.length" class="ops-card__section">
                        <div class="ops-card__section-title">Календарь</div>
                        <div class="ops-card__list">
                            <div v-for="event in sidebarEvents" :key="`event-${event.id}`" class="ops-card__row">
                                <div class="ops-card__row-main">
                                    <div class="ops-card__row-title">{{ event.title }}</div>
                                    <div class="ops-card__row-meta">
                                        {{ formatDateTime(event.starts_at) }}<span v-if="event.assignee"> · {{ event.assignee }}</span>
                                    </div>
                                </div>
                                <span v-if="event.source === 'ai_auto'" class="ops-card__tag">AI</span>
                            </div>
                        </div>
                    </div>

                    <div v-if="sidebarTasks.length" class="ops-card__section">
                        <div class="ops-card__section-title">Задачи AI</div>
                        <div class="ops-card__list">
                            <div v-for="task in sidebarTasks" :key="`task-${task.id}`" class="ops-card__row ops-card__row-task">
                                <div class="ops-card__row-main">
                                    <div class="ops-card__row-title">{{ task.title }}</div>
                                    <div v-if="task.body" class="ops-card__row-body">{{ task.body }}</div>
                                    <div class="ops-card__row-meta">{{ formatDateTime(task.created_at) }} · {{ task.status }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="px-4 pb-4">
                <button class="action-tile w-full" type="button" @click="emit('open-search')">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <span>Поиск</span>
                </button>
            </div>

            <div class="h-2" :style="{ background: 'var(--wa-bg)' }"></div>

            <!-- Group participants -->
            <div v-if="isGroup" class="py-2">
                <div class="px-4 pt-3 pb-2 text-xs uppercase tracking-wide" :style="{ color: 'var(--wa-text-secondary)' }">
                    Участники
                </div>

                <div v-if="participantsLoading" class="px-4 pb-4 text-sm" :style="{ color: 'var(--wa-text-secondary)' }">
                    Загрузка…
                </div>
                <div v-else-if="participantsError" class="px-4 pb-4 text-sm" :style="{ color: 'var(--wa-danger)' }">
                    {{ participantsError }}
                </div>
                <div v-else class="pb-2">
                    <button
                        v-for="p in participants"
                        :key="p.id"
                        class="info-row w-full"
                        type="button"
                        @click="openParticipantMenu(p, $event)"
                    >
                        <svg class="info-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m6-4a4 4 0 10-8 0 4 4 0 008 0zm6 1a3 3 0 10-6 0 3 3 0 006 0z" />
                        </svg>
                        <div class="flex-1 min-w-0 text-left">
                            <div class="info-label-inline">
                                {{ participantLabel(p) }}
                                <span v-if="p.isSuperAdmin" class="ml-2 text-[11px]" :style="{ color: 'var(--wa-accent)' }">владелец</span>
                                <span v-else-if="p.isAdmin" class="ml-2 text-[11px]" :style="{ color: 'var(--wa-accent)' }">админ</span>
                            </div>
                            <div v-if="p.number" class="info-sublabel">{{ p.number }}</div>
                        </div>
                    </button>

                    <div v-if="participants.length === 0" class="px-4 pb-4 text-sm" :style="{ color: 'var(--wa-text-secondary)' }">
                        Участники не найдены
                    </div>
                </div>
            </div>

            <div class="h-2" :style="{ background: 'var(--wa-bg)' }"></div>

            <!-- Info rows -->
            <div class="py-2">
                <button class="info-row w-full" @click="openMediaBrowser" type="button">
                    <svg class="info-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    <span class="info-label">Медиа, ссылки и документы</span>
                    <span class="info-meta">
                        {{ sharedTotalCount }}
                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                        </svg>
                    </span>
                </button>

                <button class="info-row w-full" @click="notImplemented('Избранные сообщения')" type="button">
                    <svg class="info-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.539 1.118L12 16.98l-3.976 2.888c-.784.57-1.838-.196-1.539-1.118l1.518-4.674a1 1 0 00-.363-1.118L3.664 10.1c-.783-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.673z" />
                    </svg>
                    <span class="info-label">Избранные</span>
                </button>

            </div>

            <div class="h-2" :style="{ background: 'var(--wa-bg)' }"></div>

            <!-- Pin & list -->
            <div class="py-2">
                <button class="info-row w-full" @click="togglePin" type="button" :disabled="working">
                    <svg class="info-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                    </svg>
                    <span class="info-label">
                        {{ chat.is_pinned ? 'Убрать из избранного' : 'Добавить в избранное' }}
                    </span>
                </button>

                <button class="info-row w-full" @click="notImplemented('Добавить в список')" type="button">
                    <svg class="info-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h10M4 18h10M19 15v6m-3-3h6" />
                    </svg>
                    <span class="info-label">Добавить в список</span>
                </button>

                <button class="info-row w-full" type="button" :disabled="working" @click="openClearChatDialog">
                    <svg class="info-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728L5.636 5.636m12.728 12.728A9 9 0 015.636 5.636" />
                    </svg>
                    <span class="info-label">Очистить историю чата</span>
                </button>
            </div>

        </div>
    </aside>

    <DangerConfirmModal
        :open="clearChatDialogOpen"
        title="Очистить историю чата?"
        description="Все сообщения в этом чате будут удалены. Действие необратимо."
        confirm-label="Очистить"
        :busy="working"
        confirm-variant="danger"
        @close="clearChatDialogOpen = false"
        @confirm="confirmClearChat"
    />

    <!-- Participant actions menu -->
    <teleport to="body">
        <div v-if="participantMenuOpen && participantMenu">
            <div class="fixed inset-0 z-40" @click="closeParticipantMenu" @contextmenu.prevent="closeParticipantMenu"></div>
            <div
                class="participant-popover fixed z-50 w-[min(280px,calc(100vw-16px))] max-w-[280px] rounded-xl shadow-2xl border overflow-hidden"
                :style="{
                    left: participantMenu.x + 'px',
                    top: participantMenu.y + 'px',
                    background: 'var(--wa-panel-header)',
                    borderColor: 'var(--wa-control-rim)', boxShadow: 'var(--wa-control-rim-shadow)',
                }"
            >
                <div class="participant-popover__header">
                    <div class="participant-popover__avatar" aria-hidden="true">
                        {{ participantInitial(participantMenu.p) }}
                    </div>
                    <div class="participant-popover__head-text">
                        <div class="participant-popover__title">
                            {{ participantLabel(participantMenu.p) }}
                        </div>
                        <div v-if="participantMenu.p.number" class="participant-popover__subtitle tabular-nums">
                            {{ formatPhone(participantMenu.p.number) }}
                        </div>
                        <div v-else class="participant-popover__subtitle">
                            Номер недоступен
                        </div>
                    </div>
                </div>

                <div class="participant-popover__divider" role="presentation"></div>

                <div class="participant-popover__field-block">
                    <label class="participant-popover__label" for="participant-save-name">
                        Имя для сохранения
                    </label>
                    <input
                        id="participant-save-name"
                        v-model="participantName"
                        type="text"
                        class="participant-popover__input"
                        :style="{ background: 'var(--wa-panel)', color: 'var(--wa-text)' }"
                        placeholder="Как сохранить в контактах…"
                        autocomplete="off"
                    />
                    <div v-if="participantSaveError" class="participant-popover__error">
                        {{ participantSaveError }}
                    </div>
                </div>

                <div class="participant-popover__divider" role="presentation"></div>

                <div class="participant-popover__actions">
                    <button
                        class="participant-popover__row"
                        type="button"
                        :disabled="participantSaving || !participantMenu.p.number"
                        @click="addParticipantToContacts"
                    >
                        <svg class="participant-popover__row-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                        </svg>
                        <span class="participant-popover__row-label">Добавить в контакты</span>
                    </button>

                    <button
                        class="participant-popover__row"
                        type="button"
                        :disabled="!participantMenu.p.number"
                        @click="writePrivately"
                    >
                        <svg class="participant-popover__row-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5m7 7l-3.5-3.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span class="participant-popover__row-label">Написать лично</span>
                    </button>
                </div>
            </div>
        </div>
    </teleport>
</template>

<style scoped>
.action-tile {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.375rem;
    padding: 0.75rem 0.5rem;
    border-radius: 0.5rem;
    font-size: 0.8125rem;
    color: var(--wa-accent);
    background-color: var(--wa-panel-header);
    border: 1px solid var(--wa-border);
    transition: background-color 0.15s ease;
}
.action-tile:hover {
    background-color: var(--wa-panel-hover);
}
.info-row {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    padding: 0.875rem 1.25rem;
    text-align: left;
    color: var(--wa-text);
    transition: background-color 0.12s ease;
}
.info-row:not(:disabled):hover {
    background-color: var(--wa-panel-hover);
}
.info-row:disabled {
    cursor: not-allowed;
    opacity: 0.7;
}
.info-icon {
    width: 1.25rem;
    height: 1.25rem;
    color: var(--wa-text-secondary);
    flex-shrink: 0;
}
.info-label {
    flex: 1;
    font-size: 0.9375rem;
    min-width: 0;
    text-align: left;
}
.info-label-inline {
    font-size: 0.9375rem;
}
.info-sublabel {
    font-size: 0.8125rem;
    color: var(--wa-text-secondary);
    margin-top: 0.125rem;
}
.info-meta {
    display: inline-flex;
    align-items: center;
    font-size: 0.875rem;
    color: var(--wa-text-secondary);
}
.info-row-danger {
    color: #ef4444;
}
.info-row-danger .info-icon {
    color: #ef4444;
}
.info-row-danger:hover {
    background-color: rgba(239, 68, 68, 0.08);
}
.contact-card {
    border-radius: 0.875rem;
    border: 1px solid var(--wa-border);
    background: var(--wa-panel-header);
    padding: 0.875rem;
}
.deal-card {
    border: 1px solid var(--wa-border);
    border-radius: 1.125rem;
    padding: 0.875rem;
    background: color-mix(in srgb, var(--wa-panel-header) 82%, var(--wa-panel) 18%);
}
.deal-card-ready {
    border-color: color-mix(in srgb, var(--wa-accent) 38%, var(--wa-border));
}
.deal-card-busy {
    border-color: color-mix(in srgb, #8b5cf6 42%, var(--wa-border));
}
.deal-card-warning {
    border-color: color-mix(in srgb, #f59e0b 48%, var(--wa-border));
}
.deal-card-error {
    border-color: color-mix(in srgb, var(--wa-danger) 48%, var(--wa-border));
}
.deal-card__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
}
.deal-card__eyebrow {
    font-size: 0.6875rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--wa-accent);
}
.deal-card__title {
    margin-top: 0.125rem;
    font-size: 0.9375rem;
    font-weight: 650;
    color: var(--wa-text);
}
.deal-card__badge {
    border-radius: 999px;
    padding: 0.1875rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--wa-text);
    background: color-mix(in srgb, var(--wa-accent) 14%, transparent);
}
.deal-card__meter {
    height: 0.4375rem;
    overflow: hidden;
    border-radius: 999px;
    margin-top: 0.75rem;
    background: color-mix(in srgb, var(--wa-text-secondary) 18%, transparent);
}
.deal-card__meter > span {
    display: block;
    height: 100%;
    border-radius: inherit;
    transition: width .25s ease;
}
.deal-card__facts {
    display: grid;
    gap: 0.5rem;
    margin-top: 0.75rem;
}
.deal-card__facts div {
    display: flex;
    justify-content: space-between;
    gap: 0.75rem;
    font-size: 0.75rem;
}
.deal-card__facts span {
    color: var(--wa-text-secondary);
}
.deal-card__facts strong {
    min-width: 0;
    max-width: 13rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--wa-text);
    font-weight: 650;
}
.deal-card__note {
    margin-top: 0.75rem;
    border-radius: 0.75rem;
    padding: 0.5625rem 0.625rem;
    font-size: 0.75rem;
    line-height: 1.45;
    color: var(--wa-text-secondary);
    background: var(--wa-panel);
}
.deal-card__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 0.375rem;
    margin-top: 0.625rem;
}
.deal-card__chips span {
    border-radius: 999px;
    padding: 0.25rem 0.5rem;
    font-size: 0.6875rem;
    color: var(--wa-text);
    background: var(--wa-panel);
}
.ops-card {
    border-radius: 1.125rem;
    border: 1px solid var(--wa-border);
    background: var(--wa-panel-header);
    padding: 0.875rem;
}
.quick-actions-card {
    border-radius: 1.125rem;
    border: 1px solid var(--wa-border);
    background: var(--wa-panel-header);
    padding: 0.875rem;
}
.quick-actions-card__title {
    margin-bottom: 0.625rem;
    font-size: 0.875rem;
    font-weight: 650;
    color: var(--wa-text);
}
.quick-actions-card__grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0.5rem;
}
.quick-action-btn {
    border-radius: 0.75rem;
    border: 1px solid var(--wa-border);
    padding: 0.625rem 0.75rem;
    font-size: 0.8125rem;
    font-weight: 650;
    color: var(--wa-text);
    background: var(--wa-panel);
    transition: background-color .15s ease, transform .15s ease;
}
.quick-action-btn:hover:not(:disabled) {
    background: var(--wa-panel-hover);
    transform: translateY(-1px);
}
.quick-action-btn:disabled {
    opacity: .6;
    cursor: default;
}
.quick-action-btn-primary {
    border-color: color-mix(in srgb, var(--wa-accent) 45%, var(--wa-border));
    color: #fff;
    background: var(--wa-accent);
}
.quick-action-btn-primary:hover:not(:disabled) {
    background: var(--wa-accent-hover);
}
.ops-card__head {
    display: flex;
    justify-content: space-between;
    gap: 0.75rem;
}
.ops-card__title {
    font-size: 0.875rem;
    font-weight: 650;
    color: var(--wa-text);
}
.ops-card__subtitle {
    margin-top: 0.125rem;
    font-size: 0.75rem;
    color: var(--wa-text-secondary);
}
.ops-card__section {
    margin-top: 0.875rem;
}
.ops-card__section-title {
    margin-bottom: 0.375rem;
    font-size: 0.6875rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--wa-text-secondary);
}
.ops-card__list {
    display: grid;
    gap: 0.5rem;
}
.ops-card__row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    border-radius: 0.75rem;
    padding: 0.625rem;
    background: var(--wa-panel);
}
.ops-card__row-title {
    font-size: 0.8125rem;
    font-weight: 650;
    color: var(--wa-text);
}
.ops-card__row-body {
    margin-top: 0.25rem;
    display: -webkit-box;
    overflow: hidden;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    font-size: 0.75rem;
    line-height: 1.35;
    color: var(--wa-text-secondary);
}
.ops-card__row-meta {
    margin-top: 0.1875rem;
    font-size: 0.6875rem;
    color: var(--wa-text-secondary);
}
.ops-card__tag {
    flex-shrink: 0;
    border-radius: 999px;
    padding: 0.125rem 0.45rem;
    font-size: 0.6875rem;
    font-weight: 700;
    color: var(--wa-accent);
    background: color-mix(in srgb, var(--wa-accent) 12%, transparent);
}
.contact-card__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}
.contact-card__title {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--wa-text);
}
.contact-card__subtitle,
.contact-card__muted {
    margin-top: 0.125rem;
    font-size: 0.75rem;
    color: var(--wa-text-secondary);
}
.contact-card__refresh {
    width: 1.875rem;
    height: 1.875rem;
    border-radius: 9999px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--wa-text-secondary);
}
.contact-card__refresh:hover:not(:disabled) {
    color: var(--wa-text);
    background: var(--wa-panel-hover);
}
.contact-card__grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.5rem;
}
.contact-card__grid > div {
    border-radius: 0.625rem;
    background: var(--wa-panel);
    border: 1px solid var(--wa-border);
    padding: 0.5rem;
}
.contact-card__label {
    font-size: 0.6875rem;
    color: var(--wa-text-secondary);
}
.contact-card__value {
    margin-top: 0.125rem;
    font-size: 0.9375rem;
    font-weight: 700;
    color: var(--wa-text);
}
.contact-card__facts {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.35rem 0.75rem;
    font-size: 0.75rem;
    color: var(--wa-text);
}
.contact-card__facts span {
    color: var(--wa-text-secondary);
}
.contact-card__last {
    border-radius: 0.625rem;
    background: var(--wa-panel);
    border: 1px solid var(--wa-border);
    padding: 0.625rem;
}
.contact-card__last-text {
    margin-top: 0.25rem;
    font-size: 0.8125rem;
    color: var(--wa-text);
    line-height: 1.35;
}
.contact-card__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 0.375rem;
}
.contact-card__chip {
    border-radius: 9999px;
    padding: 0.2rem 0.5rem;
    font-size: 0.6875rem;
    background: var(--wa-panel);
    border: 1px solid var(--wa-border);
    color: var(--wa-text-secondary);
}
.contact-card__error {
    font-size: 0.75rem;
    color: var(--wa-danger);
}
.shared-tab {
    border-radius: 0.625rem;
    padding: 0.45rem 0.35rem;
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--wa-text-secondary);
    transition: background-color 0.12s ease, color 0.12s ease;
}
.shared-tab:hover,
.shared-tab-active {
    color: var(--wa-text);
    background-color: var(--wa-selected);
}
.shared-empty {
    padding: 2.5rem 1rem;
    text-align: center;
    font-size: 0.875rem;
    color: var(--wa-text-secondary);
}
.shared-media-tile {
    position: relative;
    aspect-ratio: 1 / 1;
    overflow: hidden;
    border-radius: 0.375rem;
    background: var(--wa-panel-header);
    color: var(--wa-text-secondary);
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
}
.shared-media-tile:hover {
    filter: brightness(0.92);
}
.shared-media-fallback {
    font-size: 0.75rem;
    font-weight: 700;
}
.shared-media-play {
    position: absolute;
    inset: auto 0.35rem 0.35rem auto;
    width: 1.5rem;
    height: 1.5rem;
    border-radius: 9999px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    background: rgba(0, 0, 0, 0.55);
}
.shared-list-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem;
    border-radius: 0.75rem;
    border: 1px solid var(--wa-border);
    background: var(--wa-panel-header);
    color: inherit;
    text-decoration: none;
    transition: background-color 0.12s ease, border-color 0.12s ease;
}
.shared-list-row:hover {
    background: var(--wa-panel-hover);
    border-color: var(--wa-control-rim);
    box-shadow: var(--wa-control-rim-shadow);
}
.shared-link-icon,
.shared-doc-icon {
    width: 2.75rem;
    height: 2.75rem;
    border-radius: 0.75rem;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--wa-panel);
    color: var(--wa-accent);
    border: 1px solid var(--wa-border);
}
.shared-doc-icon {
    font-size: 0.6875rem;
    font-weight: 800;
    letter-spacing: 0.03em;
}
.shared-row-title {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--wa-text);
}
.shared-row-subtitle {
    margin-top: 0.125rem;
    font-size: 0.75rem;
    color: var(--wa-text-secondary);
}
.shared-row-date {
    margin-top: 0.25rem;
    font-size: 0.6875rem;
    color: var(--wa-text-secondary);
}
.sibling-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.625rem;
    border-radius: 0.5rem;
    background-color: var(--wa-panel-header);
    border: 1px solid var(--wa-border);
    text-decoration: none;
    color: inherit;
    transition: background-color 0.12s ease, border-color 0.12s ease;
}
.sibling-row:hover {
    background-color: var(--wa-panel-hover);
    border-color: var(--wa-control-rim);
    box-shadow: var(--wa-control-rim-shadow);
}
.sibling-name {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--wa-text);
}
.sibling-preview {
    margin-top: 2px;
    font-size: 0.75rem;
    color: var(--wa-text-secondary);
    max-width: 260px;
}

.participant-popover__header {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 0.875rem 0.875rem 0.75rem;
}
.participant-popover__avatar {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 9999px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--wa-accent);
    background: var(--wa-panel);
    border: 1px solid var(--wa-border);
}
.participant-popover__head-text {
    min-width: 0;
    flex: 1;
    padding-top: 0.125rem;
}
.participant-popover__title {
    font-size: 0.9375rem;
    font-weight: 600;
    line-height: 1.25;
    color: var(--wa-text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.participant-popover__subtitle {
    margin-top: 0.25rem;
    font-size: 0.75rem;
    line-height: 1.3;
    color: var(--wa-text-secondary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.participant-popover__divider {
    height: 1px;
    margin: 0 0.75rem;
    background: var(--wa-border);
}
.participant-popover__field-block {
    padding: 0.75rem 0.875rem 0.625rem;
}
.participant-popover__label {
    display: block;
    font-size: 0.6875rem;
    font-weight: 500;
    letter-spacing: 0.02em;
    margin-bottom: 0.375rem;
    color: var(--wa-text-secondary);
}
.participant-popover__input {
    width: 100%;
    border-radius: 0.625rem;
    border: 1px solid var(--wa-border);
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    line-height: 1.35;
    outline: none;
    box-sizing: border-box;
    transition: border-color 0.12s ease;
}
.participant-popover__input:focus {
    border-color: var(--wa-accent);
}
.participant-popover__error {
    margin-top: 0.375rem;
    font-size: 0.6875rem;
    line-height: 1.35;
    color: #ff6b6b;
}
.participant-popover__actions {
    padding: 0.25rem 0 0.375rem;
}
.participant-popover__row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    width: 100%;
    padding: 0.625rem 0.875rem;
    text-align: left;
    font-size: 0.875rem;
    color: var(--wa-text);
    background: transparent;
    border: none;
    cursor: pointer;
    transition: background-color 0.12s ease;
}
.participant-popover__row:not(:disabled):hover {
    background-color: var(--wa-panel-hover);
}
.participant-popover__row:disabled {
    cursor: not-allowed;
    opacity: 0.55;
}
.participant-popover__row-icon {
    width: 1.25rem;
    height: 1.25rem;
    flex-shrink: 0;
    color: var(--wa-text-secondary);
}
.participant-popover__row-label {
    flex: 1;
    min-width: 0;
    text-align: left;
}
</style>
