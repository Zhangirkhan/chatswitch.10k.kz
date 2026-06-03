<script setup lang="ts">
import AiWorkspaceRightPanel from '@/Components/AiChat/AiWorkspaceRightPanel.vue';
import AiWorkspaceVisualization, { type AiVisualization } from '@/Components/AiChat/AiWorkspaceVisualization.vue';
import type {
    ClientSummary,
    ResultTabId,
    TabCounts,
    WorkspaceResults,
} from '@/Components/AiChat/aiWorkspaceTypes';
import PanelResizeHandle from '@/Components/Ui/PanelResizeHandle.vue';
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useResizablePanelWidth } from '@/composables/useResizablePanelWidth';
import { useI18n } from '@/composables/useI18n';
import { useToastStore } from '@/stores/toast';
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import UserAvatar from '@/Components/UserAvatar.vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

const resultsOpenStorageKey = 'accel.ai-workspace.resultsOpen';

type AiTurn = {
    role: 'user' | 'assistant';
    content: string;
    ts: number;
    visualizations?: AiVisualization[];
};

type AiThread = {
    id: string;
    title: string;
    updatedAt: number;
    turns: AiTurn[];
    client_summary: ClientSummary | null;
    activeResultTab: ResultTabId;
    focusedContactId: number | null;
    summaryLoading: boolean;
} & WorkspaceResults;

type ThreadGroup = {
    label: string;
    threads: AiThread[];
};

const props = defineProps<{
    suggestions: string[];
}>();

const page = usePage<any>();
const { show: showToast } = useToastStore();
const { t } = useI18n();

const threads = ref<AiThread[]>([]);
const activeThreadId = ref<string | null>(null);
const draft = ref('');
const sending = ref(false);
const error = ref<string | null>(null);
const resultsOpen = ref(true);
const listEl = ref<HTMLDivElement | null>(null);
const textareaEl = ref<HTMLTextAreaElement | null>(null);
const hoveredTurn = ref<number | null>(null);

const threadsStorageKey = 'accel:ai-workspace:threads:v3';
const legacyStorageKey = 'accel:ai-workspace:v1';
const defaultResultTab: ResultTabId = 'contacts';

const {
    widthPx: sidebarWidthPx,
    isResizing: sidebarResizing,
    onResizePointerDown: onSidebarResize,
} = useResizablePanelWidth({
    storageKey: 'accel.ai-workspace.sidebarWidth',
    defaultWidth: 260,
    minWidth: 220,
    maxWidth: 360,
    edge: 'left',
});

const {
    widthPx: resultsWidthPx,
    isResizing: resultsResizing,
    onResizePointerDown: onResultsResize,
} = useResizablePanelWidth({
    storageKey: 'accel.ai-workspace.resultsWidth',
    defaultWidth: 340,
    minWidth: 280,
    maxWidth: 480,
    edge: 'right',
});

const userName = computed(() => page.props.auth?.user?.name ?? 'Вы');
const userAvatar = computed(() => page.props.auth?.user?.profile_photo_url ?? null);

const activeThread = computed(() => threads.value.find((t) => t.id === activeThreadId.value) ?? null);
const turns = computed(() => activeThread.value?.turns ?? []);
const contacts = computed(() => activeThread.value?.contacts ?? []);
const media = computed(() => activeThread.value?.media ?? []);
const messages = computed(() => activeThread.value?.messages ?? []);
const funnelDeals = computed(() => activeThread.value?.funnel_deals ?? []);
const calendarEvents = computed(() => activeThread.value?.calendar_events ?? []);
const departmentPosts = computed(() => activeThread.value?.department_posts ?? []);
const employees = computed(() => activeThread.value?.employees ?? []);
const clientSummary = computed(() => activeThread.value?.client_summary ?? null);
const focusedContactId = computed(() => activeThread.value?.focusedContactId ?? null);
const summaryLoading = computed(() => activeThread.value?.summaryLoading ?? false);
const activeResultTab = computed({
    get: () => activeThread.value?.activeResultTab ?? defaultResultTab,
    set: (tab: ResultTabId) => {
        const thread = activeThread.value;
        if (thread) {
            thread.activeResultTab = tab;
            persistThreads();
        }
    },
});
const workspaceResults = computed<WorkspaceResults>(() => ({
    contacts: contacts.value,
    media: media.value,
    messages: messages.value,
    funnel_deals: funnelDeals.value,
    calendar_events: calendarEvents.value,
    department_posts: departmentPosts.value,
    employees: employees.value,
}));
const tabCounts = computed<TabCounts>(() => ({
    contacts: contacts.value.length,
    media: media.value.length,
    messages: messages.value.length,
    calendar: calendarEvents.value.length,
    funnel: funnelDeals.value.length,
    tasks: departmentPosts.value.length,
    employees: employees.value.length,
}));
const resultsCount = computed(
    () => Object.values(tabCounts.value).reduce((sum, count) => sum + count, 0),
);
const isEmptyChat = computed(() => turns.value.length === 0 && !sending.value);

const threadGroups = computed<ThreadGroup[]>(() => {
    const sorted = [...threads.value].sort((a, b) => b.updatedAt - a.updatedAt);
    const startOfDay = (d: Date): Date => new Date(d.getFullYear(), d.getMonth(), d.getDate());
    const now = startOfDay(new Date());
    const dayMs = 86_400_000;

    const buckets: Record<string, AiThread[]> = {
        [t('aiChat.threadToday')]: [],
        [t('aiChat.threadYesterday')]: [],
        [t('aiChat.threadLast7')]: [],
        [t('aiChat.threadEarlier')]: [],
    };

    for (const thread of sorted) {
        const updated = startOfDay(new Date(thread.updatedAt));
        const diffDays = Math.floor((now.getTime() - updated.getTime()) / dayMs);

        if (diffDays <= 0) {
            buckets[t('aiChat.threadToday')].push(thread);
        } else if (diffDays === 1) {
            buckets[t('aiChat.threadYesterday')].push(thread);
        } else if (diffDays <= 7) {
            buckets[t('aiChat.threadLast7')].push(thread);
        } else {
            buckets[t('aiChat.threadEarlier')].push(thread);
        }
    }

    return Object.entries(buckets)
        .filter(([, items]) => items.length > 0)
        .map(([label, items]) => ({ label, threads: items }));
});

function newThreadId(): string {
    return `t_${Date.now()}_${Math.random().toString(36).slice(2, 9)}`;
}

function threadTitleFromMessage(text: string): string {
    const trimmed = text.trim().replace(/\s+/g, ' ');
    if (!trimmed) {
        return t('aiChat.newChat');
    }

    return trimmed.length > 42 ? `${trimmed.slice(0, 42)}…` : trimmed;
}

function emptyWorkspaceResults(): WorkspaceResults {
    return {
        contacts: [],
        media: [],
        messages: [],
        funnel_deals: [],
        calendar_events: [],
        department_posts: [],
        employees: [],
    };
}

function createThread(): AiThread {
    const thread: AiThread = {
        id: newThreadId(),
        title: t('aiChat.newChat'),
        updatedAt: Date.now(),
        turns: [],
        client_summary: null,
        activeResultTab: defaultResultTab,
        focusedContactId: null,
        summaryLoading: false,
        ...emptyWorkspaceResults(),
    };
    threads.value = [thread, ...threads.value];
    activeThreadId.value = thread.id;
    return thread;
}

function ensureActiveThread(): AiThread {
    const existing = activeThread.value;
    if (existing) {
        return existing;
    }

    return createThread();
}

function persistThreads(): void {
    try {
        window.localStorage.setItem(
            threadsStorageKey,
            JSON.stringify(threads.value.slice(0, 40).map((t) => ({
                ...t,
                turns: t.turns.slice(-30),
            }))),
        );
        if (activeThreadId.value) {
            window.localStorage.setItem(`${threadsStorageKey}:active`, activeThreadId.value);
        }
    } catch {
        /* ignore quota */
    }
}

function migrateLegacyStorage(): void {
    try {
        const raw = window.localStorage.getItem(legacyStorageKey);
        if (!raw) {
            return;
        }

        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed) || parsed.length === 0) {
            window.localStorage.removeItem(legacyStorageKey);
            return;
        }

        const turnsLegacy = parsed
            .filter((t) => t && (t.role === 'user' || t.role === 'assistant') && typeof t.content === 'string')
            .map((t) => ({
                role: t.role as 'user' | 'assistant',
                content: String(t.content),
                ts: typeof t.ts === 'number' ? t.ts : Date.now(),
            }));

        const firstUser = turnsLegacy.find((t) => t.role === 'user');
        const thread: AiThread = {
            id: newThreadId(),
            title: firstUser ? threadTitleFromMessage(firstUser.content) : t('aiChat.pastDialog'),
            updatedAt: Date.now(),
            turns: turnsLegacy,
            client_summary: null,
            activeResultTab: defaultResultTab,
            focusedContactId: null,
            summaryLoading: false,
            ...emptyWorkspaceResults(),
        };

        threads.value = [thread, ...threads.value];
        activeThreadId.value = thread.id;
        window.localStorage.removeItem(legacyStorageKey);
        persistThreads();
    } catch {
        window.localStorage.removeItem(legacyStorageKey);
    }
}

function normalizeThread(raw: AiThread): AiThread {
    const base = emptyWorkspaceResults();

    return {
        ...base,
        ...raw,
        contacts: Array.isArray(raw.contacts) ? raw.contacts : [],
        media: Array.isArray(raw.media) ? raw.media : [],
        messages: Array.isArray(raw.messages) ? raw.messages : [],
        funnel_deals: Array.isArray(raw.funnel_deals) ? raw.funnel_deals : [],
        calendar_events: Array.isArray(raw.calendar_events) ? raw.calendar_events : [],
        department_posts: Array.isArray(raw.department_posts) ? raw.department_posts : [],
        employees: Array.isArray(raw.employees) ? raw.employees : [],
        client_summary: raw.client_summary ?? null,
        activeResultTab: raw.activeResultTab ?? defaultResultTab,
        focusedContactId: raw.focusedContactId ?? null,
        summaryLoading: false,
    };
}

function pickDefaultTab(counts: TabCounts): ResultTabId {
    const order: ResultTabId[] = ['contacts', 'media', 'messages', 'calendar', 'funnel', 'tasks', 'employees'];
    for (const tab of order) {
        if (counts[tab] > 0) {
            return tab;
        }
    }

    return defaultResultTab;
}

function applyQueryResults(thread: AiThread, data: Record<string, unknown>): void {
    thread.contacts = Array.isArray(data.contacts) ? data.contacts as AiThread['contacts'] : [];
    thread.media = Array.isArray(data.media) ? data.media as AiThread['media'] : [];
    thread.messages = Array.isArray(data.messages) ? data.messages as AiThread['messages'] : [];
    thread.funnel_deals = Array.isArray(data.funnel_deals) ? data.funnel_deals as AiThread['funnel_deals'] : [];
    thread.calendar_events = Array.isArray(data.calendar_events) ? data.calendar_events as AiThread['calendar_events'] : [];
    thread.department_posts = Array.isArray(data.department_posts) ? data.department_posts as AiThread['department_posts'] : [];
    thread.employees = Array.isArray(data.employees) ? data.employees as AiThread['employees'] : [];
    thread.client_summary = (data.client_summary as ClientSummary | null | undefined) ?? null;
    thread.focusedContactId = thread.client_summary?.contact_id ?? thread.focusedContactId;

    const counts: TabCounts = {
        contacts: thread.contacts.length,
        media: thread.media.length,
        messages: thread.messages.length,
        calendar: thread.calendar_events.length,
        funnel: thread.funnel_deals.length,
        tasks: thread.department_posts.length,
        employees: thread.employees.length,
    };
    thread.activeResultTab = pickDefaultTab(counts);
}

async function loadClientSummary(contactId: number, chatId?: number | null): Promise<void> {
    const thread = ensureActiveThread();
    thread.summaryLoading = true;
    thread.focusedContactId = contactId;

    try {
        const { data } = await axios.get(route('ai-chat.client-summary', contactId), {
            params: chatId ? { chat_id: chatId } : {},
        });
        thread.client_summary = (data.client_summary as ClientSummary | null | undefined) ?? null;
        if (thread.client_summary) {
            thread.focusedContactId = thread.client_summary.contact_id;
        }
    } catch {
        showToast({ message: t('aiChat.summaryLoadFailed'), type: 'warning' });
    } finally {
        thread.summaryLoading = false;
        thread.updatedAt = Date.now();
        persistThreads();
    }
}

async function onSelectContact(contactId: number): Promise<void> {
    const contact = contacts.value.find((c) => c.id === contactId);
    await loadClientSummary(contactId, contact?.chat_id ?? null);
}

function onActiveTabChange(tab: ResultTabId): void {
    activeResultTab.value = tab;
}

function loadThreads(): void {
    try {
        const raw = window.localStorage.getItem(threadsStorageKey);
        if (raw) {
            const parsed = JSON.parse(raw) as AiThread[];
            if (Array.isArray(parsed)) {
                threads.value = parsed
                    .filter((t) => t && typeof t.id === 'string')
                    .map((t) => normalizeThread(t));
            }
        }

        const activeRaw = window.localStorage.getItem(`${threadsStorageKey}:active`);
        if (activeRaw && threads.value.some((t) => t.id === activeRaw)) {
            activeThreadId.value = activeRaw;
        } else if (threads.value.length > 0) {
            activeThreadId.value = threads.value[0].id;
        }

        if (threads.value.length === 0) {
            migrateLegacyStorage();
        }

        if (threads.value.length === 0) {
            createThread();
        } else if (!activeThreadId.value) {
            activeThreadId.value = threads.value[0].id;
        }
    } catch {
        threads.value = [];
        createThread();
    }
}

function scrollToBottom(): void {
    nextTick(() => {
        if (listEl.value) {
            listEl.value.scrollTop = listEl.value.scrollHeight;
        }
    });
}

function resizeTextarea(): void {
    const el = textareaEl.value;
    if (!el) {
        return;
    }
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 200)}px`;
}

function selectThread(id: string): void {
    activeThreadId.value = id;
    error.value = null;
    scrollToBottom();
    persistThreads();
}

function startNewChat(): void {
    createThread();
    error.value = null;
    draft.value = '';
    resizeTextarea();
    persistThreads();
    nextTick(() => textareaEl.value?.focus());
}

const pendingDeleteThreadId = ref<string | null>(null);
const pendingDeleteThreadTitle = computed(
    () => threads.value.find((t) => t.id === pendingDeleteThreadId.value)?.title ?? '',
);

function deleteThread(id: string, event?: Event): void {
    event?.stopPropagation();
    if (threads.value.findIndex((t) => t.id === id) === -1) {
        return;
    }

    pendingDeleteThreadId.value = id;
}

function confirmDeleteThread(): void {
    const id = pendingDeleteThreadId.value;
    pendingDeleteThreadId.value = null;
    if (id === null) {
        return;
    }

    const idx = threads.value.findIndex((t) => t.id === id);
    if (idx === -1) {
        return;
    }

    threads.value.splice(idx, 1);

    if (activeThreadId.value === id) {
        if (threads.value.length === 0) {
            createThread();
        } else {
            activeThreadId.value = threads.value[0].id;
        }
    }

    persistThreads();
}

function applySuggestion(text: string): void {
    draft.value = text;
    resizeTextarea();
    textareaEl.value?.focus();
}

async function copyTurn(content: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(content);
        showToast({ message: t('aiChat.copied'), type: 'info' });
    } catch {
        showToast({ message: t('aiChat.copyFailed'), type: 'warning' });
    }
}

async function send(): Promise<void> {
    const text = draft.value.trim();
    if (!text || sending.value) {
        return;
    }

    const thread = ensureActiveThread();
    error.value = null;
    sending.value = true;
    draft.value = '';
    resizeTextarea();

    thread.turns.push({ role: 'user', content: text, ts: Date.now() });
    if (thread.title === t('aiChat.newChat')) {
        thread.title = threadTitleFromMessage(text);
    }
    thread.updatedAt = Date.now();
    persistThreads();
    scrollToBottom();

    const history = thread.turns
        .slice(0, -1)
        .slice(-20)
        .map((t) => ({ role: t.role, content: t.content }));

    try {
        const { data } = await axios.post(route('ai-chat.query'), {
            message: text,
            history,
        });

        thread.turns.push({
            role: 'assistant',
            content: String(data.reply ?? ''),
            ts: Date.now(),
            visualizations: Array.isArray(data.visualizations) ? data.visualizations : [],
        });
        applyQueryResults(thread, data as Record<string, unknown>);
        thread.updatedAt = Date.now();

        if (!thread.client_summary && thread.contacts.length === 1) {
            await loadClientSummary(thread.contacts[0].id, thread.contacts[0].chat_id);
        }

        resultsOpen.value = true;
    } catch (e: unknown) {
        const msg =
            axios.isAxiosError(e) && e.response?.data && typeof e.response.data.message === 'string'
                ? e.response.data.message
                : t('aiChat.requestFailed');
        error.value = msg;
        thread.turns.push({
            role: 'assistant',
            content: msg,
            ts: Date.now(),
        });
        thread.updatedAt = Date.now();
    } finally {
        sending.value = false;
        persistThreads();
        scrollToBottom();
    }
}

function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        void send();
    }
}

watch(activeThreadId, () => {
    scrollToBottom();
});

onMounted(() => {
    try {
        const stored = window.localStorage.getItem(resultsOpenStorageKey);
        if (stored !== null) {
            resultsOpen.value = stored === '1';
        }
    } catch {
        /* ignore */
    }
    loadThreads();
    scrollToBottom();
});

watch(resultsOpen, (open) => {
    try {
        window.localStorage.setItem(resultsOpenStorageKey, open ? '1' : '0');
    } catch {
        /* ignore */
    }
});
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="t('nav.aiChat')" />

        <div
            class="ai-workspace"
            :class="{
                'ai-workspace--results-open': resultsOpen,
                'ai-workspace--sidebar-resizing': sidebarResizing,
                'ai-workspace--results-resizing': resultsResizing,
            }"
            :style="{
                '--ai-sidebar-width': sidebarWidthPx,
                '--ai-results-width': resultsWidthPx,
            }"
        >
            <aside class="ai-workspace__sidebar">
                <div class="ai-workspace__sidebar-head">
                    <button type="button" class="ai-workspace__new-chat" @click="startNewChat">
                        <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                        </svg>
                        {{ t('aiChat.newChat') }}
                    </button>
                </div>

                <div class="ai-workspace__thread-list wa-scrollbar">
                    <p v-if="threads.length === 0" class="ai-workspace__thread-empty">
                        {{ t('aiChat.historyEmpty') }}
                    </p>

                    <section
                        v-for="group in threadGroups"
                        :key="group.label"
                        class="ai-workspace__thread-group"
                    >
                        <h2 class="ai-workspace__thread-group-label">{{ group.label }}</h2>
                        <ul class="ai-workspace__thread-items">
                            <li v-for="thread in group.threads" :key="thread.id">
                                <button
                                    type="button"
                                    class="ai-workspace__thread-item"
                                    :class="{ 'is-active': thread.id === activeThreadId }"
                                    @click="selectThread(thread.id)"
                                >
                                    <span class="ai-workspace__thread-title">{{ thread.title }}</span>
                                    <span
                                        class="ai-workspace__thread-delete"
                                        role="button"
                                        tabindex="0"
                                        :aria-label="t('aiChat.deleteChatAria')"
                                        @click="deleteThread(thread.id, $event)"
                                        @keydown.enter.prevent="deleteThread(thread.id, $event)"
                                    >
                                        <svg viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                            <path d="M4 8h8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                                        </svg>
                                    </span>
                                </button>
                            </li>
                        </ul>
                    </section>
                </div>
            </aside>

            <PanelResizeHandle
                class="ai-workspace__sidebar-resize hidden lg:flex"
                :active="sidebarResizing"
                @pointerdown="onSidebarResize"
            />

            <section class="ai-workspace__main">
                <header class="ai-workspace__topbar">
                    <div class="ai-workspace__topbar-left">
                        <div class="min-w-0">
                            <h1 class="ai-workspace__topbar-title">
                                {{ activeThread?.title ?? t('nav.aiChat') }}
                            </h1>
                            <p class="ai-workspace__topbar-sub">
                                {{ t('aiChat.subtitle') }}
                            </p>
                        </div>
                    </div>

                    <div class="ai-workspace__topbar-actions">
                        <button
                            type="button"
                            class="ai-workspace__results-toggle lg:hidden"
                            :class="{ 'is-active': resultsOpen }"
                            @click="resultsOpen = !resultsOpen"
                        >
                            {{ t('aiChat.panel') }}
                        </button>
                    </div>
                </header>

                <div ref="listEl" class="ai-workspace__messages wa-scrollbar">
                    <div class="ai-workspace__messages-inner">
                        <Transition name="ai-hero">
                            <div v-if="isEmptyChat" class="ai-workspace__hero">
                                <div class="ai-workspace__hero-icon" aria-hidden="true">
                                    <svg viewBox="0 0 32 32" fill="none">
                                        <polygon
                                            points="16 4.5 19.48 11.55 27.26 12.68 21.63 18.17 22.96 25.92 16 22.26 9.04 25.92 10.37 18.17 4.74 12.68 12.52 11.55 16 4.5"
                                            stroke="currentColor"
                                            stroke-width="1.6"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                        />
                                    </svg>
                                </div>
                                <h2 class="ai-workspace__hero-title">{{ t('aiChat.heroTitle') }}</h2>
                                <p class="ai-workspace__hero-desc">
                                    {{ t('aiChat.heroHint') }}
                                </p>
                                <div class="ai-workspace__suggestions">
                                    <button
                                        v-for="(s, i) in suggestions"
                                        :key="i"
                                        type="button"
                                        class="ai-workspace__suggestion"
                                        :style="{ '--ai-stagger': `${i * 70}ms` }"
                                        @click="applySuggestion(s)"
                                    >
                                        {{ s }}
                                    </button>
                                </div>
                            </div>
                        </Transition>

                        <TransitionGroup
                            name="ai-msg"
                            tag="div"
                            class="ai-workspace__turn-list"
                        >
                            <article
                                v-for="(turn, idx) in turns"
                                :key="`${activeThreadId}-${turn.ts}-${idx}`"
                                class="ai-workspace__turn"
                                :class="turn.role === 'user' ? 'ai-workspace__turn--user' : 'ai-workspace__turn--assistant'"
                                @mouseenter="hoveredTurn = idx"
                                @mouseleave="hoveredTurn = null"
                            >
                                <div class="ai-workspace__turn-row">
                                    <div
                                        v-if="turn.role === 'assistant'"
                                        class="ai-workspace__turn-avatar"
                                        aria-hidden="true"
                                    >
                                        <span class="ai-workspace__assistant-mark">
                                            <svg viewBox="0 0 20 20" fill="none">
                                                <polygon
                                                    points="10 2.8 11.76 6.36 15.68 6.93 12.84 9.7 13.51 13.62 10 11.78 6.49 13.62 7.16 9.7 4.32 6.93 8.24 6.36 10 2.8"
                                                    stroke="currentColor"
                                                    stroke-width="1.35"
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                />
                                            </svg>
                                        </span>
                                    </div>

                                    <div class="ai-workspace__turn-body">
                                        <div class="ai-workspace__turn-meta">
                                            {{ turn.role === 'user' ? userName : t('aiChat.assistant') }}
                                        </div>
                                        <div class="ai-workspace__turn-bubble-wrap">
                                            <div
                                                class="ai-workspace__turn-bubble"
                                                :class="turn.role === 'user' ? 'ai-workspace__turn-bubble--user' : 'ai-workspace__turn-bubble--assistant'"
                                            >
                                                {{ turn.content }}
                                            </div>
                                            <AiWorkspaceVisualization
                                                v-for="viz in turn.visualizations ?? []"
                                                :key="viz.id"
                                                :item="viz"
                                            />
                                            <button
                                                type="button"
                                                class="ai-workspace__copy-btn"
                                                :class="{ 'is-visible': hoveredTurn === idx }"
                                                tabindex="-1"
                                                :title="t('aiChat.copy')"
                                                :aria-label="t('aiChat.copyAria')"
                                                @click="copyTurn(turn.content)"
                                            >
                                                <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                                    <rect
                                                        x="7"
                                                        y="3.5"
                                                        width="9"
                                                        height="11"
                                                        rx="1.5"
                                                        stroke="currentColor"
                                                        stroke-width="1.5"
                                                    />
                                                    <path
                                                        d="M5.5 6.5H5a1.5 1.5 0 0 0-1.5 1.5v8A1.5 1.5 0 0 0 5 17.5h8a1.5 1.5 0 0 0 1.5-1.5v-.5"
                                                        stroke="currentColor"
                                                        stroke-width="1.5"
                                                        stroke-linecap="round"
                                                    />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>

                                    <div
                                        v-if="turn.role === 'user'"
                                        class="ai-workspace__turn-avatar"
                                        aria-hidden="true"
                                    >
                                        <UserAvatar
                                            :name="userName"
                                            :avatar-url="userAvatar"
                                            :size="28"
                                        />
                                    </div>
                                </div>
                            </article>

                            <article
                                v-if="sending"
                                key="typing-indicator"
                                class="ai-workspace__turn ai-workspace__turn--assistant ai-workspace__turn--typing"
                            >
                                <div class="ai-workspace__turn-row">
                                    <div class="ai-workspace__turn-avatar" aria-hidden="true">
                                        <span class="ai-workspace__assistant-mark ai-workspace__assistant-mark--pulse">
                                            <svg viewBox="0 0 20 20" fill="none">
                                                <polygon
                                                    points="10 2.8 11.76 6.36 15.68 6.93 12.84 9.7 13.51 13.62 10 11.78 6.49 13.62 7.16 9.7 4.32 6.93 8.24 6.36 10 2.8"
                                                    stroke="currentColor"
                                                    stroke-width="1.35"
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                />
                                            </svg>
                                        </span>
                                    </div>
                                    <div class="ai-workspace__turn-body">
                                        <div class="ai-workspace__turn-bubble ai-workspace__turn-bubble--assistant ai-workspace__turn-bubble--typing">
                                            <div class="ai-workspace__typing">
                                                <span></span><span></span><span></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        </TransitionGroup>
                    </div>
                </div>

                <footer class="ai-workspace__composer-area">
                    <p v-if="error" class="ai-workspace__error">{{ error }}</p>
                    <div class="ai-workspace__composer-shell">
                        <div class="ai-workspace__composer">
                            <textarea
                                ref="textareaEl"
                                v-model="draft"
                                rows="1"
                                class="ai-workspace__composer-input"
                                :placeholder="t('aiChat.inputPlaceholder')"
                                :disabled="sending"
                                spellcheck="false"
                                @keydown="onKeydown"
                                @input="resizeTextarea"
                            />
                            <button
                                type="button"
                                class="ai-workspace__send-btn"
                                :disabled="sending || !draft.trim()"
                                :aria-label="t('aiChat.sendAria')"
                                @click="send"
                            >
                                <span v-if="sending" class="ai-workspace__send-spinner" aria-hidden="true"></span>
                                <svg v-else viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                    <path
                                        d="M10 4.5v11M10 4.5 5.5 9M10 4.5l4.5 4.5"
                                        stroke="currentColor"
                                        stroke-width="1.8"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    />
                                </svg>
                            </button>
                        </div>
                        <p class="ai-workspace__composer-hint">
                            {{ t('aiChat.inputHint') }}
                        </p>
                    </div>
                </footer>
            </section>

            <PanelResizeHandle
                v-if="resultsOpen"
                class="ai-workspace__results-resize hidden lg:flex"
                :active="resultsResizing"
                @pointerdown="onResultsResize"
            />

            <AiWorkspaceRightPanel
                v-if="resultsOpen"
                :open="resultsOpen"
                :summary="clientSummary"
                :summary-loading="summaryLoading"
                :results="workspaceResults"
                :active-tab="activeResultTab"
                :tab-counts="tabCounts"
                :focused-contact-id="focusedContactId"
                :contacts="contacts"
                :results-count="resultsCount"
                @close="resultsOpen = false"
                @update:active-tab="onActiveTabChange"
                @select-contact="onSelectContact"
            />
        </div>

        <DangerConfirmModal
            :open="pendingDeleteThreadId !== null"
            :title="t('aiChat.deleteTitle')"
            :description="pendingDeleteThreadTitle ? t('aiChat.deleteDescNamed', { title: pendingDeleteThreadTitle }) : t('aiChat.deleteDesc')"
            :confirm-label="t('common.delete')"
            @close="pendingDeleteThreadId = null"
            @confirm="confirmDeleteThread"
        />
    </AuthenticatedLayout>
</template>

<style scoped>
.ai-workspace {
    --ai-sidebar-width: 260px;
    --ai-results-width: 340px;
    --ai-header-height: 4rem;
    display: flex;
    flex: 1 1 auto;
    min-height: 0;
    min-width: 0;
    overflow: hidden;
    background: var(--wa-page-bg);
    color: var(--wa-text);
    position: relative;
}

.ai-workspace__sidebar {
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    width: var(--ai-sidebar-width);
    min-width: 0;
    background: var(--wa-panel-header);
    border-right: 1px solid var(--wa-sidebar-divider);
    z-index: 30;
}

.ai-workspace__sidebar-head {
    display: flex;
    align-items: center;
    gap: 8px;
    height: var(--ai-header-height);
    min-height: var(--ai-header-height);
    padding: 0 12px;
    border-bottom: 1px solid var(--wa-sidebar-divider);
    box-sizing: border-box;
    flex-shrink: 0;
}

.ai-workspace__new-chat {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    height: 2.5rem;
    min-height: 2.5rem;
    padding: 0 14px;
    border-radius: 999px;
    border: 1px solid var(--wa-control-rim);
    box-shadow: var(--wa-control-rim-shadow);
    background: var(--wa-control-surface);
    color: var(--wa-text);
    font-size: 0.875rem;
    font-weight: 600;
    transition: background-color 0.15s ease, border-color 0.15s ease;
}

.ai-workspace__new-chat svg {
    width: 1rem;
    height: 1rem;
}

.ai-workspace__new-chat:hover {
    border-color: var(--wa-control-rim-hover);
    background: color-mix(in srgb, var(--wa-accent) 8%, var(--wa-control-surface));
}

.ai-workspace__thread-list {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 8px;
}

.ai-workspace__thread-empty {
    padding: 12px;
    font-size: 0.75rem;
    color: var(--wa-text-secondary);
}

.ai-workspace__thread-group + .ai-workspace__thread-group {
    margin-top: 12px;
}

.ai-workspace__thread-group-label {
    padding: 4px 10px;
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--wa-text-secondary);
}

.ai-workspace__thread-items {
    list-style: none;
    margin: 0;
    padding: 0;
}

.ai-workspace__thread-item {
    display: flex;
    align-items: center;
    gap: 6px;
    width: 100%;
    padding: 9px 10px;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: var(--wa-text);
    text-align: left;
    cursor: pointer;
    transition:
        background-color 0.2s ease,
        transform 0.2s cubic-bezier(0.22, 1, 0.36, 1);
}

.ai-workspace__thread-item:hover {
    transform: translateX(2px);
    background: color-mix(in srgb, var(--wa-selected) 65%, transparent);
}

.ai-workspace__thread-item.is-active {
    background: var(--wa-selected);
}

.ai-workspace__thread-title {
    flex: 1;
    min-width: 0;
    font-size: 0.8125rem;
    line-height: 1.35;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.ai-workspace__thread-delete {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.5rem;
    height: 1.5rem;
    border-radius: 6px;
    color: var(--wa-text-secondary);
    opacity: 0;
    transition: opacity 0.12s ease, background-color 0.12s ease, color 0.12s ease;
}

.ai-workspace__thread-item:hover .ai-workspace__thread-delete,
.ai-workspace__thread-item.is-active .ai-workspace__thread-delete {
    opacity: 1;
}

.ai-workspace__thread-delete:hover {
    background: color-mix(in srgb, var(--wa-chroma-critical-fg) 12%, transparent);
    color: var(--wa-chroma-critical-fg);
}

.ai-workspace__thread-delete svg {
    width: 0.875rem;
    height: 0.875rem;
}

.ai-workspace__sidebar-resize,
.ai-workspace__results-resize {
    flex-shrink: 0;
}

.ai-workspace__main {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    min-width: 0;
    min-height: 0;
    background: var(--wa-page-bg);
}

.ai-workspace__topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    height: var(--ai-header-height);
    min-height: var(--ai-header-height);
    padding: 0 16px;
    border-bottom: 1px solid var(--wa-sidebar-divider);
    background: color-mix(in srgb, var(--wa-panel) 88%, var(--wa-page-bg));
    box-sizing: border-box;
    flex-shrink: 0;
}

.ai-workspace__topbar-left {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
    height: 100%;
}

.ai-workspace__topbar-title {
    font-size: 0.9375rem;
    font-weight: 650;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.ai-workspace__topbar-sub {
    margin-top: 1px;
    font-size: 0.6875rem;
    line-height: 1.2;
    color: var(--wa-text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.ai-workspace__topbar-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}

.ai-workspace__results-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: 34px;
    padding: 0 12px;
    border-radius: 999px;
    border: 1px solid var(--wa-control-rim);
    background: var(--wa-control-surface);
    color: var(--wa-text);
    font-size: 0.75rem;
    font-weight: 600;
}

.ai-workspace__results-toggle.is-active {
    border-color: var(--ui-accent-border);
    background: var(--ui-accent-soft);
    color: var(--wa-accent);
}

.ai-workspace__results-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.25rem;
    height: 1.25rem;
    padding: 0 5px;
    border-radius: 999px;
    background: var(--wa-accent);
    color: #fff;
    font-size: 0.625rem;
    font-weight: 700;
}

.ai-workspace__icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: var(--wa-text-secondary);
    cursor: pointer;
    transition: background-color 0.12s ease, color 0.12s ease;
}

.ai-workspace__icon-btn svg {
    width: 1.125rem;
    height: 1.125rem;
}

.ai-workspace__icon-btn:hover {
    background: var(--wa-rail-btn-hover);
    color: var(--wa-text);
}

.ai-workspace__messages {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
}

.ai-workspace__messages-inner {
    width: min(100%, 48rem);
    margin: 0 auto;
    padding: 24px 20px 32px;
}

.ai-workspace__hero {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 48px 12px 24px;
}

.ai-workspace__hero-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 3rem;
    height: 3rem;
    border-radius: 999px;
    background: color-mix(in srgb, #d4a72c 22%, var(--wa-panel));
    color: #d4a72c;
    margin-bottom: 16px;
    animation: ai-float 4s ease-in-out infinite;
}

.ai-workspace__hero-icon svg {
    width: 1.75rem;
    height: 1.75rem;
    filter: drop-shadow(0 1px 0 color-mix(in srgb, #fff 35%, transparent));
}

.ai-workspace__hero-title {
    font-size: clamp(1.35rem, 2.5vw, 1.75rem);
    font-weight: 650;
    letter-spacing: -0.02em;
}

.ai-workspace__hero-desc {
    margin-top: 8px;
    max-width: 34rem;
    font-size: 0.875rem;
    line-height: 1.55;
    color: var(--wa-text-secondary);
}

.ai-workspace__suggestions {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    width: 100%;
    margin-top: 24px;
}

.ai-workspace__suggestion {
    min-height: 72px;
    padding: 12px 14px;
    border-radius: 14px;
    border: 1px solid var(--wa-control-rim);
    box-shadow: var(--wa-control-rim-shadow);
    background: var(--wa-control-surface);
    color: var(--wa-text);
    font-size: 0.8125rem;
    line-height: 1.45;
    text-align: left;
    transition:
        border-color 0.22s ease,
        transform 0.22s cubic-bezier(0.22, 1, 0.36, 1),
        background-color 0.22s ease,
        box-shadow 0.22s ease;
    animation: ai-suggest-in 0.55s cubic-bezier(0.22, 1, 0.36, 1) backwards;
    animation-delay: var(--ai-stagger, 0ms);
}

.ai-workspace__suggestion:hover {
    border-color: var(--wa-control-rim-hover);
    background: color-mix(in srgb, var(--wa-accent) 6%, var(--wa-control-surface));
    transform: translateY(-2px);
    box-shadow:
        var(--wa-control-rim-shadow),
        0 8px 20px color-mix(in srgb, var(--wa-accent) 10%, transparent);
}

.ai-workspace__turn-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.ai-workspace__turn {
    width: 100%;
    padding-bottom: 18px;
}

.ai-workspace__turn-row {
    display: flex;
    align-items: flex-end;
    gap: 10px;
    max-width: 100%;
}

.ai-workspace__turn--assistant .ai-workspace__turn-row {
    justify-content: flex-start;
}

.ai-workspace__turn--user .ai-workspace__turn-row {
    justify-content: flex-end;
    flex-direction: row;
}

.ai-workspace__turn-avatar {
    flex-shrink: 0;
    padding-bottom: 2px;
}

.ai-workspace__turn-body {
    display: flex;
    flex-direction: column;
    min-width: 0;
    max-width: min(85%, 36rem);
}

.ai-workspace__turn--user .ai-workspace__turn-body {
    align-items: flex-end;
}

.ai-workspace__turn--assistant .ai-workspace__turn-body {
    align-items: flex-start;
}

.ai-workspace__assistant-mark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 999px;
    background: color-mix(in srgb, #d4a72c 20%, var(--wa-panel));
    color: #d4a72c;
    transition: transform 0.25s ease;
}

.ai-workspace__assistant-mark svg {
    width: 0.95rem;
    height: 0.95rem;
}

.ai-workspace__assistant-mark--pulse {
    animation: ai-pulse 1.4s ease-in-out infinite;
}

.ai-workspace__turn-meta {
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--wa-text-secondary);
    margin-bottom: 4px;
    padding: 0 4px;
}

.ai-workspace__turn-bubble {
    font-size: 0.9375rem;
    line-height: 1.6;
    white-space: pre-wrap;
    word-break: break-word;
    padding: 11px 14px;
    border-radius: 18px;
    transition: box-shadow 0.2s ease;
}

.ai-workspace__turn-bubble-wrap {
    position: relative;
    max-width: 100%;
}

.ai-workspace__turn-bubble--user {
    background: var(--wa-bubble-out);
    color: var(--wa-bubble-text-out, var(--wa-bubble-text));
    border-bottom-right-radius: 6px;
    box-shadow: 0 2px 10px color-mix(in srgb, var(--wa-accent) 14%, transparent);
}

.ai-workspace__turn-bubble--assistant {
    background: var(--wa-panel);
    color: var(--wa-text);
    border: 1px solid var(--wa-control-rim);
    box-shadow: var(--wa-control-rim-shadow);
    border-bottom-left-radius: 6px;
}

.ai-workspace__turn-bubble--typing {
    padding: 14px 16px;
}

.ai-workspace__turn--assistant .ai-workspace__turn-bubble:hover {
    box-shadow:
        var(--wa-control-rim-shadow),
        0 4px 14px color-mix(in srgb, var(--wa-text) 6%, transparent);
}

.ai-workspace__copy-btn {
    position: absolute;
    top: calc(100% + 2px);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.625rem;
    height: 1.625rem;
    padding: 0;
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: var(--wa-text-secondary);
    cursor: pointer;
    opacity: 0;
    pointer-events: none;
    transition:
        opacity 0.15s ease,
        color 0.15s ease,
        background-color 0.15s ease;
}

.ai-workspace__copy-btn svg {
    width: 0.875rem;
    height: 0.875rem;
}

.ai-workspace__turn--assistant .ai-workspace__copy-btn {
    left: 4px;
}

.ai-workspace__turn--user .ai-workspace__copy-btn {
    right: 4px;
}

.ai-workspace__copy-btn.is-visible {
    opacity: 1;
    pointer-events: auto;
}

.ai-workspace__copy-btn:hover {
    color: var(--wa-accent);
    background: color-mix(in srgb, var(--wa-accent) 10%, transparent);
}

.ai-workspace__typing {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    min-height: 24px;
}

.ai-workspace__typing span {
    width: 6px;
    height: 6px;
    border-radius: 999px;
    background: var(--wa-text-secondary);
    animation: ai-typing 1.2s ease-in-out infinite;
}

.ai-workspace__typing span:nth-child(2) {
    animation-delay: 0.15s;
}

.ai-workspace__typing span:nth-child(3) {
    animation-delay: 0.3s;
}

.ai-workspace__composer-area {
    flex-shrink: 0;
    padding: 12px 16px 16px;
    background: linear-gradient(to top, var(--wa-page-bg) 78%, transparent);
    animation: ai-composer-in 0.45s cubic-bezier(0.22, 1, 0.36, 1) backwards;
}

.ai-workspace__composer-shell {
    width: min(100%, 48rem);
    margin: 0 auto;
}

.ai-workspace__error {
    margin: 0 0 8px;
    font-size: 0.75rem;
    color: var(--wa-chroma-critical-fg);
}

.ai-workspace__composer {
    display: flex;
    align-items: flex-end;
    gap: 8px;
    padding: 10px 10px 10px 14px;
    border-radius: 24px;
    border: 1px solid var(--wa-control-rim);
    box-shadow: var(--wa-control-rim-shadow);
    background: var(--wa-panel);
    transition: border-color 0.25s ease;
    outline: none;
}

.ai-workspace__composer:focus-within {
    border-color: color-mix(in srgb, var(--wa-accent) 45%, var(--wa-control-rim));
    box-shadow: var(--wa-control-rim-shadow);
    outline: none;
}

.ai-workspace__composer-input {
    flex: 1;
    min-height: 24px;
    max-height: 200px;
    resize: none;
    border: 0;
    background: transparent;
    color: var(--wa-text);
    font-size: 0.9375rem;
    line-height: 1.5;
    outline: none !important;
    box-shadow: none !important;
    -webkit-appearance: none;
    appearance: none;
}

.ai-workspace__composer-input:focus,
.ai-workspace__composer-input:focus-visible,
.ai-workspace__composer-input:focus-within {
    outline: none !important;
    box-shadow: none !important;
    --tw-ring-shadow: 0 0 #0000 !important;
    --tw-ring-offset-shadow: 0 0 #0000 !important;
}

.ai-workspace__composer-input::placeholder {
    color: var(--wa-text-secondary);
}

.ai-workspace__send-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    flex-shrink: 0;
    border: 0;
    border-radius: 999px;
    background: var(--wa-accent);
    color: #fff;
    cursor: pointer;
    transition:
        opacity 0.2s ease,
        transform 0.22s cubic-bezier(0.22, 1, 0.36, 1),
        box-shadow 0.22s ease;
    box-shadow: 0 4px 12px color-mix(in srgb, var(--wa-accent) 30%, transparent);
}

.ai-workspace__send-btn:disabled {
    opacity: 0.35;
    cursor: not-allowed;
    box-shadow: none;
}

.ai-workspace__send-btn:not(:disabled):hover {
    transform: scale(1.06);
    box-shadow: 0 6px 16px color-mix(in srgb, var(--wa-accent) 38%, transparent);
}

.ai-workspace__send-btn:not(:disabled):active {
    transform: scale(0.96);
}

.ai-workspace__send-btn svg {
    width: 1rem;
    height: 1rem;
}

.ai-workspace__send-spinner {
    width: 0.95rem;
    height: 0.95rem;
    border-radius: 999px;
    border: 2px solid rgba(255, 255, 255, 0.35);
    border-top-color: #fff;
    animation: ai-spin 0.75s linear infinite;
}

.ai-workspace__composer-hint {
    margin: 8px 0 0;
    text-align: center;
    font-size: 0.6875rem;
    color: var(--wa-text-secondary);
}

.ai-workspace__results {
    display: none;
    flex-direction: column;
    flex-shrink: 0;
    width: var(--ai-results-width);
    min-width: 0;
    background: var(--wa-panel-header);
    border-left: 1px solid var(--wa-sidebar-divider);
}

.ai-workspace__results.is-open {
    display: flex;
}

.ai-workspace__results-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 8px;
    padding: 14px 14px 10px;
    border-bottom: 1px solid var(--wa-sidebar-divider);
}

.ai-workspace__results-title {
    font-size: 0.875rem;
    font-weight: 650;
}

.ai-workspace__results-sub {
    margin-top: 2px;
    font-size: 0.6875rem;
    color: var(--wa-text-secondary);
}

.ai-workspace__results-body {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 12px;
}

.ai-workspace__results-section + .ai-workspace__results-section {
    margin-top: 16px;
}

.ai-workspace__results-label {
    margin-bottom: 8px;
    padding: 0 2px;
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--wa-text-secondary);
}

.ai-workspace__result-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ai-workspace__result-card {
    padding: 10px 12px;
    border-radius: 12px;
    border: 1px solid var(--wa-control-rim);
    background: var(--wa-control-surface);
    box-shadow: var(--wa-control-rim-shadow);
    transition:
        transform 0.22s cubic-bezier(0.22, 1, 0.36, 1),
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}

.ai-workspace__result-card:hover {
    transform: translateY(-1px);
    border-color: var(--wa-control-rim-hover);
}

.ai-workspace__result-card--media {
    display: flex;
    gap: 10px;
    align-items: flex-start;
}

.ai-workspace__result-card-title {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--wa-text);
}

.ai-workspace__result-card-meta {
    margin-top: 3px;
    font-size: 0.6875rem;
    color: var(--wa-text-secondary);
}

.ai-workspace__result-card-badge {
    margin-top: 4px;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--wa-accent);
}

.ai-workspace__result-link {
    display: inline-block;
    margin-top: 8px;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--wa-accent);
}

.ai-workspace__media-thumb {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 2.75rem;
    height: 2.75rem;
    flex-shrink: 0;
    border-radius: 8px;
    overflow: hidden;
    background: var(--wa-rail-btn-hover);
    color: var(--wa-text-secondary);
    font-size: 0.625rem;
    text-decoration: none;
}

.ai-workspace__media-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

@media (max-width: 1023px) {
    .ai-workspace {
        --ai-sidebar-width: 220px;
    }

    .ai-workspace__sidebar-resize,
    .ai-workspace__results-resize {
        display: none !important;
    }

    .ai-workspace__results {
        position: absolute;
        top: 0;
        right: 0;
        bottom: 0;
        z-index: 25;
        width: min(100%, 22rem);
        transform: translateX(104%);
        transition: transform 0.22s ease;
        box-shadow: -8px 0 24px rgba(0, 0, 0, 0.18);
    }

    .ai-workspace__results.is-open {
        transform: translateX(0);
    }

    .ai-workspace__suggestions {
        grid-template-columns: 1fr;
    }

    .ai-workspace__topbar-sub {
        display: none;
    }
}

@keyframes ai-typing {
    0%,
    80%,
    100% {
        opacity: 0.35;
        transform: translateY(0);
    }
    40% {
        opacity: 1;
        transform: translateY(-2px);
    }
}

@keyframes ai-pulse {
    0%,
    100% {
        opacity: 0.65;
    }
    50% {
        opacity: 1;
    }
}

@keyframes ai-spin {
    to {
        transform: rotate(360deg);
    }
}

/* ─── Transitions ─── */
.ai-hero-enter-active {
    transition:
        opacity 0.45s cubic-bezier(0.22, 1, 0.36, 1),
        transform 0.45s cubic-bezier(0.22, 1, 0.36, 1);
}

.ai-hero-leave-active {
    transition:
        opacity 0.28s ease,
        transform 0.28s ease;
}

.ai-hero-enter-from,
.ai-hero-leave-to {
    opacity: 0;
    transform: translateY(12px) scale(0.98);
}

.ai-msg-enter-active {
    transition:
        opacity 0.38s cubic-bezier(0.22, 1, 0.36, 1),
        transform 0.38s cubic-bezier(0.22, 1, 0.36, 1);
}

.ai-msg-leave-active {
    transition:
        opacity 0.22s ease,
        transform 0.22s ease;
}

.ai-msg-enter-from,
.ai-msg-leave-to {
    opacity: 0;
}

.ai-workspace__turn--user.ai-msg-enter-from,
.ai-workspace__turn--user.ai-msg-leave-to {
    transform: translateX(18px) scale(0.97);
}

.ai-workspace__turn--assistant.ai-msg-enter-from,
.ai-workspace__turn--assistant.ai-msg-leave-to,
.ai-workspace__turn--typing.ai-msg-enter-from {
    transform: translateX(-18px) scale(0.97);
}

.ai-msg-move {
    transition: transform 0.32s cubic-bezier(0.22, 1, 0.36, 1);
}

@keyframes ai-suggest-in {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes ai-composer-in {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes ai-float {
    0%,
    100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-4px);
    }
}

@media (prefers-reduced-motion: reduce) {
    .ai-workspace__hero-icon,
    .ai-workspace__suggestion,
    .ai-workspace__composer-area,
    .ai-msg-enter-active,
    .ai-msg-leave-active,
    .ai-msg-move,
    .ai-hero-enter-active,
    .ai-hero-leave-active {
        animation: none !important;
        transition: none !important;
    }
}
</style>
