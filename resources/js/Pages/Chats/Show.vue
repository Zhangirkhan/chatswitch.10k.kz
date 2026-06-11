<script setup lang="ts">
import ChatLayout from '@/Layouts/ChatLayout.vue';
import ChatHeader from './Partials/ChatHeader.vue';
import ChatMessage from './Partials/ChatMessage.vue';
import ChatInput from './Partials/ChatInput.vue';
import OrchestratorApprovalBanner, { type PendingOrchestratorApproval } from './Partials/OrchestratorApprovalBanner.vue';
import FollowUpProposalBanner, { type PendingFollowUpProposal } from './Partials/FollowUpProposalBanner.vue';
import ContactInfoPanel from './Partials/ContactInfoPanel.vue';
import MessageInfoPanel from './Partials/MessageInfoPanel.vue';
import ShareMessageModal, { type ShareModalSource } from '@/Components/ShareMessageModal.vue';
import AiAssistantPanel from './Partials/AiAssistantPanel.vue';
import PanelResizeHandle from '@/Components/Ui/PanelResizeHandle.vue';
import { useResizablePanelWidth } from '@/composables/useResizablePanelWidth';
import { getChatAiPanelPrefs, updateChatAiPanelPrefs } from '@/composables/useChatAiPanelPrefs';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, onMounted, nextTick, watch, onUnmounted, computed } from 'vue';
import axios from 'axios';
import type { AssignableUser, Chat, Department, FunnelCatalogEntry, Message, MessageReaction, Paginated } from '@/types';
import { useToastStore } from '@/stores/toast';
import { useI18n } from '@/composables/useI18n';
import { detectClientLanguage } from '@/utils/messageLanguage';
import { mergeSidebarChats } from '@/utils/sidebarChatList';
import { useEchoChannel } from '@/composables/useEchoChannel';
import { useChatThreadSync } from '@/composables/useChatThreadSync';
import { chatChannel } from '@/utils/tenantChannels';

const props = defineProps<{
    chat: Chat;
    messages: Paginated<Message>;
    chats: Paginated<Chat>;
    departments?: Department[];
    assignableUsers?: AssignableUser[];
    aiStatus?: {
        id: number;
        mode: string;
        status: string;
        label: string;
        message: string;
        hint: string | null;
        knowledge_context: {
            rules: number;
            products: number;
            services: number;
        } | null;
        tone_source: {
            source: string;
            label: string;
            hint: string;
        } | null;
        draft_reply: string | null;
        technical_error: string | null;
        updated_at: string | null;
        history?: Array<{
            id: number;
            mode: string;
            status: string;
            label: string;
            message: string;
            technical_error: string | null;
            message_id: number | null;
            trigger_message_id: number | null;
            updated_at: string | null;
        }>;
        orchestrator_history?: Array<{
            id: number;
            status: string;
            label: string;
            reason: string | null;
            confidence: number | null;
            target_stage: string | null;
            customer_reply: string | null;
            task_title: string | null;
            trigger_message_id: number | null;
            completed_at: string | null;
        }>;
    } | null;
    sidebarInsights?: {
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
    funnelCatalog?: FunnelCatalogEntry[];
    aiReadinessBanner?: {
        score: number;
        threshold: number;
        status: string;
        label: string;
    } | null;
    pendingOrchestratorApproval?: PendingOrchestratorApproval | null;
    pendingFollowUpProposal?: PendingFollowUpProposal | null;
}>();

const { show: showToast } = useToastStore();
const { t } = useI18n();
const page = usePage<any>();
const tenantCompanyId = computed(() => Number(page.props.tenantCompanyId || 0));

const readinessBannerDismissed = ref(false);
const funnelRealtime = ref<Partial<Chat>>({});
const pendingApproval = ref<PendingOrchestratorApproval | null>(props.pendingOrchestratorApproval ?? null);
const pendingFollowUp = ref<PendingFollowUpProposal | null>(props.pendingFollowUpProposal ?? null);

watch(
    () => props.pendingOrchestratorApproval,
    (value) => {
        pendingApproval.value = value ?? null;
    },
);

watch(
    () => props.pendingFollowUpProposal,
    (value) => {
        pendingFollowUp.value = value ?? null;
    },
);

function onFollowUpProposalCleared(): void {
    pendingFollowUp.value = null;
}

function onOrchestratorApproved(payload: {
    ai_orchestrator_status: string | null;
    ai_orchestrator_last_summary: string | null;
}): void {
    funnelRealtime.value = {
        ...funnelRealtime.value,
        ai_orchestrator_status: payload.ai_orchestrator_status as Chat['ai_orchestrator_status'],
        ai_orchestrator_last_summary: payload.ai_orchestrator_last_summary,
    };
}

function onOrchestratorApprovalCleared(): void {
    pendingApproval.value = null;
}

const headerChat = computed(() => ({
    ...props.chat,
    ...funnelRealtime.value,
}));

watch(
    () => props.chat.id,
    () => {
        funnelRealtime.value = {};
        readinessBannerDismissed.value = false;
    },
);

watch(
    () => props.chat.updated_at,
    () => {
        funnelRealtime.value = {};
    },
);

const localMessages = ref<Message[]>([...props.messages.data].reverse());
const clientMessageLanguage = computed(() =>
    detectClientLanguage(
        localMessages.value
            .filter((message) => message.direction === 'inbound')
            .slice(-20)
            .map((message) => message.body),
    ),
);
const localChats = ref<Paginated<Chat>>({
    ...props.chats,
    data: [...props.chats.data],
});

/** Чат после SET NULL на сессии может терять whatsapp_session_id, тогда берём из сообщений / relation. */
const forwardWhatsappSessionId = computed((): number | null => {
    const fromChat = props.chat.whatsapp_session_id as number | null | undefined;
    if (fromChat != null && Number(fromChat) > 0) {
        return Number(fromChat);
    }
    const fromRel = props.chat.whatsapp_session?.id;
    if (fromRel != null && Number(fromRel) > 0) {
        return Number(fromRel);
    }
    for (const m of localMessages.value) {
        const mid = m.whatsapp_session_id;
        if (mid != null && Number(mid) > 0) {
            return Number(mid);
        }
    }
    return null;
});

type MessageStatus = 'sent' | 'delivered' | 'read';

function statusFromAck(ack: Message['ack'] | undefined): MessageStatus | null {
    if (ack === 'read') return 'read';
    if (ack === 'delivered') return 'delivered';
    if (ack === 'sent') return 'sent';
    return null;
}

function ensureStatus(message: Message): MessageStatus | null {
    return message.status || statusFromAck(message.ack) || null;
}

function setLocalMessageStatus(messageId: number, status: MessageStatus): void {
    const idx = localMessageIndexById(messageId);
    if (idx < 0) return;
    const cur = localMessages.value[idx]!;
    if (cur.status === status) return;
    localMessages.value[idx] = { ...cur, status };
}

const statusSimTimers = new Map<number, number[]>();

function clearSimTimers(messageId: number): void {
    const timers = statusSimTimers.get(messageId);
    if (!timers) return;
    timers.forEach((t) => window.clearTimeout(t));
    statusSimTimers.delete(messageId);
}

function simulateOutboundStatusProgress(messageId: number): void {
    clearSimTimers(messageId);
    const deliveredT = window.setTimeout(() => setLocalMessageStatus(messageId, 'delivered'), 1000);
    statusSimTimers.set(messageId, [deliveredT]);
}

function localMessageIndexById(id: unknown): number {
    const n = Number(id);
    if (!Number.isFinite(n)) {
        return -1;
    }
    return localMessages.value.findIndex((m) => Number(m.id) === n);
}

function mergeMessageIntoList(incoming: Message): void {
    const idx = localMessageIndexById(incoming.id);
    if (idx >= 0) {
        const cur = localMessages.value[idx]!;
        const nextStatus = incoming.status ?? statusFromAck(incoming.ack) ?? cur.status;
        localMessages.value[idx] = {
            ...cur,
            ...incoming,
            status: nextStatus,
            media:
                Array.isArray(incoming.media) && incoming.media.length > 0 ? incoming.media : cur.media,
        };
        return;
    }
    const next: Message = { ...incoming };
    const s = ensureStatus(next);
    if (s) next.status = s;
    localMessages.value.push(next);
}
const messagesContainer = ref<HTMLDivElement | null>(null);
const typingUsers = ref<Map<number, string>>(new Map());
const isLoadingMore = ref(false);
const hasMoreMessages = ref(props.messages.current_page < props.messages.last_page);

const searchOpen = ref(false);
const searchQuery = ref('');
const contactInfoOpen = ref(false);
const messageInfoOpen = ref(false);
const messageInfoMessage = ref<Message | null>(null);
const aiPanelStoredOpen = ref(getChatAiPanelPrefs(props.chat.id).open);
const aiPanelOpen = computed({
    get: () => aiPanelStoredOpen.value && !contactInfoOpen.value && !messageInfoOpen.value,
    set: (open: boolean) => {
        aiPanelStoredOpen.value = open;
        updateChatAiPanelPrefs(props.chat.id, { open });
    },
});

watch(
    () => props.chat.id,
    (chatId) => {
        aiPanelStoredOpen.value = getChatAiPanelPrefs(chatId).open;
        contactInfoOpen.value = false;
        messageInfoOpen.value = false;
        messageInfoMessage.value = null;
    },
);

const contactPanelResize = useResizablePanelWidth({
    storageKey: 'chats.contactPanelWidth',
    defaultWidth: 400,
    minWidth: 300,
    maxWidth: 640,
    edge: 'right',
});

const aiPanelResize = useResizablePanelWidth({
    storageKey: 'chats.aiPanelWidth',
    defaultWidth: 420,
    minWidth: 320,
    maxWidth: 720,
    edge: 'right',
});

const contactPanelWidthPx = computed(() => contactPanelResize.widthPx.value);
const aiPanelWidthPx = computed(() => aiPanelResize.widthPx.value);
const contactPanelResizing = computed(() => contactPanelResize.isResizing.value);
const aiPanelResizing = computed(() => aiPanelResize.isResizing.value);

const replyTo = ref<Message | null>(null);
const chatInputRef = ref<InstanceType<typeof ChatInput> | null>(null);

async function onUseAiReply(text: string): Promise<void> {
    await chatInputRef.value?.insertDraftAndSend(text);
}
const shareOpen = ref(false);
const shareMessage = ref<Message | null>(null);
const shareMessageIds = ref<number[] | null>(null);

const shareSource = computed((): ShareModalSource | null => {
    if (!shareOpen.value) return null;
    if (shareMessageIds.value && shareMessageIds.value.length > 0) {
        return {
            kind: 'whatsapp',
            messageIds: shareMessageIds.value,
            whatsappSessionId: forwardWhatsappSessionId.value,
        };
    }
    if (shareMessage.value) {
        return {
            kind: 'whatsapp',
            message: shareMessage.value,
            whatsappSessionId: forwardWhatsappSessionId.value,
        };
    }
    return null;
});

const selectionMode = ref(false);
const selectedMessageIds = ref<Set<number>>(new Set());
const selectedCount = computed(() => selectedMessageIds.value.size);

function setReply(msg: Message) {
    replyTo.value = msg;
}

function clearReply() {
    replyTo.value = null;
}

function openForward(msg: Message) {
    shareMessage.value = msg;
    shareMessageIds.value = null;
    shareOpen.value = true;
    contactInfoOpen.value = false;
    messageInfoOpen.value = false;
}

function closeShare() {
    shareOpen.value = false;
    shareMessage.value = null;
    shareMessageIds.value = null;
}

function onShareSent(payload: { tab: 'clients' | 'colleagues'; count: number }): void {
    const target = payload.tab === 'clients' ? t('chats.sentToClients') : t('chats.sentToColleagues');
    showToast({ message: t('chats.sentTo', { target, count: payload.count }), type: 'info' });
    clearSelection();
}

function toggleSelectMessage(id: number) {
    if (!selectionMode.value) selectionMode.value = true;
    const next = new Set(selectedMessageIds.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    selectedMessageIds.value = next;
    if (next.size === 0) selectionMode.value = false;
}

function clearSelection() {
    selectedMessageIds.value = new Set();
    selectionMode.value = false;
}

function openForwardSelected() {
    const ids = Array.from(selectedMessageIds.value);
    if (ids.length === 0) return;
    shareMessage.value = null;
    shareMessageIds.value = ids;
    shareOpen.value = true;
    contactInfoOpen.value = false;
    messageInfoOpen.value = false;
}

function onMessageDeleted(id: number) {
    localMessages.value = localMessages.value.filter((m) => m.id !== id);
}

function onReactionsUpdated(payload: { id: number; reactions: MessageReaction[] }) {
    const msg = localMessages.value.find((m) => m.id === payload.id);
    if (msg) msg.reactions = payload.reactions;
}

function toggleSearch() {
    searchOpen.value = !searchOpen.value;
    if (!searchOpen.value) searchQuery.value = '';
}

function toggleContactInfo() {
    contactInfoOpen.value = !contactInfoOpen.value;
}

function openAiFromContactPanel() {
    contactInfoOpen.value = false;
    aiPanelOpen.value = true;
}

function openAiPanel() {
    aiPanelOpen.value = true;
    contactInfoOpen.value = false;
    messageInfoOpen.value = false;
}

function closeAiPanel() {
    aiPanelOpen.value = false;
}

function openMessageInfo(msg: Message) {
    // “Данные о сообщении” доступны только для собственных исходящих сообщений.
    if (msg.direction !== 'outbound') return;
    messageInfoMessage.value = msg;
    messageInfoOpen.value = true;
    contactInfoOpen.value = false;
}

function closeMessageInfo() {
    messageInfoOpen.value = false;
    messageInfoMessage.value = null;
}

function messageTimelineMs(m: Message): number {
    const raw = m.message_timestamp || m.created_at;
    if (!raw) return 0;
    const t = new Date(raw).getTime();
    return Number.isFinite(t) ? t : 0;
}

const displayedMessages = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return localMessages.value;
    const hits = localMessages.value.filter((m) => (m.body || '').toLowerCase().includes(q));
    // Новые совпадения сверху (как в поиске мессенджеров: приоритет у последних сообщений).
    return [...hits].sort((a, b) => {
        const diff = messageTimelineMs(b) - messageTimelineMs(a);
        if (diff !== 0) return diff;
        return b.id - a.id;
    });
});

const pinned = computed(() => (props.chat as any)?.pinned_message || null);

async function unpinPinned(): Promise<void> {
    try {
        await axios.delete(route('chats.unpin-message', props.chat.id));
        // Refresh chat so header banner updates.
        router.reload({ only: ['chat', 'unreadChatsCount', 'unreadChatsCountMine'] });
    } catch {
        // no-op; ChatMessage.vue shows alerts on pin/unpin from context menu
    }
}

function jumpToPinned(): void {
    const id = pinned.value?.id;
    if (typeof id === 'number') jumpToMessage(id);
}

function jumpToMessage(id: number) {
    nextTick(() => {
        const bubble = messagesContainer.value?.querySelector?.(`[data-message-id="${id}"]`) as HTMLElement | null;
        if (!bubble) return;
        bubble.scrollIntoView({ block: 'center', behavior: 'smooth' });

        const row = (bubble.closest('.group') as HTMLElement | null) || bubble;
        // Flash whole row, then fade out smoothly.
        const prevTransition = row.style.transition;
        const prevBoxShadow = row.style.boxShadow;
        const prevBg = row.style.backgroundColor;

        row.style.transition = 'background-color 360ms ease-out, box-shadow 360ms ease-out';
        row.style.boxShadow = '0 0 0 6px rgba(37, 211, 102, 0.12)';
        row.style.backgroundColor = 'rgba(37, 211, 102, 0.06)';

        window.setTimeout(() => {
            row.style.boxShadow = prevBoxShadow;
            row.style.backgroundColor = prevBg;
            window.setTimeout(() => {
                row.style.transition = prevTransition;
            }, 380);
        }, 650);
    });
}

function openMessageFromSearch(id: number) {
    const q = searchQuery.value.trim();
    if (!q) return;
    // Close search and show full context around the message.
    searchQuery.value = '';
    searchOpen.value = false;
    nextTick(() => jumpToMessage(id));
}

watch(() => props.messages, (newVal) => {
    localMessages.value = [...newVal.data].reverse();
    hasMoreMessages.value = newVal.current_page < newVal.last_page;
    nextTick(scrollToBottom);
});

watch(() => props.chats, (newVal) => {
    localChats.value = mergeSidebarChats(localChats.value, newVal);
});

onMounted(() => {
    scrollToBottom();
    markAsRead();
});

function handleIncomingMessage(e: unknown): void {
    const payload = e as { message?: Message };
    const msg = payload.message;
    if (!msg?.id) return;
    const idx = localMessageIndexById(msg.id);
    if (idx >= 0) {
        const cur = localMessages.value[idx]!;
        localMessages.value[idx] = {
            ...cur,
            ...msg,
            media: Array.isArray(msg.media) && msg.media.length > 0 ? msg.media : cur.media,
        };
        return;
    }
    mergeMessageIntoList(msg);
    scrollToBottom();
    markAsRead();
}

function handleMessageAck(e: unknown): void {
    const payload = e as { id?: number; ack?: Message['ack'] };
    if (!payload.id || !payload.ack) return;
    const idx = localMessageIndexById(payload.id);
    if (idx < 0) return;
    const cur = localMessages.value[idx]!;
    const nextStatus = statusFromAck(payload.ack) ?? cur.status;
    localMessages.value[idx] = { ...cur, ack: payload.ack, status: nextStatus };
}

useEchoChannel(
    () => (props.chat.id ? chatChannel(tenantCompanyId.value, props.chat.id) : null),
    () => ({
        '.message.received': handleIncomingMessage,
        '.message.ack': handleMessageAck,
        '.user.typing': (e: unknown) => {
            const payload = e as { userId?: number; userName?: string };
            if (payload.userId == null) return;
            typingUsers.value.set(payload.userId, payload.userName ?? '');
            setTimeout(() => typingUsers.value.delete(payload.userId!), 3000);
        },
        '.message.reactions': (e: unknown) => {
            const payload = e as { id?: number; reactions?: MessageReaction[] };
            const msg = localMessages.value.find((m) => m.id === payload.id);
            if (msg) {
                msg.reactions = (payload.reactions || []) as MessageReaction[];
            }
        },
        '.funnel.updated': (e: unknown) => {
            const payload = e as {
                funnel?: Chat['funnel'];
                stage?: Chat['funnel_stage'];
                progress_percent?: number;
                funnel_progress?: Chat['funnel_progress'];
                reason?: string | null;
                funnel_tracking_enabled?: boolean;
                funnel_stage_locked?: boolean;
            };
            funnelRealtime.value = {
                ...funnelRealtime.value,
                funnel: payload.funnel ?? null,
                funnel_stage: payload.stage ?? null,
                funnel_progress_percent: typeof payload.progress_percent === 'number' ? payload.progress_percent : undefined,
                funnel_progress: payload.funnel_progress ?? undefined,
                funnel_ai_last_reason: payload.reason ?? null,
                funnel_tracking_enabled: payload.funnel_tracking_enabled,
                funnel_stage_locked: payload.funnel_stage_locked,
            };
        },
        '.ai-orchestrator.updated': (e: unknown) => {
            const payload = e as {
                ai_orchestrator_status?: Chat['ai_orchestrator_status'];
                ai_orchestrator_last_run_id?: number | null;
                ai_orchestrator_last_action_at?: string | null;
                ai_orchestrator_last_summary?: string | null;
            };
            funnelRealtime.value = {
                ...funnelRealtime.value,
                ai_orchestrator_status: payload.ai_orchestrator_status ?? null,
                ai_orchestrator_last_run_id: payload.ai_orchestrator_last_run_id ?? null,
                ai_orchestrator_last_action_at: payload.ai_orchestrator_last_action_at ?? null,
                ai_orchestrator_last_summary: payload.ai_orchestrator_last_summary ?? null,
            };
        },
    }),
);

useChatThreadSync({
    chatId: () => props.chat.id,
    messages: localMessages,
    mergeMessage: mergeMessageIntoList,
    onSynced: () => {
        scrollToBottom();
        markAsRead();
    },
});

function scrollToBottom() {
    nextTick(() => {
        if (messagesContainer.value) {
            messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
        }
    });
}

function markAsRead() {
    const localUnread = localChats.value.data.find((chat) => chat.id === props.chat.id)?.unread_count ?? 0;
    if (props.chat.unread_count > 0 || localUnread > 0) {
        localChats.value = {
            ...localChats.value,
            data: localChats.value.data.map((chat) =>
                chat.id === props.chat.id ? { ...chat, unread_count: 0 } : chat
            ),
        };

        axios
            .post(route('chats.mark-read', props.chat.id))
            .then(() => {
                router.reload({ only: ['unreadChatsCount', 'unreadChatsCountMine', 'chat', 'listOwnership', 'mineChatsTotal'] });
            })
            .catch(() => {});
    }
}

async function onMessageSent(message: Message) {
    mergeMessageIntoList(message);
    if (message.direction === 'outbound') {
        const s = ensureStatus(message) || 'sent';
        setLocalMessageStatus(message.id, s);
        // Never imitate `read` on the client: only the backend may confirm it.
        if (s === 'sent' || (!message.status && message.ack !== 'read' && message.ack !== 'delivered')) {
            simulateOutboundStatusProgress(message.id);
        }
    }
    scrollToBottom();
    // Назначения/отделы в шапке и плашки в списке (в т.ч. авто-добавление админа) — сразу с сервера.
    try {
        await router.reload({ only: ['chat', 'chats', 'unreadChatsCount', 'unreadChatsCountMine', 'listOwnership', 'mineChatsTotal'] });
    } catch {
        /* сеть / 419 — локальное сообщение уже в ленте */
    }
}

async function loadMoreMessages() {
    if (isLoadingMore.value || !hasMoreMessages.value) return;
    isLoadingMore.value = true;

    const oldestMsg = localMessages.value[0];
    const ts = oldestMsg?.message_timestamp || oldestMsg?.created_at;
    const beforeId = oldestMsg?.id;

    const el = messagesContainer.value;
    const prevScrollHeight = el?.scrollHeight ?? 0;

    try {
        const { data } = await axios.get(route('api.chats.timeline', props.chat.id), {
            params: { before_timestamp: ts, before_id: beforeId, limit: 50 },
        });
        if (data.messages?.length) {
            localMessages.value = [...data.messages, ...localMessages.value];
            await nextTick();
            if (el) {
                const delta = el.scrollHeight - prevScrollHeight;
                el.scrollTop += delta;
            }
        }
        if (!data.messages?.length || data.messages.length < 50) {
            hasMoreMessages.value = false;
        }
    } catch (err) {
        console.error('Load more failed:', err);
    } finally {
        isLoadingMore.value = false;
    }
}

onUnmounted(() => {
    statusSimTimers.forEach((timers) => timers.forEach((t) => window.clearTimeout(t)));
    statusSimTimers.clear();
});

</script>

<template>
    <Head :title="headerChat.chat_name || t('chats.chatFallbackTitle')" />
    <ChatLayout
        :chats="localChats"
        :selected-chat-id="headerChat.id"
        :scope="headerChat.is_archived ? 'archived' : 'active'"
        sidebar-lazy-load
    >
        <div class="flex h-full w-full min-h-0">
            <div class="flex flex-col flex-1 min-w-0 min-h-0">
                <div
                    v-if="aiReadinessBanner && !readinessBannerDismissed"
                    class="shrink-0 px-4 py-2.5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-[13px] leading-snug border-b"
                    :style="{
                        background: 'color-mix(in srgb, var(--wa-accent-soft) 42%, var(--wa-panel-header))',
                        borderColor: 'var(--wa-border)',
                        color: 'var(--wa-text)',
                    }"
                >
                    <div class="min-w-0">
                        <span class="font-medium">{{ t('chats.show.aiReadiness', { score: aiReadinessBanner.score }) }}</span>
                        <span class="opacity-80">
                            {{ t('chats.show.aiReadinessHint', { threshold: aiReadinessBanner.threshold }) }}
                        </span>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <Link
                            :href="route('settings.onboarding')"
                            class="rounded-lg px-3 py-1.5 text-xs font-semibold no-underline"
                            :style="{ background: 'var(--wa-accent)', color: 'var(--wa-accent-on)' }"
                        >
                            {{ t('chats.show.onboardingChecklist') }}
                        </Link>
                        <button
                            type="button"
                            class="rounded p-1 text-xs opacity-70 hover:opacity-100"
                            :aria-label="t('chats.show.hideReminderAria')"
                            @click="readinessBannerDismissed = true"
                        >
                            <span aria-hidden="true">×</span>
                        </button>
                    </div>
                </div>
                <ChatHeader
                    :chat="headerChat"
                    :typing-users="typingUsers"
                    :departments="departments"
                    :assignable-users="assignableUsers"
                    :ai-status="aiStatus"
                    :funnel-catalog="funnelCatalog"
                    @toggle-search="toggleSearch"
                    @show-contact-info="toggleContactInfo"
                    @open-ai="openAiPanel"
                />

                <!-- Inline message search -->
                <div
                    v-if="searchOpen"
                    class="px-4 py-2 shrink-0 border-b flex items-center gap-3"
                    :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border)' }"
                >
                    <div class="relative flex-1">
                        <svg
                            class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--wa-icon)]"
                            fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <input
                            v-model="searchQuery"
                            type="text"
                            :placeholder="t('chats.show.searchMessages')"
                            class="w-full pl-10 pr-3 py-2 rounded-full text-sm border-0 focus:ring-0 focus:outline-none"
                            :style="{ background: 'var(--wa-panel)', color: 'var(--wa-text)' }"
                            autofocus
                        />
                    </div>
                    <span class="text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                        {{ t('chats.show.searchFound', { count: displayedMessages.length }) }}
                    </span>
                    <button
                        @click="toggleSearch"
                        class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)]"
                        :title="t('common.close')"
                        type="button"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Single wallpaper under messages + input (input floats on top). -->
                <div class="flex min-h-0 flex-1 flex-col chat-bg">
                    <!-- Pinned message banner -->
                    <div
                        v-if="pinned"
                        class="shrink-0 px-4 py-2 border-b flex items-center gap-3"
                        :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border)' }"
                    >
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" :style="{ color: 'var(--wa-text-secondary)' }">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h8M9 3h6l1 4v5l2 2v2H6v-2l2-2V7l1-4z" />
                        </svg>
                        <button type="button" class="flex-1 min-w-0 text-left" @click="jumpToPinned">
                            <div class="text-xs font-medium truncate" :style="{ color: 'var(--wa-text)' }">
                                {{ t('chats.show.pinned') }}
                            </div>
                            <div class="text-xs truncate" :style="{ color: 'var(--wa-text-secondary)' }">
                                {{ (pinned.body || '').trim() || (pinned.type && pinned.type !== 'chat' ? t('chats.show.media') : '') || t('chats.show.message') }}
                            </div>
                        </button>
                        <button
                            type="button"
                            class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)]"
                            :title="t('chats.show.unpin')"
                            @click="unpinPinned"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Multi-select bar -->
                    <div
                        v-if="selectionMode"
                        class="shrink-0 px-4 py-2 border-b flex items-center justify-between gap-3"
                        :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border)' }"
                    >
                        <div class="flex items-center gap-2 min-w-0">
                            <button
                                type="button"
                                class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)]"
                                :title="t('chats.show.cancelSelection')"
                                @click="clearSelection"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                            <span class="text-sm font-medium truncate" :style="{ color: 'var(--wa-text)' }">
                                {{ t('chats.selected', { count: selectedCount }) }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="h-9 px-3 rounded-full text-sm font-medium"
                                :style="{ background: 'var(--wa-accent-soft)', color: 'var(--wa-accent)' }"
                                @click="openForwardSelected"
                            >
                                {{ t('chats.show.forward') }}
                            </button>
                        </div>
                    </div>

                    <!-- Messages area -->
                    <div
                        ref="messagesContainer"
                        class="min-h-0 flex-1 overflow-y-auto wa-scrollbar py-3 px-3 sm:px-4"
                    >
                        <div v-if="hasMoreMessages" class="mb-3 flex justify-center">
                            <button
                                type="button"
                                class="rounded-full px-4 py-2 text-xs font-medium transition hover:opacity-90 disabled:opacity-50"
                                :style="{
                                    background: 'var(--wa-date-bubble)',
                                    color: 'var(--wa-date-bubble-text)',
                                }"
                                :disabled="isLoadingMore"
                                @click="loadMoreMessages"
                            >
                                {{ isLoadingMore ? t('chats.loading') : t('chats.show.loadMore') }}
                            </button>
                        </div>

                    <ChatMessage
                        v-for="msg in displayedMessages"
                        :key="msg.id"
                        :message="msg"
                        :chat="chat"
                        :is-group-chat="chat.is_group"
                        :search-mode="searchOpen && !!searchQuery.trim()"
                        :selection-mode="selectionMode"
                        :selected="selectedMessageIds.has(msg.id)"
                        @jump-to="openMessageFromSearch"
                        @jump-to-message="jumpToMessage"
                        @forward="openForward"
                        @toggle-select="toggleSelectMessage"
                        @reply="setReply"
                        @deleted="onMessageDeleted"
                        @reactions-updated="onReactionsUpdated"
                        @message-info="openMessageInfo"
                    />

                    <div v-if="displayedMessages.length === 0" class="flex items-center justify-center h-full">
                        <p
                            class="text-[13px] px-4 py-2 rounded-lg wa-shadow"
                            :style="{ background: 'var(--wa-date-bubble)', color: 'var(--wa-date-bubble-text)' }"
                        >
                            <template v-if="searchQuery">
                                {{ t('chats.show.noSearchResults') }}
                            </template>
                            <template v-else>
                                {{ t('chats.show.noMessages') }}
                            </template>
                        </p>
                    </div>
                    </div>

                    <OrchestratorApprovalBanner
                        v-if="pendingApproval"
                        :chat-id="chat.id"
                        :pending="pendingApproval"
                        @approved="onOrchestratorApproved"
                        @cleared="onOrchestratorApprovalCleared"
                    />

                    <FollowUpProposalBanner
                        v-if="pendingFollowUp"
                        :chat-id="chat.id"
                        :pending="pendingFollowUp"
                        @cleared="onFollowUpProposalCleared"
                        @sent="onFollowUpProposalCleared"
                    />

                    <ChatInput
                        ref="chatInputRef"
                        :chat-id="chat.id"
                        :session-id="chat.whatsapp_session_id"
                        :reply-to="replyTo"
                        :is-group="chat.is_group"
                        :suggested-draft="aiStatus?.draft_reply || null"
                        :client-language="clientMessageLanguage"
                        @message-sent="onMessageSent"
                        @cancel-reply="clearReply"
                    />
                </div>
            </div>

            <template v-if="contactInfoOpen">
                <PanelResizeHandle
                    :active="contactPanelResizing"
                    @pointerdown="contactPanelResize.onResizePointerDown"
                />
                <ContactInfoPanel
                    :chat="chat"
                    :messages="localMessages"
                    :ai-status="aiStatus"
                    :sidebar-insights="sidebarInsights"
                    :panel-width="contactPanelWidthPx"
                    @close="toggleContactInfo"
                    @open-ai="openAiFromContactPanel"
                    @open-search="() => { contactInfoOpen = false; searchOpen = true; }"
                />
            </template>

            <template v-if="messageInfoOpen && messageInfoMessage">
                <PanelResizeHandle
                    :active="contactPanelResizing"
                    @pointerdown="contactPanelResize.onResizePointerDown"
                />
                <MessageInfoPanel
                    :message="messageInfoMessage"
                    :panel-width="contactPanelWidthPx"
                    @close="closeMessageInfo"
                />
            </template>

            <template v-if="aiPanelOpen">
                <PanelResizeHandle
                    :active="aiPanelResizing"
                    @pointerdown="aiPanelResize.onResizePointerDown"
                />
                <AiAssistantPanel
                    :chat-id="chat.id"
                    :contact-id="chat.contact_id"
                    :chat-name="chat.chat_name || chat.contact?.name || chat.contact?.push_name || null"
                    :messages="localMessages"
                    :ai-status="aiStatus"
                    :panel-width="aiPanelWidthPx"
                    @close="closeAiPanel"
                    @use-reply="onUseAiReply"
                />
            </template>

            <ShareMessageModal
                :open="shareOpen"
                :source="shareSource"
                @close="closeShare"
                @sent="onShareSent"
            />

        </div>
    </ChatLayout>
</template>

<!-- Flash styles are applied inline in `jumpToMessage` -->
