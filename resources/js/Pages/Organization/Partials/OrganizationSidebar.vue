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
import { useI18n } from '@/composables/useI18n';
import { useToastStore } from '@/stores/toast';
import { initialsFromName } from '@/utils/initials';

const { t } = useI18n();

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
const orgTasksEnabled = computed(() => Boolean(page.props.modules?.org_tasks ?? false));
const teamChatActive = computed(
    () => !orgTasksEnabled.value
        || (typeof page.url === 'string' && page.url.startsWith('/organization/chat')),
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

const teamDepartmentConversations = computed(() =>
    teamConversations.value.filter((c) => c.type === 'department'),
);

const teamDirectConversations = computed(() =>
    teamConversations.value.filter((c) => c.type === 'direct'),
);

const showTeamChatSections = computed(
    () =>
        conversationFilter.value === ''
        && teamDepartmentConversations.value.length > 0
        && teamDirectConversations.value.length > 0,
);

type TeamConversationSection = { key: string; label: string | null; items: TeamConvRow[] };

const teamConversationSections = computed((): TeamConversationSection[] => {
    if (!showTeamChatSections.value) {
        return [{ key: 'all', label: null, items: teamConversations.value }];
    }

    return [
        { key: 'department', label: t('organization.filterDepartment'), items: teamDepartmentConversations.value },
        { key: 'direct', label: t('organization.filterDirect'), items: teamDirectConversations.value },
    ];
});

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
        showToast({ message: msg || t('organization.openDmFailed'), type: 'warning' });
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
    const senderName = e?.message?.sender?.name ? String(e.message.sender.name) : t('organization.employeeFallback');
    const bodyRaw = typeof e?.message?.body === 'string' ? e.message.body : '';
    const body = bodyRaw.trim() ? bodyRaw.slice(0, 140) : t('organization.mentionFallback');
    const convId = Number(e?.conversation_id);
    const mid = Number(e?.message?.id);
    const tag =
        Number.isFinite(convId) && Number.isFinite(mid) && mid > 0 ? `team-mention-${convId}-${mid}` : undefined;
    try {
        new Notification(t('organization.mentionNotificationTitle', { sender: senderName }), { body, tag });
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
    const tenantId = Number(page.props.tenantCompanyId || 0);
    inboxEcho = Echo.private(`t.${tenantId}.team-inbox.${uid}`);
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
                    Accel
                </h1>
            </div>

            <div class="px-3 py-2 shrink-0">
                <div v-if="!teamChatActive" class="ui-search-shell">
                    <div class="absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none">
                        <svg class="w-4 h-4 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <input
                        v-model="search"
                        type="search"
                        :placeholder="t('organization.searchDept')"
                        class="ui-search-input pr-10"
                    />
                    <button
                        v-if="search"
                        type="button"
                        :title="t('organization.clearSearch')"
                        class="absolute right-3 top-1/2 -translate-y-1/2 w-6 h-6 rounded-full flex items-center justify-center text-[var(--wa-icon)] hover:bg-[var(--wa-selected)]"
                        @click="clearSearch"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div v-else class="ui-search-shell">
                    <div class="absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none">
                        <svg class="w-4 h-4 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <input
                        v-model="teamGlobalSearch"
                        type="search"
                        autocomplete="off"
                        :placeholder="t('organization.searchTeamChat')"
                        class="ui-search-input pr-10"
                    />
                    <button
                        v-if="teamGlobalSearch"
                        type="button"
                        :title="t('organization.clearSearch')"
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
                <UiPillNav v-if="orgTasksEnabled">
                    <Link
                        :href="route('organization.index')"
                        class="ui-pill-nav__item"
                        :class="{ 'is-active': !teamChatActive }"
                    >
                        {{ t('organization.tasksTab') }}
                    </Link>
                    <Link
                        :href="route('organization.team-chat.index')"
                        class="ui-pill-nav__item"
                        :class="{ 'is-active': teamChatActive }"
                    >
                        <span class="truncate">{{ t('organization.chatTab') }}</span>
                        <span
                            v-if="teamChatUnread > 0"
                            class="ui-pill-nav__badge"
                            :title="t('organization.teamChatUnread', { count: teamChatUnread })"
                        >{{ teamChatUnread > 99 ? '99+' : teamChatUnread }}</span>
                    </Link>
                </UiPillNav>
                <p
                    v-else
                    class="m-0 px-1 text-xs text-[var(--wa-text-secondary)] leading-snug"
                >
                    {{ t('organization.internalChat') }}
                </p>
                    <template v-if="teamChatActive">
                        <UiPillNav>
                            <button
                                type="button"
                                class="ui-pill-nav__item"
                                :class="{ 'is-active': chatSidebarMode === 'chats' }"
                                @click="chatSidebarMode = 'chats'"
                            >
                                {{ t('organization.conversations') }}
                            </button>
                            <button
                                type="button"
                                class="ui-pill-nav__item"
                                :class="{ 'is-active': chatSidebarMode === 'contacts' }"
                                @click="chatSidebarMode = 'contacts'"
                            >
                                {{ t('organization.people') }}
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
                                {{ t('organization.filterAll') }}
                            </button>
                            <button
                                type="button"
                                class="ui-chip shrink-0"
                                :class="{ 'is-active': conversationFilter === 'unread' }"
                                @click="setConvFilter('unread')"
                            >
                                {{ t('organization.filterUnread') }}
                            </button>
                            <button
                                type="button"
                                class="ui-chip shrink-0"
                                :class="{ 'is-active': conversationFilter === 'department' }"
                                @click="setConvFilter('department')"
                            >
                                {{ t('organization.filterDepartment') }}
                            </button>
                            <button
                                type="button"
                                class="ui-chip shrink-0"
                                :class="{ 'is-active': conversationFilter === 'direct' }"
                                @click="setConvFilter('direct')"
                            >
                                {{ t('organization.filterDirect') }}
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
                    {{ t('organization.noDepartmentsAvailable') }}
                </div>
                <Link
                    v-for="dept in flat"
                    :key="dept.id"
                    :href="route('organization.departments.show', dept.id)"
                    class="ui-org-sidebar-item"
                    :class="{ 'is-selected': dept.id === selectedDepartmentId }"
                    :style="{ paddingLeft: `${0.75 + dept.depth * 1.1}rem` }"
                >
                    <Avatar :name="dept.name" :size="40" variant="group" />
                    <div class="flex-1 min-w-0">
                        <div class="ui-org-sidebar-item__name truncate">{{ dept.name }}</div>
                        <div v-if="dept.description" class="ui-org-sidebar-item__meta truncate">{{ dept.description }}</div>
                    </div>
                    <div v-if="dept.posts_count > 0" class="flex items-center gap-1 shrink-0">
                        <span
                            v-if="dept.in_progress_count > 0"
                            class="ui-tab-badge ui-tab-badge--warn"
                            :title="t('organization.inProgress', { count: dept.in_progress_count })"
                        >{{ dept.in_progress_count > 99 ? '99+' : dept.in_progress_count }}</span>
                        <span
                            v-if="dept.open_count > 0"
                            class="ui-tab-badge ui-tab-badge--team"
                            :title="t('organization.openCount', { count: dept.open_count })"
                        >{{ dept.open_count > 99 ? '99+' : dept.open_count }}</span>
                    </div>
                </Link>

                <!-- Archive link -->
                <Link
                    :href="route('organization.archive')"
                    class="ui-org-archive-link"
                    :class="{ 'is-selected': archiveActive }"
                >
                    <div class="ui-page-header__icon ui-org-archive-link__icon">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8l1 12a2 2 0 002 2h8a2 2 0 002-2L19 8M10 12v4m4-4v4" />
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="ui-org-sidebar-item__name truncate">{{ t('organization.archiveTasks') }}</div>
                        <div class="ui-org-sidebar-item__meta">{{ t('organization.archiveMeta') }}</div>
                    </div>
                    <span v-if="totalArchived > 0" class="ui-tab-badge ui-tab-badge--neutral" :title="t('organization.archivedCount', { count: totalArchived })">
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
                        {{ t('organization.deptChatsFirst') }}
                    </p>
                    <button
                        v-if="showTeamMentionNotifPrompt"
                        type="button"
                        class="mt-1.5 text-left text-[0.65rem] text-[var(--wa-accent)] underline decoration-dotted hover:opacity-90"
                        @click="requestMentionBrowserNotifications"
                    >
                        {{ t('organization.enableMentionNotifications') }}
                    </button>
                    <div class="pt-1">
                        <div
                            v-if="teamGlobalSearchLoading"
                            class="text-xs text-[var(--wa-text-secondary)] mt-1.5"
                        >{{ t('organization.searchingShort') }}</div>
                        <div
                            v-else-if="teamGlobalSearch.trim().length >= 2 && (teamGlobalSearchConvHits.length || teamGlobalSearchMsgHits.length || teamGlobalSearchColleagueHits.length)"
                            class="ui-panel mt-2 max-h-48 overflow-y-auto text-left"
                        >
                            <div v-if="teamGlobalSearchColleagueHits.length" class="px-2 py-1 text-[0.65rem] uppercase tracking-wide text-[var(--wa-text-secondary)]">{{ t('organization.peopleSection') }}</div>
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
                            <div v-if="teamGlobalSearchConvHits.length" class="px-2 py-1 text-[0.65rem] uppercase tracking-wide text-[var(--wa-text-secondary)] border-t border-[var(--wa-border)]">{{ t('organization.conversations') }}</div>
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
                            >{{ t('organization.messages') }}</div>
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
                        >{{ t('organization.nothingFound') }}</div>
                    </div>
                </div>
                <div v-else class="px-3 py-2 shrink-0">
                    <div class="ui-search-shell">
                        <div class="absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none">
                            <svg class="w-4 h-4 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                        <input
                            v-model="contactSearch"
                            type="search"
                            :placeholder="t('organization.searchEmployee')"
                            class="ui-search-input pr-10"
                        />
                        <button
                            v-if="contactSearch"
                            type="button"
                            :title="t('organization.clearSearch')"
                            class="absolute right-3 top-1/2 -translate-y-1/2 w-6 h-6 rounded-full flex items-center justify-center text-[var(--wa-icon)] hover:bg-[var(--wa-selected)]"
                            @click="contactSearch = ''"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
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
                        <template v-for="section in teamConversationSections" :key="section.key">
                            <div
                                v-if="section.label"
                                class="team-chat-section-label"
                            >
                                {{ section.label }}
                            </div>
                            <div
                                v-for="c in section.items"
                                :key="c.id"
                                class="ui-team-chat-row"
                                :class="{ 'is-selected': c.id === selectedConversationId }"
                            >
                                <Link
                                    :href="route('organization.team-chat.show', c.id)"
                                    class="ui-team-chat-row__link"
                                >
                                    <Avatar
                                        :name="c.title"
                                        :size="40"
                                        :variant="c.type === 'department' ? 'group' : 'staff'"
                                        fallback-initials
                                    />
                                    <div class="flex-1 min-w-0">
                                        <div class="ui-org-sidebar-item__name truncate flex items-center gap-1">
                                            <span v-if="c.is_pinned" class="text-[var(--wa-accent)] shrink-0" :title="t('organization.pinned')">📌</span>
                                            <span class="truncate">{{ c.title }}</span>
                                        </div>
                                        <div v-if="c.last_message_preview" class="ui-org-sidebar-item__meta truncate">{{ c.last_message_preview }}</div>
                                        <div v-else-if="c.subtitle" class="ui-org-sidebar-item__meta truncate">{{ c.subtitle }}</div>
                                    </div>
                                    <span v-if="c.unread_count > 0" class="ui-tab-badge ui-tab-badge--team shrink-0">{{ c.unread_count > 99 ? '99+' : c.unread_count }}</span>
                                </Link>
                                <button
                                    type="button"
                                    class="ui-team-chat-row__pin"
                                    :title="c.is_pinned ? t('organization.unpin') : t('organization.pin')"
                                    :aria-label="c.is_pinned ? t('organization.unpin') : t('organization.pin')"
                                    @click.prevent.stop="toggleTeamPin(c)"
                                >
                                    <span class="text-base leading-none">{{ c.is_pinned ? '★' : '☆' }}</span>
                                </button>
                            </div>
                        </template>
                        <div v-if="!teamListLoading && teamConversations.length === 0" class="py-8 px-4 text-center text-sm text-[var(--wa-text-secondary)]">
                            <span v-if="conversationFilter === 'unread'">{{ t('organization.noUnread') }}</span>
                            <span v-else-if="conversationFilter === 'department'">{{ t('organization.noDeptChats') }}</span>
                            <span v-else-if="conversationFilter === 'direct'">{{ t('organization.noDirectChats') }}</span>
                            <span v-else>{{ t('organization.noConversationsHint') }}</span>
                        </div>
                    </template>
                    <template v-else>
                        <button
                            v-for="u in contacts"
                            :key="u.id"
                            type="button"
                            class="ui-org-sidebar-item w-full text-left border-0"
                            @click="openContactDm(u.id)"
                        >
                            <UserAvatar :name="u.name" :size="40" />
                            <div class="flex-1 min-w-0">
                                <div class="ui-org-sidebar-item__name truncate">{{ u.name }}</div>
                                <div v-if="u.email" class="ui-org-sidebar-item__meta truncate">{{ u.email }}</div>
                            </div>
                        </button>
                        <div v-if="contacts.length === 0" class="py-8 px-4 text-center text-sm text-[var(--wa-text-secondary)]">
                            {{ t('organization.noEmployees') }}
                        </div>
                    </template>
                </div>
                </UiViewTransition>
            </template>
        </div>
    </div>
</template>

<style scoped>
.ui-org-archive-link.is-selected {
    background: var(--wa-selected);
}

.team-chat-section-label {
    padding: 0.625rem 0.75rem 0.25rem;
    font-size: 0.65rem;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--wa-text-secondary);
}
.team-chat-section-label:not(:first-child) {
    margin-top: 0.25rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--wa-border);
}
</style>
