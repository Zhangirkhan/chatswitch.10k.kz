<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import SidebarSectionTabs from '@/Components/SidebarSectionTabs.vue';
import UiPillNav from '@/Components/Ui/UiPillNav.vue';
import UiViewTransition from '@/Components/Ui/UiViewTransition.vue';
import Avatar from '@/Components/Avatar.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import type { PageProps } from '@/types';
import { useToastStore } from '@/stores/toast';
import { initialsFromName } from '@/utils/initials';

const { show: showToast } = useToastStore();

export interface OrgDepartment {
    id: number;
    name: string;
    description: string | null;
    parent_id: number | null;
    open_count: number;
    in_progress_count: number;
    done_count: number;
    posts_count: number;
    archived_posts_count: number;
}

const props = defineProps<{
    departments: OrgDepartment[];
    selectedDepartmentId?: number | null;
    archiveActive?: boolean;
}>();

const page = usePage<PageProps>();
const teamChatActive = computed(
    () => typeof page.url === 'string' && page.url.startsWith('/organization/chat'),
);
const showTeamMentionNotifPrompt = computed((): boolean => {
    if (typeof window === 'undefined') return false;
    return 'Notification' in window && Notification.permission !== 'granted';
});
const selectedConversationId = computed<number | null>(() => {
    const v = page.props.selectedConversationId;
    return typeof v === 'number' ? v : null;
});
const teamChatUnread = computed(() => Number(page.props.teamChatUnreadCount ?? 0));

type TeamConvRow = {
    id: number;
    type: string;
    title: string;
    subtitle: string | null;
    unread_count: number;
    last_message_at: string | null;
    last_message_preview: string | null;
    is_pinned?: boolean;
};

type ContactRow = { id: number; name: string; email: string };

const teamConversations = ref<TeamConvRow[]>([]);
const contacts = ref<ContactRow[]>([]);
const chatSidebarMode = ref<'chats' | 'contacts'>('chats');
const conversationFilter = ref<'' | 'unread' | 'department' | 'direct'>('');
const teamListLoading = ref(false);
const contactSearch = ref('');

type TeamSearchConvHit = {
    id: number;
    type: string;
    title: string;
    subtitle: string | null;
    last_message_preview: string | null;
};
type TeamSearchMsgHit = {
    id: number;
    team_conversation_id: number;
    conversation_title: string;
    body_snippet: string;
    created_at: string | null;
    sender_name: string | null;
};
type TeamSearchColleagueHit = { id: number; name: string; email: string };

function userInitials(name?: string | null): string {
    return initialsFromName(name, 'С');
}

const teamGlobalSearch = ref('');
const teamGlobalSearchLoading = ref(false);
const teamGlobalSearchConvHits = ref<TeamSearchConvHit[]>([]);
const teamGlobalSearchMsgHits = ref<TeamSearchMsgHit[]>([]);
const teamGlobalSearchColleagueHits = ref<TeamSearchColleagueHit[]>([]);
let teamGlobalSearchTimer: ReturnType<typeof setTimeout> | null = null;
const lastTeamSearchQuery = ref('');

let contactSearchTimer: ReturnType<typeof setTimeout> | null = null;
let inboxEcho: any = null;

async function loadTeamConversations() {
    teamListLoading.value = true;
    try {
        const params: Record<string, string> = {};
        if (conversationFilter.value) {
            params.filter = conversationFilter.value;
        }
        const { data } = await axios.get(route('organization.team-chat.api.conversations'), { params });
        teamConversations.value = data.conversations ?? [];
    } catch {
        teamConversations.value = [];
    } finally {
        teamListLoading.value = false;
    }
}

function setConvFilter(f: '' | 'unread' | 'department' | 'direct') {
    conversationFilter.value = f;
    void loadTeamConversations();
}

function clearTeamGlobalSearch() {
    teamGlobalSearch.value = '';
    teamGlobalSearchConvHits.value = [];
    teamGlobalSearchMsgHits.value = [];
    teamGlobalSearchColleagueHits.value = [];
    lastTeamSearchQuery.value = '';
}

function scheduleTeamGlobalSearch() {
    if (teamGlobalSearchTimer) clearTimeout(teamGlobalSearchTimer);
    teamGlobalSearchTimer = setTimeout(() => {
        teamGlobalSearchTimer = null;
        void runTeamGlobalSearch();
    }, 320);
}

async function runTeamGlobalSearch() {
    const q = teamGlobalSearch.value.trim();
    if (q.length < 2) {
        teamGlobalSearchConvHits.value = [];
        teamGlobalSearchMsgHits.value = [];
        teamGlobalSearchColleagueHits.value = [];
        lastTeamSearchQuery.value = '';
        teamGlobalSearchLoading.value = false;
        return;
    }
    teamGlobalSearchLoading.value = true;
    try {
        const { data } = await axios.get(route('organization.team-chat.api.search'), { params: { q } });
        teamGlobalSearchConvHits.value = (data.conversations ?? []) as TeamSearchConvHit[];
        teamGlobalSearchMsgHits.value = (data.messages ?? []) as TeamSearchMsgHit[];
        teamGlobalSearchColleagueHits.value = (data.colleagues ?? []) as TeamSearchColleagueHit[];
    } catch {
        teamGlobalSearchConvHits.value = [];
        teamGlobalSearchMsgHits.value = [];
        teamGlobalSearchColleagueHits.value = [];
    } finally {
        teamGlobalSearchLoading.value = false;
        lastTeamSearchQuery.value = q;
    }
}

function openTeamSearchMessage(convId: number, messageId: number) {
    const path = route('organization.team-chat.show', convId);
    const u = new URL(path, window.location.origin);
    u.searchParams.set('highlight_message_id', String(messageId));
    router.visit(u.pathname + u.search);
}

async function toggleTeamPin(c: TeamConvRow) {
    try {
        await axios.post(route('organization.team-chat.api.pin', c.id), {
            pinned: !c.is_pinned,
        });
        await loadTeamConversations();
    } catch {
        /* ignore */
    }
}

async function loadContacts() {
    try {
        const { data } = await axios.get(route('organization.team-chat.api.contacts'), {
            params: { search: contactSearch.value.trim() || undefined },
        });
        contacts.value = data.contacts ?? [];
    } catch {
        contacts.value = [];
    }
}

function scheduleContactSearch() {
    if (contactSearchTimer) clearTimeout(contactSearchTimer);
    contactSearchTimer = setTimeout(() => {
        contactSearchTimer = null;
        void loadContacts();
    }, 200);
}

async function openContactDm(userId: number) {
    try {
        const { data } = await axios.post(route('organization.team-chat.api.direct'), { user_id: userId });
        const id = data.conversation?.id;
        if (id) {
            chatSidebarMode.value = 'chats';
            await loadTeamConversations();
            router.visit(route('organization.team-chat.show', id));
        }
    } catch (e: unknown) {
        const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message;
        showToast({ message: msg || 'Не удалось открыть личный чат.', type: 'warning' });
    }
}

function notifyTeamMentionIfNeeded(e: any): void {
    if (typeof window === 'undefined' || typeof document === 'undefined') return;
    if (document.visibilityState === 'visible') return;
    const uid = page.props.auth?.user?.id;
    if (uid == null) return;
    const idsRaw = e?.message?.mentioned_user_ids;
    if (!Array.isArray(idsRaw)) return;
    const ids = idsRaw.map((x: unknown) => Number(x)).filter((n) => n > 0);
    if (!ids.includes(Number(uid))) return;
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    const senderName = e?.message?.sender?.name ? String(e.message.sender.name) : 'Сотрудник';
    const bodyRaw = typeof e?.message?.body === 'string' ? e.message.body : '';
    const body = bodyRaw.trim() ? bodyRaw.slice(0, 140) : 'Вас упомянули во внутреннем чате.';
    const convId = Number(e?.conversation_id);
    const mid = Number(e?.message?.id);
    const tag =
        Number.isFinite(convId) && Number.isFinite(mid) && mid > 0 ? `team-mention-${convId}-${mid}` : undefined;
    try {
        new Notification(`${senderName} упомянул(а) вас`, { body, tag });
    } catch {
        /* ignore */
    }
}

async function requestMentionBrowserNotifications(): Promise<void> {
    if (typeof window === 'undefined' || !('Notification' in window)) return;
    try {
        await Notification.requestPermission();
    } catch {
        /* ignore */
    }
}

function setupTeamInboxEcho() {
    const Echo = (window as any).Echo;
    const uid = page.props.auth?.user?.id;
    if (!Echo || !uid) return;
    teardownTeamInboxEcho();
    inboxEcho = Echo.private(`team-inbox.${uid}`);
    inboxEcho.listen('.team.message', (e: any) => {
        void loadTeamConversations();
        notifyTeamMentionIfNeeded(e);
    });
}

function teardownTeamInboxEcho() {
    const Echo = (window as any).Echo;
    const uid = page.props.auth?.user?.id;
    if (Echo && inboxEcho && uid) {
        try {
            Echo.leave(`team-inbox.${uid}`);
        } catch {
            /* ignore */
        }
    }
    inboxEcho = null;
}

onMounted(() => {
    if (teamChatActive.value) {
        void loadTeamConversations();
        void loadContacts();
        setupTeamInboxEcho();
    }
});

watch(teamChatActive, (on) => {
    if (on) {
        void loadTeamConversations();
        void loadContacts();
        setupTeamInboxEcho();
    } else {
        teardownTeamInboxEcho();
    }
});

watch(chatSidebarMode, (m) => {
    if (m === 'contacts') void loadContacts();
});

watch(contactSearch, () => {
    if (chatSidebarMode.value === 'contacts') scheduleContactSearch();
});

watch(teamGlobalSearch, () => {
    if (teamChatActive.value && chatSidebarMode.value === 'chats') {
        scheduleTeamGlobalSearch();
    }
});

watch([teamChatActive, chatSidebarMode], () => {
    if (!teamChatActive.value || chatSidebarMode.value !== 'chats') {
        clearTeamGlobalSearch();
    }
});

onBeforeUnmount(() => teardownTeamInboxEcho());

const totalArchived = computed<number>(() =>
    props.departments.reduce((sum, d) => sum + (d.archived_posts_count ?? 0), 0),
);

const search = ref('');

interface DeptNode extends OrgDepartment {
    children: DeptNode[];
    depth: number;
}

const tree = computed<DeptNode[]>(() => {
    const byParent = new Map<number | null, OrgDepartment[]>();
    props.departments.forEach((d) => {
        const key = d.parent_id ?? null;
        if (!byParent.has(key)) byParent.set(key, []);
        byParent.get(key)!.push(d);
    });

    const allowedIds = new Set<number>(props.departments.map((d) => d.id));

    const build = (parentId: number | null, depth: number): DeptNode[] => {
        const list = byParent.get(parentId) || [];
        return list.map((d) => ({
            ...d,
            depth,
            children: build(d.id, depth + 1),
        }));
    };

    // Корневыми считаем те, у кого нет parent_id ИЛИ чей parent отсутствует
    // в выдаче (например, скрыт из-за прав доступа).
    const roots: DeptNode[] = [];
    const seenIds = new Set<number>();
    const walk = (parentId: number | null, depth: number): DeptNode[] => {
        const out: DeptNode[] = [];
        for (const d of byParent.get(parentId) || []) {
            if (seenIds.has(d.id)) continue;
            seenIds.add(d.id);
            out.push({ ...d, depth, children: walk(d.id, depth + 1) });
        }
        return out;
    };

    roots.push(...walk(null, 0));
    // Те, чей parent есть, но недоступен — поднимаем в корень.
    for (const d of props.departments) {
        if (seenIds.has(d.id)) continue;
        if (d.parent_id !== null && !allowedIds.has(d.parent_id)) {
            seenIds.add(d.id);
            roots.push({ ...d, depth: 0, children: build(d.id, 1) });
        }
    }

    return roots;
});

function flattenFiltered(nodes: DeptNode[]): DeptNode[] {
    const q = search.value.trim().toLowerCase();
    const out: DeptNode[] = [];
    const visit = (list: DeptNode[]) => {
        for (const node of list) {
            const matches = !q
                || node.name.toLowerCase().includes(q)
                || (node.description || '').toLowerCase().includes(q);
            const childrenMatches = node.children.length > 0
                && flattenFiltered(node.children).length > 0;
            if (matches || childrenMatches) {
                out.push(node);
            }
            visit(node.children);
        }
    };
    visit(nodes);
    return out;
}

const flat = computed<DeptNode[]>(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) {
        const out: DeptNode[] = [];
        const visit = (list: DeptNode[]) => {
            for (const node of list) {
                out.push(node);
                visit(node.children);
            }
        };
        visit(tree.value);
        return out;
    }

    return flattenFiltered(tree.value);
});

function clearSearch() {
    search.value = '';
}
</script>

<template>
    <div
        class="h-full w-full relative overflow-hidden border-r"
        :style="{ borderColor: 'var(--wa-sidebar-divider)' }"
    >
        <div class="w-full h-full flex flex-col bg-[var(--wa-panel)]">
            <!-- Panel header (same shell as clients) -->
            <div class="h-[60px] px-4 flex items-center justify-between shrink-0">
                <h1 class="min-w-0 text-[var(--wa-text)] text-xl font-normal m-0 truncate">
                    ChatSwitch
                </h1>
            </div>

            <!-- Search bar -->
            <div class="px-3 py-2 shrink-0">
                <div
                    v-if="!teamChatActive"
                    class="relative rounded-full"
                    :style="{ background: 'var(--wa-panel-header)' }"
                >
                    <div class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 flex items-center justify-center">
                        <svg
                            class="w-4 h-4 text-[var(--wa-icon)]"
                            fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Поиск отдела"
                        class="w-full pl-12 pr-10 py-[9px] bg-transparent rounded-full text-sm text-[var(--wa-text)] border-0 focus:ring-0 focus:outline-none"
                    />
                    <button
                        v-if="search"
                        @click="clearSearch"
                        class="absolute right-3 top-1/2 -translate-y-1/2 w-6 h-6 rounded-full flex items-center justify-center text-[var(--wa-icon)] hover:bg-[var(--wa-selected)]"
                        type="button"
                        title="Очистить"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div
                    v-else
                    class="relative rounded-full"
                    :style="{ background: 'var(--wa-panel-header)' }"
                >
                    <div class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 flex items-center justify-center">
                        <svg
                            class="w-4 h-4 text-[var(--wa-icon)]"
                            fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <input
                        v-model="teamGlobalSearch"
                        type="search"
                        autocomplete="off"
                        placeholder="Поиск по чатам, сообщениям и людям"
                        class="w-full pl-12 pr-10 py-[9px] bg-transparent rounded-full text-sm text-[var(--wa-text)] border-0 focus:ring-0 focus:outline-none"
                    />
                    <button
                        v-if="teamGlobalSearch"
                        type="button"
                        title="Очистить"
                        class="absolute right-3 top-1/2 -translate-y-1/2 w-6 h-6 rounded-full flex items-center justify-center text-[var(--wa-icon)] hover:bg-[var(--wa-selected)]"
                        @click="clearTeamGlobalSearch"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            <div class="ui-sidebar-filters">
                <SidebarSectionTabs active="organization" />

                <div class="ui-sidebar-filters__group">
                <UiPillNav>
                    <Link
                        :href="route('organization.index')"
                        class="ui-pill-nav__item"
                        :class="{ 'is-active': !teamChatActive }"
                    >
                        Задачи
                    </Link>
                    <Link
                        :href="route('organization.team-chat.index')"
                        class="ui-pill-nav__item"
                        :class="{ 'is-active': teamChatActive }"
                    >
                        <span class="truncate">Чат</span>
                        <span
                            v-if="teamChatUnread > 0"
                            class="ui-pill-nav__badge"
                            :title="`Непрочитанных в чате: ${teamChatUnread}`"
                        >{{ teamChatUnread > 99 ? '99+' : teamChatUnread }}</span>
                    </Link>
                </UiPillNav>
                    <template v-if="teamChatActive">
                        <UiPillNav>
                            <button
                                type="button"
                                class="ui-pill-nav__item"
                                :class="{ 'is-active': chatSidebarMode === 'chats' }"
                                @click="chatSidebarMode = 'chats'"
                            >
                                Беседы
                            </button>
                            <button
                                type="button"
                                class="ui-pill-nav__item"
                                :class="{ 'is-active': chatSidebarMode === 'contacts' }"
                                @click="chatSidebarMode = 'contacts'"
                            >
                                Сотрудники
                            </button>
                        </UiPillNav>
                        <div
                            v-if="chatSidebarMode === 'chats'"
                            class="ui-chip-row ui-chip-row--scroll wa-scrollbar"
                        >
                            <button
                                type="button"
                                class="ui-chip shrink-0"
                                :class="{ 'is-active': conversationFilter === '' }"
                                @click="setConvFilter('')"
                            >
                                Все
                            </button>
                            <button
                                type="button"
                                class="ui-chip shrink-0"
                                :class="{ 'is-active': conversationFilter === 'unread' }"
                                @click="setConvFilter('unread')"
                            >
                                Непрочит.
                            </button>
                            <button
                                type="button"
                                class="ui-chip shrink-0"
                                :class="{ 'is-active': conversationFilter === 'department' }"
                                @click="setConvFilter('department')"
                            >
                                Отделы
                            </button>
                            <button
                                type="button"
                                class="ui-chip shrink-0"
                                :class="{ 'is-active': conversationFilter === 'direct' }"
                                @click="setConvFilter('direct')"
                            >
                                Личные
                            </button>
                        </div>
                    </template>
                    <div v-else class="ui-sidebar-filters__chips-slot" aria-hidden="true" />
                </div>
            </div>

            <template v-if="!teamChatActive">
            <!-- Departments list -->
            <div class="flex-1 overflow-y-auto wa-scrollbar">
                <div
                    v-if="flat.length === 0"
                    class="py-6 text-sm text-[var(--wa-text-secondary)] px-6 text-center"
                >
                    Нет доступных отделов
                </div>
                <Link
                    v-for="dept in flat"
                    :key="dept.id"
                    :href="route('organization.departments.show', dept.id)"
                    class="dept-item"
                    :class="{ 'dept-item-selected': dept.id === selectedDepartmentId }"
                    :style="{ paddingLeft: `${0.75 + dept.depth * 1.1}rem` }"
                >
                    <Avatar :name="dept.name" :size="40" variant="group" />
                    <div class="flex-1 min-w-0">
                        <div class="dept-name truncate">{{ dept.name }}</div>
                        <div v-if="dept.description" class="dept-meta truncate">{{ dept.description }}</div>
                    </div>
                    <div v-if="dept.posts_count > 0" class="dept-badges">
                        <span
                            v-if="dept.in_progress_count > 0"
                            class="ui-tab-badge ui-tab-badge--warn"
                            :title="`В работе: ${dept.in_progress_count}`"
                        >{{ dept.in_progress_count > 99 ? '99+' : dept.in_progress_count }}</span>
                        <span
                            v-if="dept.open_count > 0"
                            class="ui-tab-badge ui-tab-badge--team"
                            :title="`Открыто: ${dept.open_count}`"
                        >{{ dept.open_count > 99 ? '99+' : dept.open_count }}</span>
                    </div>
                </Link>

                <!-- Archive link -->
                <Link
                    :href="route('organization.archive')"
                    class="dept-item archive-item"
                    :class="{ 'dept-item-selected': archiveActive }"
                >
                    <div class="dept-icon archive-icon">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8l1 12a2 2 0 002 2h8a2 2 0 002-2L19 8M10 12v4m4-4v4" />
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="dept-name truncate">Архив задач</div>
                        <div class="dept-meta">Завершённые задачи</div>
                    </div>
                    <span v-if="totalArchived > 0" class="ui-tab-badge ui-tab-badge--neutral" :title="`Архивных задач: ${totalArchived}`">
                        {{ totalArchived > 99 ? '99+' : totalArchived }}
                    </span>
                </Link>
            </div>
            </template>

            <template v-else>
                <UiViewTransition
                    :transition-key="chatSidebarMode"
                    panel-class="flex flex-1 flex-col min-h-0 overflow-hidden"
                    class="flex flex-1 flex-col min-h-0 overflow-hidden"
                >
                <div v-if="chatSidebarMode === 'chats'" class="px-3 pb-2 shrink-0 space-y-2">
                    <p class="text-xs text-[var(--wa-text-secondary)] m-0 leading-snug">
                        Группы отделов и личные сообщения
                    </p>
                    <button
                        v-if="showTeamMentionNotifPrompt"
                        type="button"
                        class="mt-1.5 text-left text-[0.65rem] text-[var(--wa-accent)] underline decoration-dotted hover:opacity-90"
                        @click="requestMentionBrowserNotifications"
                    >
                        Разрешить уведомления браузера при @упоминании (вкладка в фоне)
                    </button>
                    <div class="pt-1">
                        <div
                            v-if="teamGlobalSearchLoading"
                            class="text-xs text-[var(--wa-text-secondary)] mt-1.5"
                        >Поиск…</div>
                        <div
                            v-else-if="teamGlobalSearch.trim().length >= 2 && (teamGlobalSearchConvHits.length || teamGlobalSearchMsgHits.length || teamGlobalSearchColleagueHits.length)"
                            class="ui-panel mt-2 max-h-48 overflow-y-auto text-left"
                        >
                            <div v-if="teamGlobalSearchColleagueHits.length" class="px-2 py-1 text-[0.65rem] uppercase tracking-wide text-[var(--wa-text-secondary)]">Люди</div>
                            <button
                                v-for="h in teamGlobalSearchColleagueHits"
                                :key="'u-' + h.id"
                                type="button"
                                class="w-full text-left px-2 py-1.5 text-sm hover:bg-[var(--wa-selected)] border-b border-[var(--wa-border)]"
                                @click="openContactDm(h.id)"
                            >
                                <span class="font-medium text-[var(--wa-text)]">{{ h.name }}</span>
                                <span v-if="h.email" class="block text-xs text-[var(--wa-text-secondary)] truncate">{{ h.email }}</span>
                            </button>
                            <div v-if="teamGlobalSearchConvHits.length" class="px-2 py-1 text-[0.65rem] uppercase tracking-wide text-[var(--wa-text-secondary)] border-t border-[var(--wa-border)]">Беседы</div>
                            <Link
                                v-for="h in teamGlobalSearchConvHits"
                                :key="'c-' + h.id"
                                :href="route('organization.team-chat.show', h.id)"
                                class="block px-2 py-1.5 text-sm hover:bg-[var(--wa-selected)] border-b border-[var(--wa-border)] last:border-b-0"
                            >
                                <span class="font-medium text-[var(--wa-text)]">{{ h.title }}</span>
                                <span v-if="h.last_message_preview" class="block text-xs text-[var(--wa-text-secondary)] truncate">{{ h.last_message_preview }}</span>
                            </Link>
                            <div
                                v-if="teamGlobalSearchMsgHits.length"
                                :class="[
                                    'px-2 py-1 text-[0.65rem] uppercase tracking-wide text-[var(--wa-text-secondary)]',
                                    teamGlobalSearchColleagueHits.length || teamGlobalSearchConvHits.length
                                        ? 'border-t border-[var(--wa-border)]'
                                        : '',
                                ]"
                            >Сообщения</div>
                            <button
                                v-for="h in teamGlobalSearchMsgHits"
                                :key="'m-' + h.id"
                                type="button"
                                class="w-full text-left px-2 py-1.5 text-sm hover:bg-[var(--wa-selected)] border-b border-[var(--wa-border)] last:border-b-0"
                                @click="openTeamSearchMessage(h.team_conversation_id, h.id)"
                            >
                                <span class="text-xs text-[var(--wa-accent)]">{{ h.conversation_title }}</span>
                                <span class="block text-[var(--wa-text)] truncate">{{ h.body_snippet }}</span>
                                <span v-if="h.sender_name" class="text-xs text-[var(--wa-text-secondary)]">{{ h.sender_name }}</span>
                            </button>
                        </div>
                        <div
                            v-else-if="teamGlobalSearch.trim() === lastTeamSearchQuery && lastTeamSearchQuery.length >= 2 && !teamGlobalSearchLoading && !teamGlobalSearchConvHits.length && !teamGlobalSearchMsgHits.length && !teamGlobalSearchColleagueHits.length"
                            class="text-xs text-[var(--wa-text-secondary)] mt-1.5"
                        >Ничего не найдено</div>
                    </div>
                </div>
                <div v-else class="px-3 py-2 shrink-0">
                    <input
                        v-model="contactSearch"
                        type="text"
                        placeholder="Поиск сотрудника"
                        class="w-full px-3 py-2 rounded-lg text-sm text-[var(--wa-text)] border border-[var(--wa-border)] bg-[var(--wa-panel-header)] focus:outline-none focus:ring-1 focus:ring-[var(--wa-accent)]"
                    />
                </div>
                <div class="flex-1 overflow-y-auto wa-scrollbar">
                    <div v-if="chatSidebarMode === 'chats' && teamListLoading" class="px-3 py-3 space-y-3">
                        <div v-for="n in 6" :key="n" class="flex items-center gap-3 animate-pulse">
                            <div class="w-10 h-10 rounded-full shrink-0" :style="{ background: 'var(--wa-panel-header)' }" />
                            <div class="flex-1 space-y-2">
                                <div class="h-3 w-2/3 rounded" :style="{ background: 'var(--wa-panel-header)' }" />
                                <div class="h-2.5 w-1/2 rounded" :style="{ background: 'var(--wa-panel-header)' }" />
                            </div>
                        </div>
                    </div>
                    <template v-else-if="chatSidebarMode === 'chats'">
                        <div
                            v-for="c in teamConversations"
                            :key="c.id"
                            class="dept-item flex flex-row items-stretch gap-0 pr-0"
                            :class="{ 'dept-item-selected': c.id === selectedConversationId }"
                        >
                            <Link
                                :href="route('organization.team-chat.show', c.id)"
                                class="flex flex-1 min-w-0 items-center gap-3 text-inherit no-underline min-h-[44px]"
                            >
                                <Avatar
                                    :name="c.title"
                                    :size="40"
                                    :variant="c.type === 'department' ? 'group' : 'staff'"
                                    fallback-initials
                                    class="shrink-0"
                                />
                                <div class="flex-1 min-w-0">
                                    <div class="dept-name truncate flex items-center gap-1">
                                        <span v-if="c.is_pinned" class="text-[var(--wa-accent)] shrink-0" title="Закреплено">📌</span>
                                        <span class="truncate">{{ c.title }}</span>
                                    </div>
                                    <div v-if="c.last_message_preview" class="dept-meta truncate">{{ c.last_message_preview }}</div>
                                    <div v-else-if="c.subtitle" class="dept-meta truncate">{{ c.subtitle }}</div>
                                </div>
                                <span v-if="c.unread_count > 0" class="ui-tab-badge ui-tab-badge--team shrink-0">{{ c.unread_count > 99 ? '99+' : c.unread_count }}</span>
                            </Link>
                            <button
                                type="button"
                                class="shrink-0 w-10 flex items-center justify-center text-[var(--wa-text-secondary)] hover:text-[var(--wa-accent)] hover:bg-[var(--wa-selected)] border-0 bg-transparent rounded-none"
                                :title="c.is_pinned ? 'Открепить' : 'Закрепить'"
                                :aria-label="c.is_pinned ? 'Открепить' : 'Закрепить'"
                                @click.prevent.stop="toggleTeamPin(c)"
                            >
                                <span class="text-base leading-none">{{ c.is_pinned ? '★' : '☆' }}</span>
                            </button>
                        </div>
                        <div v-if="!teamListLoading && teamConversations.length === 0" class="py-8 px-4 text-center text-sm text-[var(--wa-text-secondary)]">
                            <span v-if="conversationFilter === 'unread'">Нет непрочитанных бесед.</span>
                            <span v-else-if="conversationFilter === 'department'">Нет чатов отделов в списке.</span>
                            <span v-else-if="conversationFilter === 'direct'">Нет личных бесед.</span>
                            <span v-else>Нет бесед. Откройте «Сотрудники», чтобы написать коллеге.</span>
                        </div>
                    </template>
                    <template v-else>
                        <button
                            v-for="u in contacts"
                            :key="u.id"
                            type="button"
                            class="dept-item w-full text-left border-0 bg-transparent"
                            @click="openContactDm(u.id)"
                        >
                            <UserAvatar :name="u.name" :size="40" />
                            <div class="flex-1 min-w-0">
                                <div class="dept-name truncate">{{ u.name }}</div>
                                <div class="dept-meta truncate">{{ u.email }}</div>
                            </div>
                        </button>
                        <div v-if="contacts.length === 0" class="py-8 px-4 text-center text-sm text-[var(--wa-text-secondary)]">
                            Нет сотрудников в компании или не задан company_id.
                        </div>
                    </template>
                </div>
                </UiViewTransition>
            </template>
        </div>
    </div>
</template>

<style scoped>
.dept-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.6rem 0.75rem;
    border-bottom: 1px solid var(--wa-border);
    color: var(--wa-text);
    text-decoration: none;
    transition: background-color 0.12s ease;
    cursor: pointer;
}
.dept-item:hover {
    background-color: var(--wa-panel-hover);
}
.dept-item-selected {
    background-color: var(--wa-selected);
}
.dept-icon {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: var(--wa-panel-header);
    color: var(--wa-icon);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.dept-icon--initials {
    color: var(--wa-accent);
    background:
        radial-gradient(circle at 30% 20%, color-mix(in srgb, var(--wa-accent) 28%, transparent), transparent 48%),
        color-mix(in srgb, var(--wa-accent) 14%, var(--wa-panel));
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.01em;
}
.dept-icon--group {
    color: #f59e0b;
    background:
        radial-gradient(circle at 30% 20%, color-mix(in srgb, #f59e0b 28%, transparent), transparent 48%),
        color-mix(in srgb, #f59e0b 16%, var(--wa-panel));
}
.dept-name {
    font-size: 0.95rem;
    line-height: 1.2;
    color: var(--wa-text);
}
.dept-meta {
    font-size: 0.8rem;
    color: var(--wa-text-secondary);
    margin-top: 2px;
}
.dept-badges {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
}
.archive-item {
    border-top: 1px solid var(--wa-border-strong);
    margin-top: 2px;
}
.archive-icon {
    background: color-mix(in srgb, var(--wa-accent) 15%, var(--wa-panel-header));
    color: var(--wa-accent);
}
.archive-badge {
    background: var(--wa-accent);
    color: var(--wa-accent-on);
}
</style>
