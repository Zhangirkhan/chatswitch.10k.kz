<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import OrganizationLayout from '@/Layouts/OrganizationLayout.vue';
import type { OrgDepartment } from '../Partials/OrganizationSidebar.vue';
import type { PageProps } from '@/types';

const DRAFTS_KEY = 'chatswitch:orgTeamChatDrafts:v1';

type TeamForward = {
    from_message_id: number;
    source_title: string;
    quote_sender_name: string;
    quote_body: string;
};

type TeamReplyTo = {
    id: number;
    sender_name: string;
    body_preview: string;
};

type TeamMsg = {
    id: number;
    team_conversation_id: number;
    parent_team_message_id?: number | null;
    sender_id: number;
    body: string;
    client_message_id?: string | null;
    mentioned_user_ids?: number[];
    mentioned_users?: { id: number; name: string }[];
    forward?: TeamForward | null;
    reply_to?: TeamReplyTo | null;
    created_at: string | null;
    sender: { id: number; name: string } | null;
};

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
const sending = ref(false);
const loading = ref(false);
const hasMore = ref(true);
const threadEl = ref<HTMLElement | null>(null);
const conversationType = ref<'direct' | 'department' | null>(null);
const peerLastReadMessageId = ref<number | null>(null);
const peerLastDeliveredMessageId = ref<number | null>(null);
/** В группе отдела: min(last_delivered) по остальным участникам — для подписи «Доставлено» ко всем. */
const othersMinDeliveredMessageId = ref<number | null>(null);
const participants = ref<TeamParticipant[]>([]);
const mentionUserIds = ref<number[]>([]);

const forwardPickerOpen = ref(false);
const forwardSource = ref<TeamMsg | null>(null);
const forwardCaption = ref('');
const forwardTargets = ref<TeamConvPick[]>([]);
const forwardTargetsLoading = ref(false);
const forwardSending = ref(false);

const replyToMessage = ref<TeamMsg | null>(null);

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
};

function applyReadMetaFromPayload(rm: TeamReadMetaPayload | undefined): void {
    const pr = rm?.peer_last_read_message_id;
    peerLastReadMessageId.value = typeof pr === 'number' && pr > 0 ? pr : null;
    const pd = rm?.peer_last_delivered_message_id;
    peerLastDeliveredMessageId.value = typeof pd === 'number' && pd > 0 ? pd : null;
    const om = rm?.others_min_last_delivered_message_id;
    othersMinDeliveredMessageId.value = typeof om === 'number' && om >= 0 ? om : null;
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
        const conv = data.conversation as { id?: number; type?: string } | undefined;
        const t = conv?.type;
        conversationType.value = t === 'direct' || t === 'department' ? t : null;
        applyReadMetaFromPayload(
            data.read_meta as TeamReadMetaPayload,
        );

        if (beforeId) {
            messages.value = [...incoming, ...messages.value];
        } else {
            messages.value = incoming;
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

/** Сегменты текста для подсветки @Имя по списку упомянутых (фаза 2 roadmap). */
type TeamBodySeg = { text: string; mentionUserId?: number };

function teamMessageBodySegments(body: string, mentioned: { id: number; name: string }[] | undefined): TeamBodySeg[] {
    const users = (mentioned ?? []).filter((u) => u.name.trim() !== '');
    if (!body || users.length === 0) {
        return [{ text: body }];
    }
    const sorted = [...users].sort((a, b) => b.name.length - a.name.length);
    const out: TeamBodySeg[] = [];
    let buf = '';
    const flushBuf = (): void => {
        if (buf !== '') {
            out.push({ text: buf });
            buf = '';
        }
    };
    let i = 0;
    while (i < body.length) {
        if (body[i] === '@') {
            let matched: { id: number; name: string } | null = null;
            for (const u of sorted) {
                const name = u.name;
                if (name === '') continue;
                if (body.startsWith(name, i + 1)) {
                    const after = i + 1 + name.length;
                    const ch = body[after];
                    if (ch !== undefined && /[\p{L}\p{N}_]/u.test(ch)) {
                        continue;
                    }
                    matched = u;
                    break;
                }
            }
            if (matched) {
                flushBuf();
                out.push({ text: `@${matched.name}`, mentionUserId: matched.id });
                i += 1 + matched.name.length;
                continue;
            }
        }
        buf += body[i];
        i++;
    }
    flushBuf();
    return out;
}

function mentionAppearsInBody(body: string, name: string): boolean {
    const needle = `@${name}`;
    if (name.trim() === '') {
        return false;
    }
    let idx = body.indexOf(needle);
    while (idx !== -1) {
        const after = idx + needle.length;
        const ch = body[after];
        if (ch === undefined || !/[\p{L}\p{N}_]/u.test(ch)) {
            return true;
        }
        idx = body.indexOf(needle, idx + 1);
    }
    return false;
}

function mentionedUsersNotInBody(m: TeamMsg): { id: number; name: string }[] {
    const users = m.mentioned_users ?? [];
    if (users.length === 0) {
        return [];
    }
    const body = m.body ?? '';
    if (body === '') {
        return users;
    }
    return users.filter((u) => !mentionAppearsInBody(body, u.name));
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
        messages.value = [...messages.value, m];
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
        if (conversationType.value !== 'direct') return;
        if (!lid) return;
        const cur = peerLastReadMessageId.value ?? 0;
        if (lid > cur) {
            peerLastReadMessageId.value = lid;
        }
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
        participants.value = [];
        conversationType.value = null;
        peerLastReadMessageId.value = null;
        peerLastDeliveredMessageId.value = null;
        othersMinDeliveredMessageId.value = null;
        if (!id) {
            draft.value = '';
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

async function send() {
    const cid = props.selectedConversationId;
    const text = draft.value.trim();
    if (!cid || !text || sending.value) return;
    sending.value = true;
    const clientMessageId = crypto.randomUUID();
    try {
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
                messages.value = [...messages.value, m];
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
                <div
                    ref="threadEl"
                    class="flex-1 overflow-y-auto wa-scrollbar px-3 sm:px-4 py-3 space-y-2 min-h-0"
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
                    <div
                        v-for="m in messages"
                        :key="m.id"
                        :data-team-msg-id="m.id"
                        class="max-w-[min(92%,28rem)] sm:max-w-[min(85%,28rem)] rounded-lg px-3 py-2.5 sm:py-2 text-sm border group/msg touch-manipulation"
                        :class="m.sender_id === myUserId()
                            ? 'ml-auto border-[var(--wa-accent)] bg-[var(--wa-selected)] text-[var(--wa-text)]'
                            : 'mr-auto border-[var(--wa-border)] bg-[var(--wa-panel-header)] text-[var(--wa-text)]'"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                        <div v-if="m.sender_id !== myUserId()" class="text-xs font-semibold text-[var(--wa-accent)] mb-1">
                            {{ m.sender?.name ?? '…' }}
                        </div>
                        <div
                            v-if="m.forward"
                            class="mb-2 rounded border-l-2 border-[var(--wa-accent)] bg-black/5 dark:bg-white/5 pl-2 py-1.5 pr-1 text-[0.75rem]"
                        >
                            <div class="text-[var(--wa-text-secondary)] font-medium">Переслано из «{{ m.forward.source_title }}»</div>
                            <div v-if="m.forward.quote_body" class="mt-0.5 opacity-90">
                                <span class="font-semibold text-[var(--wa-accent)]">{{ m.forward.quote_sender_name }}:</span>
                                {{ m.forward.quote_body }}
                            </div>
                        </div>
                        <button
                            v-if="m.reply_to"
                            type="button"
                            class="mb-2 w-full rounded border-l-2 border-[var(--wa-border)] bg-black/5 dark:bg-white/5 pl-2 py-1.5 pr-1 text-left text-[0.75rem] cursor-pointer transition-colors hover:bg-black/10 dark:hover:bg-white/10"
                            title="Перейти к сообщению"
                            @click="scrollToQuotedParent(m.reply_to.id)"
                        >
                            <div class="text-[var(--wa-text-secondary)]">
                                Ответ на
                                <span class="font-medium text-[var(--wa-accent)]">{{ m.reply_to.sender_name }}</span>
                            </div>
                            <div v-if="m.reply_to.body_preview" class="mt-0.5 opacity-90 truncate">{{ m.reply_to.body_preview }}</div>
                        </button>
                        <div v-if="m.body" class="whitespace-pre-wrap break-words">
                            <template
                                v-for="(seg, si) in teamMessageBodySegments(m.body, m.mentioned_users)"
                                :key="`${m.id}-b-${si}`"
                            >
                                <span
                                    v-if="seg.mentionUserId"
                                    class="team-chat-mention font-medium text-[var(--wa-accent)] bg-[var(--wa-accent)]/12 rounded px-0.5"
                                >{{ seg.text }}</span>
                                <span v-else>{{ seg.text }}</span>
                            </template>
                        </div>
                        <div
                            v-if="mentionedUsersNotInBody(m).length"
                            class="text-[0.65rem] mt-1 text-[var(--wa-accent)] opacity-90"
                        >
                            Упомянуты: {{ mentionedUsersNotInBody(m).map((u) => u.name).join(', ') }}
                        </div>
                            </div>
                            <div class="flex flex-col gap-0.5 shrink-0 items-center">
                            <button
                                v-if="!m.reply_to"
                                type="button"
                                class="min-h-[36px] min-w-[36px] sm:min-h-0 sm:min-w-0 flex items-center justify-center text-sm text-[var(--wa-accent)] opacity-80 hover:opacity-100 px-1 py-1 rounded-lg"
                                title="Ответить"
                                @click="startReplyTo(m)"
                            >
                                ↩
                            </button>
                            <button
                                type="button"
                                class="min-h-[36px] min-w-[36px] sm:min-h-0 sm:min-w-0 flex items-center justify-center text-sm text-[var(--wa-accent)] opacity-70 hover:opacity-100 px-1 py-1 rounded-lg active:opacity-100"
                                title="Переслать"
                                @click="openForwardPicker(m)"
                            >
                                →
                            </button>
                            </div>
                        </div>
                        <div class="text-[0.65rem] mt-1 opacity-70 flex items-center justify-end gap-2 flex-wrap">
                            <span
                                v-if="outgoingDmReceiptLabel(m) === 'read'"
                                class="text-[var(--wa-text-secondary)]"
                            >Прочитано</span>
                            <span
                                v-else-if="outgoingDmReceiptLabel(m) === 'delivered'"
                                class="text-[var(--wa-text-secondary)]"
                            >Доставлено</span>
                            <span
                                v-else-if="outgoingDmReceiptLabel(m) === 'sent'"
                                class="text-[var(--wa-text-secondary)]"
                            >Отправлено</span>
                            <span>
                                {{ m.created_at ? new Date(m.created_at).toLocaleString('ru-RU', { hour: '2-digit', minute: '2-digit' }) : '' }}
                            </span>
                        </div>
                    </div>
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
                        <div class="flex gap-2 items-end">
                        <textarea
                            v-model="draft"
                            rows="2"
                            class="flex-1 min-h-[44px] resize-none rounded-lg border border-[var(--wa-border)] bg-[var(--wa-panel-header)] px-3 py-2 text-base sm:text-sm text-[var(--wa-text)] focus:outline-none focus:ring-1 focus:ring-[var(--wa-accent)]"
                            placeholder="Сообщение…"
                            @keydown="onKeydown"
                        />
                        <button
                            type="button"
                            class="shrink-0 min-h-[44px] min-w-[44px] px-3 sm:px-4 rounded-lg text-sm font-medium text-[var(--wa-unread-text,#0b0d0e)] bg-[var(--wa-accent)] disabled:opacity-50"
                            :disabled="sending || !draft.trim()"
                            @click="send"
                        >
                            Отправить
                        </button>
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
