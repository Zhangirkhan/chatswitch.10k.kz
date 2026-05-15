<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import OrganizationLayout from '@/Layouts/OrganizationLayout.vue';
import TeamChatMessage, { type TeamChatMessageModel } from './Partials/TeamChatMessage.vue';
import type { OrgDepartment } from '../Partials/OrganizationSidebar.vue';
import type { MessageReaction, PageProps } from '@/types';

const DRAFTS_KEY = 'chatswitch:orgTeamChatDrafts:v1';

type TeamRoomPinned = {
    id: number;
    sender_name: string;
    body_preview: string;
};

type TeamMsg = TeamChatMessageModel;

type TeamConvPick = { id: number; title: string; subtitle: string | null };

type TeamParticipant = { id: number; name: string };

const props = defineProps<{
    departments: OrgDepartment[];
    selectedConversationId: number | null;
}>();

const page = usePage<PageProps>();
const myUserId = () => page.props.auth?.user?.id ?? 0;

const messages = ref<TeamMsg[]>([]);
const draft = ref('');
const pendingAttachments = ref<File[]>([]);
const attachmentInputRef = ref<HTMLInputElement | null>(null);
const sending = ref(false);
const loading = ref(false);
const hasMore = ref(true);
const threadEl = ref<HTMLElement | null>(null);
const conversationType = ref<'direct' | 'department' | null>(null);
const departmentId = ref<number | null>(null);
const peerLastReadMessageId = ref<number | null>(null);
const peerLastDeliveredMessageId = ref<number | null>(null);
/** В группе отдела: min(last_delivered) по остальным участникам — для подписи «Доставлено» ко всем. */
const othersMinDeliveredMessageId = ref<number | null>(null);
/** В группе отдела: min(last_read) по остальным — «Прочитано» всеми. */
const othersMinReadMessageId = ref<number | null>(null);
const participants = ref<TeamParticipant[]>([]);
const mentionUserIds = ref<number[]>([]);

const forwardPickerOpen = ref(false);
const forwardSource = ref<TeamMsg | null>(null);
const forwardCaption = ref('');
const forwardTargets = ref<TeamConvPick[]>([]);
const forwardTargetsLoading = ref(false);
const forwardSending = ref(false);

const replyToMessage = ref<TeamMsg | null>(null);

/** user_id → имя; только собеседники (не я). */
const typingUsers = reactive(new Map<number, string>());
let typingPingTimer: ReturnType<typeof setTimeout> | null = null;

const typingLabel = computed((): string => {
    const names = [...typingUsers.values()];
    if (names.length === 0) {
        return '';
    }
    if (names.length === 1) {
        return `${names[0]} печатает…`;
    }
    return `${names.join(', ')} печатают…`;
});

const canPinRoomMessage = ref(false);
const roomPinnedMessage = ref<TeamRoomPinned | null>(null);
const roomPinSending = ref(false);

const taskFromMessageSending = ref(false);

const replyJumpNotice = ref('');
let replyJumpNoticeTimer: ReturnType<typeof setTimeout> | null = null;

let echoChannel: any = null;
let draftPersistTimer: ReturnType<typeof setTimeout> | null = null;
let deliverFlushTimer: ReturnType<typeof setTimeout> | null = null;
let deliverMaxPendingId = 0;

function scheduleMarkDelivered(messageId: number): void {
    if (conversationType.value !== 'direct' && conversationType.value !== 'department') return;
    if (messageId < 1) return;
    deliverMaxPendingId = deliverMaxPendingId === 0 ? messageId : Math.max(deliverMaxPendingId, messageId);
    if (deliverFlushTimer) clearTimeout(deliverFlushTimer);
    deliverFlushTimer = setTimeout(() => {
        deliverFlushTimer = null;
        const cid = props.selectedConversationId;
        const mid = deliverMaxPendingId;
        deliverMaxPendingId = 0;
        if (!cid || mid < 1) return;
        void axios
            .post(route('organization.team-chat.api.delivered', cid), { message_id: mid })
            .catch(() => {});
    }, 300);
}

function markDeliveredForLoadedIncoming(): void {
    if (conversationType.value !== 'direct' && conversationType.value !== 'department') return;
    const mine = myUserId();
    let max = 0;
    for (const m of messages.value) {
        if (m.sender_id !== mine) max = Math.max(max, m.id);
    }
    if (max > 0) scheduleMarkDelivered(max);
}

function readAllDrafts(): Record<string, string> {
    try {
        const raw = sessionStorage.getItem(DRAFTS_KEY);
        if (!raw) return {};
        const o = JSON.parse(raw) as unknown;
        if (typeof o !== 'object' || o === null || Array.isArray(o)) return {};
        return o as Record<string, string>;
    } catch {
        return {};
    }
}

function persistDraft(convId: number, text: string): void {
    const all = readAllDrafts();
    const key = String(convId);
    if (text.trim() === '') {
        delete all[key];
    } else {
        all[key] = text;
    }
    sessionStorage.setItem(DRAFTS_KEY, JSON.stringify(all));
}

function clearDraftInStorage(convId: number): void {
    const all = readAllDrafts();
    delete all[String(convId)];
    sessionStorage.setItem(DRAFTS_KEY, JSON.stringify(all));
}

const peerUser = computed((): TeamParticipant | null => {
    if (conversationType.value !== 'direct') return null;
    const mine = myUserId();
    const others = participants.value.filter((p) => p.id !== mine);
    return others.length === 1 ? others[0]! : null;
});

function isReadByPeer(m: TeamMsg): boolean {
    if (conversationType.value !== 'direct') return false;
    if (m.sender_id !== myUserId()) return false;
    const peerRead = peerLastReadMessageId.value;
    if (peerRead === null || peerRead < 1) return false;
    return m.id <= peerRead;
}

function isDeliveredToPeer(m: TeamMsg): boolean {
    if (conversationType.value !== 'direct') return false;
    if (m.sender_id !== myUserId()) return false;
    const d = peerLastDeliveredMessageId.value;
    if (d === null || d < 1) return false;
    return m.id <= d;
}

function outgoingDmReceiptLabel(m: TeamMsg): 'read' | 'delivered' | 'sent' | null {
    if (m.sender_id !== myUserId()) return null;
    if (conversationType.value === 'direct') {
        if (isReadByPeer(m)) return 'read';
        if (isDeliveredToPeer(m)) return 'delivered';
        return 'sent';
    }
    if (conversationType.value === 'department') {
        const readMin = othersMinReadMessageId.value;
        if (readMin !== null && m.id <= readMin) return 'read';
        const d = othersMinDeliveredMessageId.value;
        if (d !== null && m.id <= d) return 'delivered';
        return 'sent';
    }
    return null;
}

type TeamReadMetaPayload = {
    peer_last_read_message_id?: number | null;
    peer_last_delivered_message_id?: number | null;
    others_min_last_delivered_message_id?: number | null;
    others_min_last_read_message_id?: number | null;
};

function applyReadMetaFromPayload(rm: TeamReadMetaPayload | undefined): void {
    const pr = rm?.peer_last_read_message_id;
    peerLastReadMessageId.value = typeof pr === 'number' && pr > 0 ? pr : null;
    const pd = rm?.peer_last_delivered_message_id;
    peerLastDeliveredMessageId.value = typeof pd === 'number' && pd > 0 ? pd : null;
    const om = rm?.others_min_last_delivered_message_id;
    othersMinDeliveredMessageId.value = typeof om === 'number' && om >= 0 ? om : null;
    const or = rm?.others_min_last_read_message_id;
    othersMinReadMessageId.value = typeof or === 'number' && or >= 0 ? or : null;
}

async function refreshReadMeta(): Promise<void> {
    const cid = props.selectedConversationId;
    if (!cid) return;
    try {
        const { data } = await axios.get(route('organization.team-chat.api.read-meta', cid));
        applyReadMetaFromPayload(data.read_meta as TeamReadMetaPayload);
    } catch {
        /* ignore */
    }
}

async function fetchMessages(beforeId?: number | null) {
    const cid = props.selectedConversationId;
    if (!cid) {
        messages.value = [];
        return;
    }
    loading.value = true;
    try {
        const { data } = await axios.get(route('organization.team-chat.api.messages', cid), {
            params: beforeId ? { before_id: beforeId } : {},
        });
        const incoming = (data.messages ?? []) as TeamMsg[];
        const conv = data.conversation as {
            id?: number;
            type?: string;
            department_id?: number | null;
            can_pin_room_message?: boolean;
            room_pinned_message?: TeamRoomPinned | null;
        } | undefined;
        const t = conv?.type;
        conversationType.value = t === 'direct' || t === 'department' ? t : null;
        departmentId.value = typeof conv?.department_id === 'number' && conv.department_id > 0
            ? conv.department_id
            : null;
        if (typeof conv?.can_pin_room_message === 'boolean') {
            canPinRoomMessage.value = conv.can_pin_room_message;
        }
        roomPinnedMessage.value = conv?.room_pinned_message ?? null;
        applyReadMetaFromPayload(
            data.read_meta as TeamReadMetaPayload,
        );

        if (beforeId) {
            messages.value = [...incoming.map(normalizeTeamMsg), ...messages.value];
        } else {
            messages.value = incoming.map(normalizeTeamMsg);
            await nextTick();
            scrollToBottom();
        }
        hasMore.value = incoming.length >= 50;
        markDeliveredForLoadedIncoming();
    } finally {
        loading.value = false;
    }
}

async function fetchParticipants() {
    const cid = props.selectedConversationId;
    if (!cid) {
        participants.value = [];
        return;
    }
    try {
        const { data } = await axios.get(route('organization.team-chat.api.participants', cid));
        participants.value = (data.participants ?? []) as TeamParticipant[];
    } catch {
        participants.value = [];
    }
}

function toggleMentionPeer() {
    const p = peerUser.value;
    if (!p) return;
    const set = new Set(mentionUserIds.value);
    if (set.has(p.id)) {
        set.delete(p.id);
    } else {
        set.add(p.id);
    }
    mentionUserIds.value = [...set];
}

function toggleMentionColleague(id: number) {
    if (id === myUserId()) return;
    const set = new Set(mentionUserIds.value);
    if (set.has(id)) {
        set.delete(id);
    } else if (set.size < 20) {
        set.add(id);
    }
    mentionUserIds.value = [...set];
}

async function markRead() {
    const cid = props.selectedConversationId;
    if (!cid) return;
    try {
        await axios.post(route('organization.team-chat.api.read', cid), {});
    } catch {
        /* ignore */
    }
}

function scrollToBottom() {
    const el = threadEl.value;
    if (el) el.scrollTop = el.scrollHeight;
}

function scheduleTypingPing(): void {
    const cid = props.selectedConversationId;
    if (!cid || sending.value) {
        return;
    }
    if (typingPingTimer) {
        clearTimeout(typingPingTimer);
    }
    typingPingTimer = setTimeout(() => {
        typingPingTimer = null;
        void axios.post(route('organization.team-chat.api.typing', cid)).catch(() => {});
    }, 520);
}

function onDraftInput(): void {
    if (!props.selectedConversationId || sending.value) {
        return;
    }
    if (draft.value === '' && pendingAttachments.value.length === 0) {
        return;
    }
    scheduleTypingPing();
}

function patchMessageReactions(messageId: number, reactions: MessageReaction[]): void {
    messages.value = messages.value.map((row) =>
        row.id === messageId ? { ...row, reactions: [...reactions] } : row,
    );
}

async function applyTeamReaction(m: TeamMsg, emoji: string): Promise<void> {
    const cid = props.selectedConversationId;
    if (!cid || emoji.trim() === '') {
        return;
    }
    try {
        const { data } = await axios.post(
            route('organization.team-chat.api.messages.react', {
                team_conversation: cid,
                team_message: m.id,
            }),
            { emoji },
        );
        const reactions = Array.isArray(data.reactions) ? (data.reactions as MessageReaction[]) : [];
        patchMessageReactions(m.id, reactions);
    } catch {
        /* ignore */
    }
}

function normalizeTeamMsg(row: TeamMsg): TeamMsg {
    return {
        ...row,
        reactions: Array.isArray(row.reactions) ? row.reactions : [],
    };
}

function setupEcho() {
    const Echo = (window as any).Echo;
    const cid = props.selectedConversationId;
    if (!Echo || !cid) return;
    teardownEcho();
    echoChannel = Echo.private(`team-conversation.${cid}`);
    echoChannel.listen('.team.message', (e: any) => {
        const m = e.message as TeamMsg | undefined;
        if (!m?.id) return;
        if (messages.value.some((x) => x.id === m.id)) return;
        if (m.client_message_id && messages.value.some((x) => x.client_message_id === m.client_message_id)) {
            return;
        }
        const merged = normalizeTeamMsg(m);
        messages.value = [...messages.value, merged];
        typingUsers.delete(m.sender_id);
        void nextTick(() => scrollToBottom());
        if (m.sender_id !== myUserId()) {
            scheduleMarkDelivered(m.id);
            void markRead();
        }
    });
    echoChannel.listen('.team.delivered', (e: any) => {
        const recipientId = Number(e.recipient_user_id);
        const lid = Number(e.last_delivered_message_id);
        if (!recipientId || !lid) return;
        if (recipientId === myUserId()) return;
        if (conversationType.value === 'direct') {
            const cur = peerLastDeliveredMessageId.value ?? 0;
            if (lid > cur) {
                peerLastDeliveredMessageId.value = lid;
            }
            return;
        }
        if (conversationType.value === 'department') {
            void refreshReadMeta();
        }
    });
    echoChannel.listen('.team.read', (e: any) => {
        const readerId = Number(e.reader_user_id);
        const lid = Number(e.last_read_message_id);
        if (!readerId || readerId === myUserId()) return;
        if (!lid) return;
        if (conversationType.value === 'direct') {
            const cur = peerLastReadMessageId.value ?? 0;
            if (lid > cur) {
                peerLastReadMessageId.value = lid;
            }
            return;
        }
        if (conversationType.value === 'department') {
            void refreshReadMeta();
        }
    });
    echoChannel.listen('.team.room-pin', (e: any) => {
        const cid = props.selectedConversationId;
        if (!cid || Number(e.conversation_id) !== cid) {
            return;
        }
        const p = e.room_pinned_message;
        if (p === null) {
            roomPinnedMessage.value = null;
            return;
        }
        if (typeof p?.id === 'number' && p.id > 0) {
            roomPinnedMessage.value = {
                id: p.id,
                sender_name: String(p.sender_name ?? '…'),
                body_preview: String(p.body_preview ?? ''),
            };
        }
    });
    echoChannel.listen('.team.typing', (e: any) => {
        const uid = Number(e.user_id);
        if (!uid || uid === myUserId()) {
            return;
        }
        typingUsers.set(uid, String(e.user_name ?? '…'));
        window.setTimeout(() => typingUsers.delete(uid), 3200);
    });
    echoChannel.listen('.team.message.reactions', (e: any) => {
        const mid = Number(e.id);
        const conv = Number(e.conversation_id);
        const cid = props.selectedConversationId;
        if (!mid || !cid || conv !== cid) {
            return;
        }
        const reactions = Array.isArray(e.reactions) ? (e.reactions as MessageReaction[]) : [];
        patchMessageReactions(mid, reactions);
    });
}

function teardownEcho(conversationId?: number | null): void {
    const Echo = (window as any).Echo;
    const id = conversationId ?? null;
    if (Echo && echoChannel && id) {
        try {
            Echo.leave(`team-conversation.${id}`);
        } catch {
            /* ignore */
        }
    }
    echoChannel = null;
}

function onThreadScroll(e: Event) {
    const t = e.target as HTMLElement;
    if (t.scrollTop < 80) {
        void loadMore();
    }
}

async function scrollToHighlightIfNeeded(): Promise<void> {
    const raw = typeof page.url === 'string' ? page.url : '';
    const qIndex = raw.indexOf('?');
    if (qIndex === -1) {
        return;
    }
    const params = new URLSearchParams(raw.slice(qIndex + 1));
    const v = params.get('highlight_message_id');
    const hid = v ? parseInt(v, 10) : NaN;
    if (!Number.isFinite(hid) || hid < 1) {
        return;
    }
    for (let attempt = 0; attempt < 8; attempt++) {
        await nextTick();
        const root = threadEl.value;
        const el = root?.querySelector(`[data-team-msg-id="${hid}"]`);
        if (el instanceof HTMLElement) {
            el.scrollIntoView({ block: 'center', behavior: 'smooth' });
            el.classList.add('ring-2', 'ring-[var(--wa-accent)]', 'rounded-lg');
            window.setTimeout(() => {
                el.classList.remove('ring-2', 'ring-[var(--wa-accent)]', 'rounded-lg');
            }, 2200);
            return;
        }
        await new Promise((r) => setTimeout(r, 120));
    }
}

watch(
    () => props.selectedConversationId,
    async (id, prevId) => {
        if (prevId) {
            persistDraft(prevId, draft.value);
            teardownEcho(prevId);
        } else {
            teardownEcho();
        }
        messages.value = [];
        hasMore.value = true;
        mentionUserIds.value = [];
        replyToMessage.value = null;
        pendingAttachments.value = [];
        typingUsers.clear();
        if (typingPingTimer) {
            clearTimeout(typingPingTimer);
            typingPingTimer = null;
        }
        participants.value = [];
        conversationType.value = null;
        departmentId.value = null;
        peerLastReadMessageId.value = null;
        peerLastDeliveredMessageId.value = null;
        othersMinDeliveredMessageId.value = null;
        othersMinReadMessageId.value = null;
        canPinRoomMessage.value = false;
        roomPinnedMessage.value = null;
        roomPinSending.value = false;
        if (!id) {
            draft.value = '';
            typingUsers.clear();
            if (typingPingTimer) {
                clearTimeout(typingPingTimer);
                typingPingTimer = null;
            }
            return;
        }
        draft.value = readAllDrafts()[String(id)] ?? '';
        await Promise.all([fetchMessages(), fetchParticipants()]);
        setupEcho();
        void markRead();
        await scrollToHighlightIfNeeded();
    },
    { immediate: true },
);

watch(draft, () => {
    const cid = props.selectedConversationId;
    if (!cid) return;
    if (draftPersistTimer) clearTimeout(draftPersistTimer);
    draftPersistTimer = setTimeout(() => {
        draftPersistTimer = null;
        persistDraft(cid, draft.value);
    }, 400);
});

watch(
    () => page.url,
    () => {
        if (props.selectedConversationId) {
            void scrollToHighlightIfNeeded();
        }
    },
);

function onAttachmentInputChange(e: Event): void {
    const t = e.target as HTMLInputElement;
    const picked = t.files ? Array.from(t.files) : [];
    t.value = '';
    if (picked.length === 0) return;
    const max = 5;
    pendingAttachments.value = [...pendingAttachments.value, ...picked].slice(0, max);
}

function removePendingAttachment(index: number): void {
    pendingAttachments.value = pendingAttachments.value.filter((_, i) => i !== index);
}

function openAttachmentPicker(): void {
    attachmentInputRef.value?.click();
}

async function send() {
    const cid = props.selectedConversationId;
    const text = draft.value.trim();
    const files = pendingAttachments.value;
    if (!cid || sending.value) return;
    if (!text && files.length === 0) return;
    sending.value = true;
    const clientMessageId = crypto.randomUUID();
    try {
        const hasFiles = files.length > 0;
        if (hasFiles) {
            const fd = new FormData();
            if (text !== '') {
                fd.append('body', draft.value);
            }
            fd.append('client_message_id', clientMessageId);
            if (mentionUserIds.value.length > 0) {
                for (const id of mentionUserIds.value) {
                    fd.append('mention_user_ids[]', String(id));
                }
            }
            const reply = replyToMessage.value;
            if (reply?.id) {
                fd.append('parent_team_message_id', String(reply.id));
            }
            for (const f of files) {
                fd.append('attachments[]', f);
            }
            const { data } = await axios.post(route('organization.team-chat.api.messages.store', cid), fd);
            draft.value = '';
            clearDraftInStorage(cid);
            pendingAttachments.value = [];
            mentionUserIds.value = [];
            replyToMessage.value = null;
            const m = data.message as TeamMsg | undefined;
            if (m?.id) {
                const existsById = messages.value.some((x) => x.id === m.id);
                const existsByClient =
                    m.client_message_id
                    && messages.value.some((x) => x.client_message_id === m.client_message_id);
                if (!existsById && !existsByClient) {
                    messages.value = [...messages.value, normalizeTeamMsg(m)];
                }
            }
            await nextTick(() => scrollToBottom());
            void markRead();
            return;
        }

        const payload: Record<string, unknown> = {
            body: text,
            client_message_id: clientMessageId,
        };
        if (mentionUserIds.value.length > 0) {
            payload.mention_user_ids = [...mentionUserIds.value];
        }
        const reply = replyToMessage.value;
        if (reply?.id) {
            payload.parent_team_message_id = reply.id;
        }
        const { data } = await axios.post(route('organization.team-chat.api.messages.store', cid), payload);
        draft.value = '';
        clearDraftInStorage(cid);
        mentionUserIds.value = [];
        replyToMessage.value = null;
        const m = data.message as TeamMsg | undefined;
        if (m?.id) {
            const existsById = messages.value.some((x) => x.id === m.id);
            const existsByClient =
                m.client_message_id
                && messages.value.some((x) => x.client_message_id === m.client_message_id);
            if (!existsById && !existsByClient) {
                messages.value = [...messages.value, normalizeTeamMsg(m)];
            }
        }
        await nextTick(() => scrollToBottom());
        void markRead();
    } finally {
        sending.value = false;
    }
}

function startReplyTo(m: TeamMsg): void {
    if (m.reply_to) return;
    replyToMessage.value = m;
    const sel = window.getSelection()?.toString().trim() ?? '';
    if (sel.length >= 2) {
        const quote = `«${sel.slice(0, 240)}»\n\n`;
        if (!draft.value.startsWith(quote)) {
            draft.value = quote + draft.value;
        }
    }
}

function openCalendarFromChat(): void {
    const cid = props.selectedConversationId;
    if (!cid) return;
    const names = participants.value.map((p) => p.name).filter(Boolean).join(', ');
    const params = new URLSearchParams({ create: '1' });
    if (names) {
        params.set('title', `Встреча: ${names.slice(0, 80)}`);
    }
    const mine = myUserId();
    const others = participants.value.filter((p) => p.id !== mine);
    if (others.length === 1) {
        params.set('assignee_user_id', String(others[0]!.id));
    }
    window.open(`${route('calendar.index')}?${params.toString()}`, '_blank', 'noopener,noreferrer');
}

async function createTaskFromMessage(m: TeamMsg): Promise<void> {
    const deptId = departmentId.value;
    if (!deptId || taskFromMessageSending.value) return;
    const cid = props.selectedConversationId;
    const bodyText = (m.body ?? '').trim();
    const quote = bodyText !== '' ? bodyText.slice(0, 500) : 'Сообщение без текста';
    const titleSource = bodyText !== '' ? bodyText : (m.sender?.name ?? 'Задача из чата');
    const title = titleSource.slice(0, 120);
    const chatUrl = cid ? route('organization.team-chat.show', cid) : '';
    const body = `<p>Из внутреннего чата${chatUrl ? ` (<a href="${chatUrl}">открыть беседу</a>)` : ''}:</p><blockquote>${quote.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</blockquote>`;
    taskFromMessageSending.value = true;
    try {
        const { data } = await axios.post(route('organization.posts.store', deptId), {
            title,
            body,
            status: 'open',
        });
        const postId = data?.post?.id;
        if (typeof postId === 'number' && postId > 0) {
            window.open(route('organization.posts.show', postId), '_blank', 'noopener,noreferrer');
        }
    } catch {
        /* ignore */
    } finally {
        taskFromMessageSending.value = false;
    }
}

function clearReplyTo(): void {
    replyToMessage.value = null;
}

function replyDraftPreview(m: TeamMsg): string {
    if (m.reply_to?.body_preview) return m.reply_to.body_preview;
    const t = (m.body ?? '').trim();
    return t ? t.slice(0, 120) : '…';
}

function showReplyJumpNotice(text: string): void {
    replyJumpNotice.value = text;
    if (replyJumpNoticeTimer) {
        clearTimeout(replyJumpNoticeTimer);
    }
    replyJumpNoticeTimer = setTimeout(() => {
        replyJumpNotice.value = '';
        replyJumpNoticeTimer = null;
    }, 3500);
}

async function scrollToQuotedParent(parentMessageId: number): Promise<void> {
    if (parentMessageId < 1) {
        return;
    }
    await nextTick();
    const root = threadEl.value;
    if (!root) {
        return;
    }
    const el = root.querySelector(`[data-team-msg-id="${parentMessageId}"]`);
    if (el instanceof HTMLElement) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.classList.add(
            'ring-2',
            'ring-[var(--wa-accent)]',
            'ring-offset-2',
            'ring-offset-[var(--wa-bg)]',
        );
        setTimeout(() => {
            el.classList.remove(
                'ring-2',
                'ring-[var(--wa-accent)]',
                'ring-offset-2',
                'ring-offset-[var(--wa-bg)]',
            );
        }, 1600);
        return;
    }
    showReplyJumpNotice(
        'Исходное сообщение не в загруженной части истории. Прокрутите вверх и подгрузите сообщения.',
    );
}

async function clearRoomPinned(): Promise<void> {
    const cid = props.selectedConversationId;
    if (!cid || roomPinSending.value) {
        return;
    }
    roomPinSending.value = true;
    try {
        await axios.post(route('organization.team-chat.api.pinned-message', cid), {
            team_message_id: null,
        });
        roomPinnedMessage.value = null;
    } catch {
        /* ignore */
    } finally {
        roomPinSending.value = false;
    }
}

async function setRoomPinnedForMessage(messageId: number): Promise<void> {
    const cid = props.selectedConversationId;
    if (!cid || roomPinSending.value || messageId < 1) {
        return;
    }
    roomPinSending.value = true;
    try {
        const { data } = await axios.post(route('organization.team-chat.api.pinned-message', cid), {
            team_message_id: messageId,
        });
        const p = data.room_pinned_message as TeamRoomPinned | null | undefined;
        roomPinnedMessage.value = p && typeof p.id === 'number' ? p : null;
    } catch {
        /* ignore */
    } finally {
        roomPinSending.value = false;
    }
}

async function loadMore() {
    const first = messages.value[0];
    if (!first || !hasMore.value || loading.value) return;
    const el = threadEl.value;
    const prev = el?.scrollHeight ?? 0;
    await fetchMessages(first.id);
    await nextTick();
    if (el) {
        el.scrollTop = el.scrollHeight - prev;
    }
}

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        void send();
    }
}

async function openForwardPicker(m: TeamMsg) {
    forwardSource.value = m;
    forwardCaption.value = '';
    forwardPickerOpen.value = true;
    forwardTargetsLoading.value = true;
    forwardTargets.value = [];
    try {
        const { data } = await axios.get(route('organization.team-chat.api.conversations'));
        const rows = (data.conversations ?? []) as { id: number; title: string; subtitle?: string | null }[];
        forwardTargets.value = rows.map((r) => ({
            id: r.id,
            title: r.title,
            subtitle: r.subtitle ?? null,
        }));
    } catch {
        forwardTargets.value = [];
    } finally {
        forwardTargetsLoading.value = false;
    }
}

function closeForwardPicker() {
    forwardPickerOpen.value = false;
    forwardSource.value = null;
    forwardCaption.value = '';
    forwardTargets.value = [];
}

async function submitForward(targetConversationId: number) {
    const src = forwardSource.value;
    if (!src || forwardSending.value) return;
    forwardSending.value = true;
    const clientMessageId = crypto.randomUUID();
    try {
        const payload: Record<string, unknown> = {
            forwarded_from_team_message_id: src.id,
            client_message_id: clientMessageId,
        };
        const cap = forwardCaption.value.trim();
        if (cap !== '') {
            payload.body = cap;
        }
        await axios.post(route('organization.team-chat.api.messages.store', targetConversationId), payload);
        closeForwardPicker();
        if (targetConversationId !== props.selectedConversationId) {
            router.visit(route('organization.team-chat.show', targetConversationId));
        } else {
            await fetchMessages();
            await nextTick(() => scrollToBottom());
        }
    } finally {
        forwardSending.value = false;
    }
}

onBeforeUnmount(() => {
    if (typingPingTimer) clearTimeout(typingPingTimer);
    typingPingTimer = null;
    if (deliverFlushTimer) clearTimeout(deliverFlushTimer);
    deliverFlushTimer = null;
    if (replyJumpNoticeTimer) clearTimeout(replyJumpNoticeTimer);
    replyJumpNoticeTimer = null;
    if (draftPersistTimer) clearTimeout(draftPersistTimer);
    if (props.selectedConversationId) {
        persistDraft(props.selectedConversationId, draft.value);
    }
    teardownEcho(props.selectedConversationId);
});
</script>

<template>
    <Head title="Чат организации" />
    <OrganizationLayout :departments="departments" :selected-department-id="null">
        <div class="flex h-full min-h-0 flex-col bg-[var(--wa-bg)] team-chat-main">
            <div
                v-if="!selectedConversationId"
                class="flex flex-1 flex-col items-center justify-center px-4 sm:px-6 text-center text-[var(--wa-text-secondary)]"
            >
                <p class="text-lg text-[var(--wa-text)] m-0 mb-2">Внутренний чат</p>
                <p class="text-sm m-0 max-w-md">
                    Выберите беседу слева или откройте вкладку «Сотрудники», чтобы написать коллеге.
                    Чаты отделов создаются автоматически; покинуть группу отдела нельзя — состав меняет администратор.
                </p>
            </div>
            <template v-else>
                <div class="flex flex-1 min-h-0 flex-col">
                    <div
                        v-if="conversationType === 'department' && roomPinnedMessage"
                        class="shrink-0 border-b border-[var(--wa-border)] bg-[var(--wa-selected)]/20 px-3 sm:px-4 py-2"
                    >
                        <div class="max-w-4xl mx-auto flex items-start gap-2 text-xs sm:text-sm">
                            <span class="shrink-0 pt-0.5" aria-hidden="true">📌</span>
                            <button
                                type="button"
                                class="min-w-0 flex-1 text-left rounded-lg px-2 py-1 -mx-2 -my-1 hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
                                title="Перейти к сообщению"
                                @click="scrollToQuotedParent(roomPinnedMessage.id)"
                            >
                                <div class="font-semibold text-[var(--wa-accent)]">{{ roomPinnedMessage.sender_name }}</div>
                                <div class="text-[var(--wa-text-secondary)] truncate mt-0.5">{{ roomPinnedMessage.body_preview }}</div>
                            </button>
                            <button
                                v-if="canPinRoomMessage"
                                type="button"
                                class="shrink-0 text-[var(--wa-text-secondary)] hover:text-[var(--wa-text)] px-2 py-1 rounded-lg disabled:opacity-40"
                                :disabled="roomPinSending"
                                @click="clearRoomPinned()"
                            >
                                Снять
                            </button>
                        </div>
                    </div>
                <div
                    ref="threadEl"
                    class="team-chat-thread flex-1 overflow-y-auto wa-scrollbar px-3 sm:px-4 py-3 min-h-0"
                    @scroll.passive="onThreadScroll"
                >
                    <div v-if="loading && messages.length === 0" class="text-sm text-[var(--wa-text-secondary)]">
                        Загрузка сообщений…
                    </div>
                    <div
                        v-if="replyJumpNotice"
                        class="mb-2 rounded-lg border border-[var(--wa-border)] bg-[var(--wa-panel)] px-3 py-2 text-xs text-[var(--wa-text-secondary)]"
                        role="status"
                    >
                        {{ replyJumpNotice }}
                    </div>
                    <TeamChatMessage
                        v-for="m in messages"
                        :key="m.id"
                        :message="m"
                        :is-outbound="m.sender_id === myUserId()"
                        :show-sender-name="conversationType === 'department' && m.sender_id !== myUserId()"
                        :receipt-label="outgoingDmReceiptLabel(m)"
                        :can-pin-room="canPinRoomMessage && conversationType === 'department'"
                        :is-room-pinned="roomPinnedMessage?.id === m.id"
                        :room-pin-sending="roomPinSending"
                        :can-create-task="conversationType === 'department' && !!departmentId"
                        :task-sending="taskFromMessageSending"
                        @reply="startReplyTo"
                        @forward="openForwardPicker"
                        @react="({ message, emoji }) => applyTeamReaction(message, emoji)"
                        @jump-to-reply="scrollToQuotedParent"
                        @pin-room="setRoomPinnedForMessage"
                        @unpin-room="clearRoomPinned"
                        @create-task="createTaskFromMessage"
                    />
                </div>
                <div
                    class="shrink-0 border-t border-[var(--wa-border)] px-3 pt-3 bg-[var(--wa-panel)] team-chat-input-bar"
                >
                    <div class="flex flex-col gap-2 max-w-4xl mx-auto pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                        <div
                            v-if="peerUser || (conversationType === 'department' && participants.filter((p) => p.id !== myUserId()).length)"
                            class="flex flex-wrap items-center gap-2 text-xs text-[var(--wa-text-secondary)]"
                        >
                            <span class="shrink-0">Упомянуть:</span>
                            <template v-if="peerUser">
                                <button
                                    type="button"
                                    class="rounded-full border px-3 py-1.5 min-h-[40px] transition-colors touch-manipulation"
                                    :class="mentionUserIds.includes(peerUser.id)
                                        ? 'border-[var(--wa-accent)] bg-[var(--wa-selected)] text-[var(--wa-text)]'
                                        : 'border-[var(--wa-border)]'"
                                    @click="toggleMentionPeer"
                                >
                                    @{{ peerUser.name }}
                                </button>
                            </template>
                            <template v-else>
                                <button
                                    v-for="p in participants.filter((x) => x.id !== myUserId())"
                                    :key="p.id"
                                    type="button"
                                    class="rounded-full border px-3 py-1.5 min-h-[40px] transition-colors max-w-[10rem] truncate touch-manipulation"
                                    :class="mentionUserIds.includes(p.id)
                                        ? 'border-[var(--wa-accent)] bg-[var(--wa-selected)] text-[var(--wa-text)]'
                                        : 'border-[var(--wa-border)]'"
                                    @click="toggleMentionColleague(p.id)"
                                >
                                    @{{ p.name }}
                                </button>
                            </template>
                            <button
                                v-if="participants.length > 0"
                                type="button"
                                class="rounded-full border border-[var(--wa-border)] px-3 py-1.5 min-h-[40px] touch-manipulation text-[var(--wa-text)] hover:bg-[var(--wa-selected)]"
                                @click="openCalendarFromChat"
                            >
                                📅 Встреча
                            </button>
                        </div>
                        <div
                            v-if="typingLabel"
                            class="text-xs text-[var(--wa-text-secondary)] px-0.5 min-h-[1.1rem]"
                            role="status"
                        >
                            {{ typingLabel }}
                        </div>
                        <div
                            v-if="replyToMessage"
                            class="flex items-start gap-2 rounded-lg border border-[var(--wa-accent)]/40 bg-[var(--wa-selected)]/25 px-3 py-2 text-xs text-[var(--wa-text)]"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="text-[var(--wa-text-secondary)] font-medium">Ответ на {{ replyToMessage.sender?.name ?? '…' }}</div>
                                <div class="truncate opacity-90 mt-0.5">{{ replyDraftPreview(replyToMessage) }}</div>
                            </div>
                            <button
                                type="button"
                                class="shrink-0 text-lg leading-none opacity-60 hover:opacity-100 px-1"
                                aria-label="Отменить ответ"
                                @click="clearReplyTo"
                            >×</button>
                        </div>
                        <div v-if="pendingAttachments.length" class="flex flex-wrap gap-1.5 text-xs">
                            <span
                                v-for="(f, fi) in pendingAttachments"
                                :key="`${f.name}-${fi}-${f.size}`"
                                class="inline-flex items-center gap-1 rounded-full border border-[var(--wa-border)] bg-[var(--wa-panel-header)] pl-2 pr-1 py-0.5 max-w-full"
                            >
                                <span class="truncate max-w-[10rem]">{{ f.name }}</span>
                                <button
                                    type="button"
                                    class="shrink-0 rounded-full px-1 opacity-60 hover:opacity-100"
                                    :aria-label="`Убрать ${f.name}`"
                                    @click="removePendingAttachment(fi)"
                                >×</button>
                            </span>
                        </div>
                        <div class="flex gap-2 items-end">
                        <input
                            ref="attachmentInputRef"
                            type="file"
                            class="sr-only"
                            multiple
                            accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx,.zip,.txt"
                            @change="onAttachmentInputChange"
                        />
                        <button
                            type="button"
                            class="shrink-0 min-h-[44px] min-w-[44px] rounded-lg border border-[var(--wa-border)] bg-[var(--wa-panel-header)] text-[var(--wa-accent)] flex items-center justify-center disabled:opacity-40 hover:bg-[var(--wa-selected)]/40 transition-colors"
                            :disabled="sending"
                            title="Прикрепить файл (до 5)"
                            aria-label="Прикрепить файл"
                            @click="openAttachmentPicker"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.14-7.693 7.693a2.25 2.25 0 0 0 3.182 3.182l7.693-7.693a.75.75 0 0 0-1.06-1.06l-7.693 7.693Z" />
                            </svg>
                        </button>
                        <textarea
                            v-model="draft"
                            rows="2"
                            class="flex-1 min-h-[44px] resize-none rounded-lg border border-[var(--wa-border)] bg-[var(--wa-panel-header)] px-3 py-2 text-base sm:text-sm text-[var(--wa-text)] focus:outline-none focus:ring-1 focus:ring-[var(--wa-accent)]"
                            placeholder="Сообщение…"
                            @input="onDraftInput"
                            @keydown="onKeydown"
                        />
                        <button
                            type="button"
                            class="shrink-0 min-h-[44px] min-w-[44px] px-3 sm:px-4 rounded-lg text-sm font-medium text-[var(--wa-unread-text,#0b0d0e)] bg-[var(--wa-accent)] disabled:opacity-50"
                            :disabled="sending || (!draft.trim() && pendingAttachments.length === 0)"
                            @click="send"
                        >
                            Отправить
                        </button>
                        </div>
                    </div>
                </div>
                </div>
                <Teleport to="body">
                    <div
                        v-if="forwardPickerOpen"
                        class="fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-black/40 p-3"
                        role="dialog"
                        aria-modal="true"
                        @click.self="closeForwardPicker"
                    >
                        <div
                            class="w-full max-w-md max-h-[85vh] overflow-hidden flex flex-col rounded-xl border border-[var(--wa-border)] bg-[var(--wa-panel)] text-[var(--wa-text)] shadow-xl"
                            @click.stop
                        >
                            <div class="px-4 py-3 border-b border-[var(--wa-border)] flex items-center justify-between gap-2">
                                <span class="text-sm font-semibold">Переслать в…</span>
                                <button type="button" class="text-lg leading-none opacity-60 hover:opacity-100 px-1" aria-label="Закрыть" @click="closeForwardPicker">×</button>
                            </div>
                            <div class="px-4 py-2 border-b border-[var(--wa-border)]">
                                <label class="block text-xs text-[var(--wa-text-secondary)] mb-1">Комментарий (необязательно)</label>
                                <textarea
                                    v-model="forwardCaption"
                                    rows="2"
                                    class="w-full resize-none rounded-lg border border-[var(--wa-border)] bg-[var(--wa-panel-header)] px-2 py-1.5 text-sm"
                                    placeholder="Добавить текст к пересылке…"
                                />
                            </div>
                            <div class="overflow-y-auto flex-1 min-h-0 px-2 py-2">
                                <div v-if="forwardTargetsLoading" class="text-sm text-[var(--wa-text-secondary)] px-2 py-4 text-center">
                                    Загрузка…
                                </div>
                                <button
                                    v-for="c in forwardTargets"
                                    :key="c.id"
                                    type="button"
                                    class="w-full text-left rounded-lg px-3 py-2.5 text-sm hover:bg-[var(--wa-selected)] border border-transparent hover:border-[var(--wa-border)] mb-1 disabled:opacity-50"
                                    :disabled="forwardSending"
                                    @click="submitForward(c.id)"
                                >
                                    <div class="font-medium">{{ c.title }}</div>
                                    <div v-if="c.subtitle" class="text-xs text-[var(--wa-text-secondary)]">{{ c.subtitle }}</div>
                                </button>
                                <p v-if="!forwardTargetsLoading && forwardTargets.length === 0" class="text-sm text-[var(--wa-text-secondary)] px-2 py-4 text-center">
                                    Нет доступных бесед
                                </p>
                            </div>
                        </div>
                    </div>
                </Teleport>
            </template>
        </div>
    </OrganizationLayout>
</template>

<style scoped>
.team-chat-main {
    /* iOS: дать скроллу и инпуту предсказуемую высоту во flex-колонке */
    min-height: 0;
}
</style>
