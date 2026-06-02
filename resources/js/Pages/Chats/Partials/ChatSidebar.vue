<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ref, watch, computed, onBeforeUnmount, onMounted } from 'vue';
import ChatListItem from './ChatListItem.vue';
import NewChatPanel from './NewChatPanel.vue';
import SidebarSectionTabs from '@/Components/SidebarSectionTabs.vue';
import UiPillNav from '@/Components/Ui/UiPillNav.vue';
import SkeletonBlock from '@/Components/SkeletonBlock.vue';
import type { Chat, Paginated } from '@/types';
import { onShortcut } from '@/composables/useKeyboardShortcuts';
import { useLiveUnreadCount } from '@/composables/useLiveUnreadCount';
import { appendChatListOwnership } from '@/utils/chatListOwnershipUrl';
import { useToastStore } from '@/stores/toast';
import axios from 'axios';

const { show: showToast } = useToastStore();

type ScopeKey = 'active' | 'archived';
type OwnershipKey = 'all' | 'mine';
type SegmentKey = 'favorites' | 'clients' | 'staff';
type ListFilterKey = 'attention' | null;

const props = withDefaults(
    defineProps<{
        chats: Paginated<Chat>;
        selectedChatId?: number;
        search?: string;
        scope?: ScopeKey;
        sidebarLazyLoad?: boolean;
    }>(),
    {
        scope: 'active',
        sidebarLazyLoad: false,
    },
);

const page = usePage<any>();
const archivedCount = computed<number>(() => Number(page.props.archivedCount || 0));
const user = computed(() => page.props.auth?.user);
const liveUnread = useLiveUnreadCount();
const roles = computed<string[]>(() => user.value?.roles || []);
const isAdmin = computed(() => roles.value.includes('administrator'));
const isManager = computed(() => roles.value.includes('manager'));
const canFilterByOwnership = computed(() => isAdmin.value || isManager.value);

const listOwnership = computed(() => (page.props.listOwnership === 'mine' ? 'mine' : 'all'));
const listFilter = computed<ListFilterKey>(() =>
    page.props.listFilter === 'attention' ? 'attention' : null,
);
const attentionChatsTotal = computed(() => Number(page.props.attentionChatsTotal ?? 0));

function chatsListRoute(): string {
    return props.scope === 'archived' ? route('chats.archived') : route('chats.index');
}

function chatsListQuery(overrides: Record<string, string | undefined> = {}): Record<string, string | undefined> {
    const q: Record<string, string | undefined> = { ...overrides };
    if (props.search) {
        q.search = props.search;
    }
    if (listOwnership.value === 'mine') {
        q.ownership = 'mine';
    }
    if (!Object.prototype.hasOwnProperty.call(overrides, 'filter') && listFilter.value === 'attention') {
        q.filter = 'attention';
    }
    return q;
}

const CHAT_LIST_RELOAD_PROPS = [
    'chats',
    'unreadChatsCount',
    'unreadChatsCountMine',
    'listOwnership',
    'listFilter',
    'attentionChatsTotal',
    'mineChatsTotal',
] as const;

let chatListReloadTimer: ReturnType<typeof setTimeout> | null = null;

function scheduleChatListReload(): void {
    if (chatListReloadTimer !== null) {
        clearTimeout(chatListReloadTimer);
    }
    const delayMs = listFilter.value === 'attention' ? 5000 : 1200;
    chatListReloadTimer = setTimeout(() => {
        chatListReloadTimer = null;
        router.reload({ only: [...CHAT_LIST_RELOAD_PROPS] });
    }, delayMs);
}

const activeListHref = computed(() => {
    const q = chatsListQuery();
    const params = new URLSearchParams();
    Object.entries(q).forEach(([k, v]) => {
        if (v !== undefined && v !== '') {
            params.set(k, v);
        }
    });
    const s = params.toString();
    const base = route('chats.index') as string;

    return s ? `${base}?${s}` : base;
});

const archivedListHref = computed(() => {
    const q = chatsListQuery();
    const params = new URLSearchParams();
    Object.entries(q).forEach(([k, v]) => {
        if (v !== undefined && v !== '') {
            params.set(k, v);
        }
    });
    const s = params.toString();
    const base = route('chats.archived') as string;

    return s ? `${base}?${s}` : base;
});

const SEGMENT_KEY = 'accel.chats.segment';
const searchQuery = ref(props.search || '');
const searchFocused = ref(false);

// ─── Live chat list (реальное время) ─────────────────────────────────────────
const localChats = ref<Chat[]>([...props.chats.data]);
const loadingMore = ref(false);
const initialSidebarLoading = ref(false);
const listNavigating = ref(false);
const loadedUntilPage = ref(props.chats.current_page);

const lastPage = computed(() => props.chats.last_page);
const hasMoreChats = computed(() => loadedUntilPage.value < lastPage.value);

watch(
    () => props.chats,
    (p) => {
        localChats.value = [...p.data];
        loadedUntilPage.value = p.current_page;
    },
);

// Sync live singleton when Inertia refreshes the unread count prop
watch(
    () => page.props.unreadChatsCount as number | undefined,
    (n) => {
        if (typeof n === 'number') liveUnread.set(n);
    },
);

function applyIncomingMessage(chatId: number, msg: {
    body?: string | null;
    direction?: 'inbound' | 'outbound' | null;
    created_at?: string | null;
    message_timestamp?: string | null;
    metadata?: { ai?: { generated?: boolean } } | null;
}): void {
    const idx = localChats.value.findIndex((c) => c.id === chatId);
    if (idx < 0) {
        // В режиме «Внимание» в списке только часть чатов — не перезагружаем страницу
        // на каждое сообщение в остальные диалоги (иначе UI зависает в reload-loop).
        if (listFilter.value === 'attention') {
            if (msg.direction === 'inbound') {
                liveUnread.increment();
                scheduleChatListReload();
            }
            return;
        }
        scheduleChatListReload();
        return;
    }

    const chat = { ...localChats.value[idx]! };
    chat.last_message_text = msg.body ?? chat.last_message_text;
    chat.last_message_direction = (msg.direction as Chat['last_message_direction']) ?? chat.last_message_direction;
    chat.last_message_at = msg.message_timestamp ?? msg.created_at ?? chat.last_message_at;
    chat.last_message_is_ai = msg.direction === 'outbound'
        ? msg.metadata?.ai?.generated === true
        : false;

    const isActiveChatId = props.selectedChatId === chatId;

    if (!isActiveChatId && msg.direction === 'inbound') {
        chat.unread_count = (chat.unread_count || 0) + 1;
        liveUnread.increment();
    }

    // Сдвигаем чат на верх (если не закреплён)
    const updated = localChats.value.filter((c) => c.id !== chatId);
    if (chat.is_pinned) {
        // Закреплённые остаются на своих местах — просто обновляем данные
        updated.splice(idx, 0, chat);
    } else {
        // Вставляем после последнего закреплённого
        const lastPinnedIdx = updated.reduce((last, c, i) => (c.is_pinned ? i : last), -1);
        updated.splice(lastPinnedIdx + 1, 0, chat);
    }
    localChats.value = updated;
}

async function fetchFeedPage(page: number, options: { replace?: boolean } = {}): Promise<void> {
    const { data: payload } = await axios.get<{
        data: Chat[];
        current_page: number;
        last_page: number;
    }>(route('chats.feed'), {
        params: {
            page,
            search: props.search || undefined,
            archived: props.scope === 'archived' ? 1 : 0,
            ownership: listOwnership.value === 'mine' ? 'mine' : undefined,
            filter: listFilter.value === 'attention' ? 'attention' : undefined,
            ensure_chat_id: props.selectedChatId || undefined,
        },
    });

    if (options.replace) {
        localChats.value = [...payload.data];
    } else {
        const seen = new Set(localChats.value.map((c) => c.id));
        for (const row of payload.data) {
            if (!seen.has(row.id)) {
                seen.add(row.id);
                localChats.value.push(row);
            }
        }
    }

    loadedUntilPage.value = payload.current_page;
}

async function onChatListScroll(e: Event): Promise<void> {
    const el = e.target as HTMLElement;
    if (!hasMoreChats.value || loadingMore.value || initialSidebarLoading.value) {
        return;
    }
    if (el.scrollHeight - el.scrollTop - el.clientHeight > 140) {
        return;
    }
    loadingMore.value = true;
    try {
        await fetchFeedPage(loadedUntilPage.value + 1);
    } catch {
        /* offline / 419 */
    } finally {
        loadingMore.value = false;
    }
}

// ─── Echo subscription ────────────────────────────────────────────────────────
let listEchoChannel: any = null;

function setupListEcho(): void {
    const Echo = (window as any).Echo;
    const uid = user.value?.id;
    if (!Echo || !uid) return;

    try {
        const tenantId = Number(page.props.tenantCompanyId || 0);
        listEchoChannel = Echo.private(`t.${tenantId}.chats.list.${uid}`);

        listEchoChannel.listen('.message.received', (e: any) => {
            const msg = e.message;
            if (!msg?.chat_id) return;
            applyIncomingMessage(msg.chat_id, {
                body: msg.body,
                direction: msg.direction,
                created_at: msg.created_at,
                message_timestamp: msg.message_timestamp,
            });
        });

        listEchoChannel.listen('.chats.notify', () => {
            scheduleChatListReload();
        });
    } catch {
        listEchoChannel = null;
    }
}

function teardownListEcho(): void {
    if (listEchoChannel && (window as any).Echo) {
        const uid = user.value?.id;
        if (uid) {
            const tenantId = Number(page.props.tenantCompanyId || 0);
            try { (window as any).Echo.leave(`t.${tenantId}.chats.list.${uid}`); } catch { /* ignore */ }
        }
    }
    listEchoChannel = null;
}
const activeSegment = ref<SegmentKey>('clients');
const headerMenuOpen = ref(false);
const showNewChat = ref(false);

// Dismissible info banners (remembered per-browser so they don't come back on reload)
const NOTIF_BANNER_KEY = 'accel.banner.notifications';
const PROMO_BANNER_KEY = 'accel.banner.promo';
/** Баннер запроса разрешений на уведомления */
const SHOW_NOTIFICATIONS_MUTED_BANNER = true;
/** Пока скрыт промо-баннер (Facebook / Instagram) */
const SHOW_PROMO_BANNER = false;
const notifBannerOpen = ref(true);
const promoBannerOpen = ref(true);
onMounted(() => {
    if (typeof window === 'undefined') return;
    if (SHOW_NOTIFICATIONS_MUTED_BANNER) {
        const dismissed = localStorage.getItem(NOTIF_BANNER_KEY) === 'dismissed';
        const alreadyGranted = typeof Notification !== 'undefined' && Notification.permission === 'granted';
        notifBannerOpen.value = !dismissed && !alreadyGranted;
    } else {
        notifBannerOpen.value = false;
    }
    if (SHOW_PROMO_BANNER) {
        promoBannerOpen.value = localStorage.getItem(PROMO_BANNER_KEY) !== 'dismissed';
    } else {
        promoBannerOpen.value = false;
    }

    const storedSegment = localStorage.getItem(SEGMENT_KEY);
    if (storedSegment === 'favorites' || storedSegment === 'clients' || storedSegment === 'staff') {
        activeSegment.value = storedSegment;
    }

    removeListStart = router.on('start', (event) => {
        const url = event.detail.visit.url;
        const path = (typeof url === 'string' ? url : url.pathname).split('?')[0] ?? '';
        if (path.includes('/chats')) {
            listNavigating.value = true;
        }
    });
    removeListFinish = router.on('finish', () => {
        listNavigating.value = false;
    });
});

// On first mount, pull group chats for connected numbers so they appear in the list.
onMounted(async () => {
    // Инициализируем живой счётчик из Inertia-пропов (если ещё не был задан)
    if (!liveUnread.initialized()) {
        liveUnread.init(Number(page.props.unreadChatsCount || 0));
    }

    if (props.sidebarLazyLoad && props.chats.total > props.chats.data.length) {
        initialSidebarLoading.value = true;
        try {
            await fetchFeedPage(1, { replace: true });
        } catch {
            /* offline / 419 */
        } finally {
            initialSidebarLoading.value = false;
        }
    }

    // Echo — подписываемся сразу или ждём инициализации
    if ((window as any).Echo) {
        setupListEcho();
    } else {
        let waited = 0;
        const iv = setInterval(() => {
            waited += 300;
            if ((window as any).Echo) {
                clearInterval(iv);
                setupListEcho();
            } else if (waited >= 15_000) {
                clearInterval(iv);
            }
        }, 300);
    }

    let hiddenAt: number | null = null;
    const onVisibility = () => {
        if (typeof document === 'undefined') {
            return;
        }
        if (document.visibilityState === 'hidden') {
            hiddenAt = Date.now();
            return;
        }
        if (hiddenAt !== null && Date.now() - hiddenAt > 3000) {
            scheduleChatListReload();
        }
        hiddenAt = null;
    };
    document.addEventListener('visibilitychange', onVisibility);
    onBeforeUnmount(() => document.removeEventListener('visibilitychange', onVisibility));

    const syncGroupsKey = 'accel:sync-groups-session';
    if (!sessionStorage.getItem(syncGroupsKey)) {
        try {
            await axios.post(route('chats.sync-groups'));
            sessionStorage.setItem(syncGroupsKey, '1');
            router.reload({ only: [...CHAT_LIST_RELOAD_PROPS] });
        } catch {
            // ignore (offline / service not ready)
        }
    }
});

watch(activeSegment, (val) => {
    if (typeof window !== 'undefined') {
        localStorage.setItem(SEGMENT_KEY, val);
    }
});
function dismissNotifBanner() {
    notifBannerOpen.value = false;
    localStorage.setItem(NOTIF_BANNER_KEY, 'dismissed');
}

async function enableNotifications() {
    if (typeof window === 'undefined' || !('Notification' in window)) return;
    if (Notification.permission === 'denied') {
        showToast({
            message: 'Браузер заблокировал уведомления. Снимите запрет в настройках сайта и перезагрузите страницу.',
            type: 'warning',
            duration: 6000,
        });
        return;
    }
    const result = await Notification.requestPermission();
    if (result === 'granted') {
        // Включаем флаг в localStorage (тот же ключ, что читает useChatsListDesktopNotifications)
        try { localStorage.setItem('accel.settings.notifications.enabled', 'true'); } catch { /**/ }
        dismissNotifBanner();
    }
}
function dismissPromoBanner() {
    promoBannerOpen.value = false;
    localStorage.setItem(PROMO_BANNER_KEY, 'dismissed');
}

let searchTimeout: ReturnType<typeof setTimeout>;

watch(searchQuery, (val) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(chatsListRoute(), chatsListQuery({ search: val || undefined }), {
            preserveState: true,
            preserveScroll: true,
            only: ['chats', 'unreadChatsCount', 'unreadChatsCountMine', 'listOwnership', 'listFilter', 'attentionChatsTotal', 'mineChatsTotal'],
        });
    }, 300);
});

const ownershipFilteredChats = computed(() => localChats.value);

/** Последнее сообщение от живого сотрудника — чат в «Сотрудники» (AI-ответы остаются в «Клиенты»). */
function isStaffLastMessage(chat: Chat): boolean {
    return chat.last_message_direction === 'outbound' && chat.last_message_is_ai !== true;
}

const filteredChats = computed(() => {
    let list = ownershipFilteredChats.value;
    if (listFilter.value === 'attention') {
        return [...list].sort((a, b) => {
            const ad = new Date((a.last_message_at || (a as any).created_at || '') as any).getTime() || 0;
            const bd = new Date((b.last_message_at || (b as any).created_at || '') as any).getTime() || 0;
            return bd - ad;
        });
    }
    if (activeSegment.value === 'favorites') {
        list = list.filter((c) => c.is_pinned || c.is_favorite);
    } else if (activeSegment.value === 'clients') {
        // Клиенты: последнее сообщение от клиента (или ещё нет переписки).
        list = list.filter((c) => !isStaffLastMessage(c));
    } else {
        // Сотрудники: последнее сообщение от компании (оператор / AI).
        list = list.filter((c) => isStaffLastMessage(c));
    }
    // Always keep pinned chats on top (WhatsApp-like), even if backend order
    // is affected by cached props / partial reloads.
    return [...list].sort((a, b) => {
        const ap = a.is_pinned ? 1 : 0;
        const bp = b.is_pinned ? 1 : 0;
        if (bp !== ap) return bp - ap;
        const ad = new Date((a.last_message_at || (a as any).created_at || '') as any).getTime() || 0;
        const bd = new Date((b.last_message_at || (b as any).created_at || '') as any).getTime() || 0;
        return bd - ad;
    });
});

const favoritesTotal = computed(() =>
    ownershipFilteredChats.value.filter((c) => c.is_pinned || c.is_favorite).length
);
const clientsTotal = computed(() =>
    ownershipFilteredChats.value.filter((c) => !isStaffLastMessage(c)).length,
);
const staffTotal = computed(() => ownershipFilteredChats.value.filter((c) => isStaffLastMessage(c)).length);
const mineChatsTotal = computed(() => Number(page.props.mineChatsTotal ?? 0));

function setSegment(key: SegmentKey) {
    activeSegment.value = key;
    if (listFilter.value === 'attention') {
        router.get(chatsListRoute(), chatsListQuery({ filter: undefined }), {
            preserveState: true,
            preserveScroll: true,
            only: ['chats', 'unreadChatsCount', 'unreadChatsCountMine', 'listOwnership', 'listFilter', 'attentionChatsTotal', 'mineChatsTotal'],
        });
    }
}

function setAttentionFilter(): void {
    router.get(chatsListRoute(), chatsListQuery({ filter: 'attention' }), {
        preserveState: true,
        preserveScroll: true,
        only: ['chats', 'unreadChatsCount', 'unreadChatsCountMine', 'listOwnership', 'listFilter', 'attentionChatsTotal', 'mineChatsTotal'],
    });
}

function setOwnership(key: OwnershipKey) {
    const q: Record<string, string | undefined> = {};
    if (props.search) {
        q.search = props.search;
    }
    if (key === 'mine') {
        q.ownership = 'mine';
    }
    router.get(chatsListRoute(), q, {
        preserveState: true,
        preserveScroll: true,
        only: ['chats', 'unreadChatsCount', 'unreadChatsCountMine', 'listOwnership', 'listFilter', 'attentionChatsTotal', 'mineChatsTotal'],
    });
}

function clearSearch() {
    searchQuery.value = '';
}

function onEscape(e: KeyboardEvent) {
    if (e.key === 'Escape') {
        headerMenuOpen.value = false;
        if (searchFocused.value) clearSearch();
    }
}
window.addEventListener('keydown', onEscape);
onBeforeUnmount(() => window.removeEventListener('keydown', onEscape));

function navigateChat(direction: 1 | -1) {
    const list = filteredChats.value;
    if (list.length === 0) return;
    const currentIdx = props.selectedChatId
        ? list.findIndex((c) => c.id === props.selectedChatId)
        : -1;
    let nextIdx: number;
    if (currentIdx === -1) {
        nextIdx = direction === 1 ? 0 : list.length - 1;
    } else {
        nextIdx = (currentIdx + direction + list.length) % list.length;
    }
    router.visit(appendChatListOwnership(route('chats.show', list[nextIdx].id), listOwnership.value));
}

const offNextChat = onShortcut('next-chat', () => navigateChat(1));
const offPrevChat = onShortcut('prev-chat', () => navigateChat(-1));
const offNewChat = onShortcut('new-chat', () => {
    showNewChat.value = true;
});
const offNewGroup = onShortcut('new-group', () => {
    showNewChat.value = true;
    window.dispatchEvent(new CustomEvent('accel:new-chat-mode', { detail: 'group' }));
});
let removeListStart: (() => void) | undefined;
let removeListFinish: (() => void) | undefined;
onBeforeUnmount(() => {
    if (chatListReloadTimer !== null) {
        clearTimeout(chatListReloadTimer);
        chatListReloadTimer = null;
    }
    teardownListEcho();
    offNextChat();
    offPrevChat();
    offNewChat();
    offNewGroup();
    removeListStart?.();
    removeListFinish?.();
});
</script>

<template>
    <div
        class="h-full w-full relative overflow-hidden border-r"
        :style="{ borderColor: 'var(--wa-sidebar-divider)' }"
    >
        <!-- New-chat panel slides in from the left -->
        <Transition name="slide-left">
            <NewChatPanel
                v-if="showNewChat"
                class="absolute inset-0 z-20"
                @close="showNewChat = false"
            />
        </Transition>

    <div class="w-full h-full flex flex-col bg-[var(--wa-panel)]">
        <!-- Panel header -->
        <div class="h-[60px] px-4 flex items-center justify-between shrink-0">
            <h1 class="min-w-0 text-[var(--wa-text)] text-xl font-normal m-0 truncate">
                Accel
            </h1>
            <div class="flex items-center gap-1">
                <button
                    @click="showNewChat = true"
                    class="wa-icon-btn"
                    title="Новый чат"
                    type="button"
                >
                    <svg
                        class="w-[22px] h-[22px]"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path d="M7.5 19.25 4.25 21.5v-3.25a2.75 2.75 0 0 1-1.5-2.45V6.25A2.75 2.75 0 0 1 5.5 3.5h13A2.75 2.75 0 0 1 21.25 6.25v9.55A2.75 2.75 0 0 1 18.5 18.55H9.1z" />
                        <path d="M12 8v6" />
                        <path d="M9 11h6" />
                    </svg>
                </button>
                <div class="relative">
                    <button
                        @click="headerMenuOpen = !headerMenuOpen"
                        class="wa-icon-btn"
                        title="Меню"
                        type="button"
                    >
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                            <circle cx="12" cy="5" r="2"/>
                            <circle cx="12" cy="12" r="2"/>
                            <circle cx="12" cy="19" r="2"/>
                        </svg>
                    </button>

                    <div
                        v-if="headerMenuOpen"
                        @click="headerMenuOpen = false"
                        class="fixed inset-0 z-40"
                    ></div>

                    <div
                        v-if="headerMenuOpen"
                        class="absolute right-0 top-full mt-2 min-w-[240px] rounded-lg shadow-xl py-2 z-50 border header-menu"
                        :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border-strong)' }"
                    >
                        <template v-if="isAdmin">
                            <Link
                                :href="route('settings.connections')"
                                @click="headerMenuOpen = false"
                                class="menu-item"
                            >
                                <svg class="menu-item-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 16l-4-4m0 0l4-4m-4 4h16m-4 4l4-4m0 0l-4-4" />
                                </svg>
                                <span>Подключения WhatsApp</span>
                            </Link>
                            <Link
                                :href="route('settings.departments')"
                                @click="headerMenuOpen = false"
                                class="menu-item"
                            >
                                <svg class="menu-item-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                <span>Отделы</span>
                            </Link>
                            <Link
                                :href="route('settings.users')"
                                @click="headerMenuOpen = false"
                                class="menu-item"
                            >
                                <svg class="menu-item-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                                <span>Пользователи</span>
                            </Link>
                            <Link
                                :href="route('settings.system')"
                                @click="headerMenuOpen = false"
                                class="menu-item"
                            >
                                <svg class="menu-item-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                <span>Настройки системы</span>
                            </Link>
                            <div class="my-1 h-px" :style="{ background: 'var(--wa-border)' }"></div>
                        </template>
                        <Link
                            :href="route('profile.edit')"
                            @click="headerMenuOpen = false"
                            class="menu-item"
                        >
                            <svg class="menu-item-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                            <span>Профиль</span>
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search bar -->
        <div class="px-3 py-2 shrink-0">
            <div
                class="relative rounded-full"
                :style="{ background: 'var(--wa-panel-header)' }"
            >
                <div class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 flex items-center justify-center">
                    <svg
                        v-if="!searchFocused && !searchQuery"
                        class="w-4 h-4 text-[var(--wa-icon)]"
                        fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <button
                        v-else
                        @click="clearSearch"
                        class="w-5 h-5 flex items-center justify-center rounded-full"
                        :style="{ color: 'var(--wa-accent)' }"
                        type="button"
                        title="Очистить"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                        </svg>
                    </button>
                </div>
                <input
                    v-model="searchQuery"
                    @focus="searchFocused = true"
                    @blur="searchFocused = false"
                    type="text"
                    placeholder="Поиск или новый чат"
                    aria-label="Поиск или новый чат"
                    data-shortcut-target="chat-search"
                    class="w-full pl-12 pr-10 py-[9px] bg-transparent rounded-full text-sm text-[var(--wa-text)] border-0 focus:ring-0 focus:outline-none"
                />
                <button
                    v-if="searchQuery"
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
        </div>

        <div class="ui-sidebar-filters">
            <SidebarSectionTabs active="clients" />

            <div class="ui-sidebar-filters__group">
                <UiPillNav>
                    <Link
                        :href="activeListHref"
                        class="ui-pill-nav__item"
                        :class="{ 'is-active': scope === 'active' }"
                    >
                        Активные
                    </Link>
                    <Link
                        :href="archivedListHref"
                        class="ui-pill-nav__item"
                        :class="{ 'is-active': scope === 'archived' }"
                    >
                        <span class="truncate">Архив</span>
                        <span
                            v-if="archivedCount > 0"
                            class="ui-tab-badge"
                            :title="`Чатов в архиве: ${archivedCount}`"
                        >{{ archivedCount > 99 ? '99+' : archivedCount }}</span>
                    </Link>
                </UiPillNav>

                <div class="ui-sidebar-filters__chips-slot">
                    <div
                        v-if="scope === 'active'"
                        class="ui-chip-row ui-chip-row--scroll wa-scrollbar"
                        role="toolbar"
                        aria-label="Фильтры списка чатов"
                    >
                    <template v-if="canFilterByOwnership">
                        <button
                            type="button"
                            class="ui-chip shrink-0"
                            :class="{ 'is-active': listOwnership === 'all' }"
                            @click="setOwnership('all')"
                        >
                            Все
                        </button>
                        <button
                            type="button"
                            class="ui-chip shrink-0"
                            :class="{ 'is-active': listOwnership === 'mine' }"
                            @click="setOwnership('mine')"
                        >
                            Мои
                            <span v-if="mineChatsTotal" class="ui-chip__meta">{{ mineChatsTotal }}</span>
                        </button>
                        <span class="ui-sidebar-filters__sep" aria-hidden="true" />
                    </template>
                    <button
                        type="button"
                        class="ui-chip shrink-0"
                        :class="{ 'is-active': activeSegment === 'favorites' && listFilter !== 'attention' }"
                        @click="setSegment('favorites')"
                    >
                        Избранные
                        <span v-if="favoritesTotal" class="ui-chip__meta">{{ favoritesTotal }}</span>
                    </button>
                    <button
                        type="button"
                        class="ui-chip shrink-0"
                        :class="{ 'is-active': activeSegment === 'clients' && listFilter !== 'attention' }"
                        @click="setSegment('clients')"
                    >
                        Клиенты
                        <span v-if="clientsTotal" class="ui-chip__meta">{{ clientsTotal }}</span>
                    </button>
                    <button
                        type="button"
                        class="ui-chip shrink-0"
                        :class="{ 'is-active': activeSegment === 'staff' && listFilter !== 'attention' }"
                        @click="setSegment('staff')"
                    >
                        Сотрудники
                        <span v-if="staffTotal" class="ui-chip__meta">{{ staffTotal }}</span>
                    </button>
                    <button
                        type="button"
                        class="ui-chip ui-chip--danger shrink-0"
                        :class="{ 'is-active': listFilter === 'attention' }"
                        title="AI: нужен менеджер, ошибка, низкая уверенность; непрочитанные"
                        @click="setAttentionFilter"
                    >
                        Внимание
                        <span v-if="attentionChatsTotal" class="ui-chip__meta">{{ attentionChatsTotal > 99 ? '99+' : attentionChatsTotal }}</span>
                    </button>
                    </div>
                </div>
            </div>
        </div>

        <div
            v-if="scope === 'active' && listFilter === 'attention' && filteredChats.length === 0"
            class="mx-3 mb-2 px-3 py-2 rounded-lg text-xs shrink-0 leading-snug"
            :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-text-secondary)' }"
        >
            Сейчас нет чатов, требующих внимания.
        </div>

        <div
            v-if="scope === 'active' && activeSegment === 'staff' && listFilter !== 'attention' && filteredChats.length === 0 && ownershipFilteredChats.length > 0"
            class="mx-3 mb-2 px-3 py-2 rounded-lg text-xs shrink-0 leading-snug"
            :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-text-secondary)' }"
        >
            В разделе «Сотрудники» только чаты, где последнее сообщение от оператора.
            <button
                type="button"
                class="font-semibold mt-1 block"
                :style="{ color: 'var(--wa-accent)' }"
                @click="setSegment('clients')"
            >
                Показать всех клиентов
            </button>
        </div>

        <!-- Notifications muted banner -->
        <div
            v-if="SHOW_NOTIFICATIONS_MUTED_BANNER && notifBannerOpen"
            class="mx-3 mb-2 flex items-center gap-3 px-3 py-2.5 rounded-lg shrink-0 banner"
        >
            <div class="w-10 h-10 rounded-full flex items-center justify-center shrink-0" :style="{ background: 'var(--wa-selected)' }">
                <svg class="w-5 h-5 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.586 15L4 17h5a3 3 0 006 0h5l-1.405-1.405M3 3l18 18M9 5.341V5a2 2 0 114 0v.341" />
                </svg>
            </div>
                <div class="flex-1 min-w-0">
                    <div class="text-[13px] text-[var(--wa-text)] truncate font-medium">Включите уведомления о новых сообщениях</div>
                    <button
                        type="button"
                        class="text-[13px] font-semibold mt-0.5"
                        :style="{ color: 'var(--wa-accent)' }"
                        @click="enableNotifications"
                    >
                        Разрешить
                    </button>
                </div>
            <button
                @click="dismissNotifBanner"
                class="w-6 h-6 rounded-full flex items-center justify-center shrink-0 hover:bg-[var(--wa-panel-hover)] text-[var(--wa-icon)]"
                title="Закрыть"
                type="button"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <!-- Promo banner -->
        <div
            v-if="SHOW_PROMO_BANNER && promoBannerOpen"
            class="mx-3 mb-2 flex items-start gap-3 px-3 py-2.5 rounded-lg shrink-0 banner"
        >
            <div class="w-10 h-10 rounded-full flex items-center justify-center shrink-0" :style="{ background: 'var(--wa-accent-soft)' }">
                <svg class="w-5 h-5" :style="{ color: 'var(--wa-accent)' }" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <rect x="3" y="3" width="18" height="18" rx="5" ry="5" />
                    <circle cx="12" cy="12" r="4" />
                    <circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none" />
                </svg>
            </div>
            <div class="flex-1 min-w-0">
                <div class="text-[13px] text-[var(--wa-text)]">Обращайтесь к новым клиентам</div>
                <div class="text-[12px] text-[var(--wa-text-secondary)] mt-0.5 leading-snug">
                    Рекламируйте свою компанию на Facebook и в Instagram.
                    <button
                        type="button"
                        class="font-medium"
                        :style="{ color: 'var(--wa-accent)' }"
                    >
                        Начать
                    </button>
                </div>
            </div>
            <button
                @click="dismissPromoBanner"
                class="w-6 h-6 rounded-full flex items-center justify-center shrink-0 hover:bg-[var(--wa-panel-hover)] text-[var(--wa-icon)]"
                title="Закрыть"
                type="button"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <!-- Chat list -->
        <div class="flex-1 overflow-y-auto wa-scrollbar" @scroll.passive="onChatListScroll">
            <SkeletonBlock
                v-if="(listNavigating || initialSidebarLoading) && filteredChats.length === 0"
                :lines="8"
                wrapper-class="px-4 py-4"
            />
            <div
                v-else-if="filteredChats.length === 0 && !(scope === 'active' && activeSegment === 'staff' && ownershipFilteredChats.length > 0)"
                class="flex items-center justify-center h-full text-sm text-[var(--wa-text-secondary)] px-6 text-center"
            >
                <template v-if="scope === 'archived'">В архиве пока нет чатов</template>
                <template v-else>Нет чатов в этом разделе</template>
            </div>
            <ChatListItem
                v-for="chat in filteredChats"
                :key="chat.id"
                :chat="chat"
                :is-selected="chat.id === selectedChatId"
            />
            <SkeletonBlock v-if="loadingMore || initialSidebarLoading" :lines="4" class="px-3 py-3" />
        </div>

    </div>
    </div>
</template>

<style scoped>
.wa-icon-btn {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 9999px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--wa-icon);
    transition: background-color 0.15s ease;
}
.wa-icon-btn:hover {
    background-color: var(--wa-panel-hover);
}
@keyframes filter-menu-pop {
    from { opacity: 0; transform: translateY(-4px) scale(0.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
.archived-link {
    transition: background-color 0.15s ease;
}
.archived-link:hover {
    background-color: var(--wa-panel-hover);
}
.header-menu {
    animation: filter-menu-pop 0.12s ease-out;
}
.banner {
    background-color: color-mix(in srgb, var(--wa-accent) 18%, var(--wa-panel-header));
}
.menu-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.5rem 1rem;
    width: 100%;
    font-size: 0.875rem;
    color: var(--wa-text);
    transition: background-color 0.15s ease;
    white-space: nowrap;
}
.menu-item:hover {
    background-color: var(--wa-panel-hover);
}
.menu-item-icon {
    width: 1.125rem;
    height: 1.125rem;
    color: var(--wa-icon);
    flex-shrink: 0;
}
.slide-left-enter-active,
.slide-left-leave-active {
    transition: transform 0.25s ease, opacity 0.25s ease;
}
.slide-left-enter-from {
    transform: translateX(-100%);
    opacity: 0;
}
.slide-left-leave-to {
    transform: translateX(-100%);
    opacity: 0;
}
</style>
