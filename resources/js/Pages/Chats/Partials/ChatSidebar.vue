<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ref, watch, computed, onBeforeUnmount } from 'vue';
import ChatListItem from './ChatListItem.vue';
import NewChatPanel from './NewChatPanel.vue';
import type { Chat, Paginated } from '@/types';

type FilterKey = 'all' | 'unread' | 'favorites' | 'groups';

const props = defineProps<{
    chats: Paginated<Chat>;
    selectedChatId?: number;
    search?: string;
}>();

const page = usePage<any>();
const archivedCount = computed<number>(() => Number(page.props.archivedCount || 0));
const user = computed(() => page.props.auth?.user);
const roles = computed<string[]>(() => user.value?.roles || []);
const isAdmin = computed(() => roles.value.includes('administrator'));

const searchQuery = ref(props.search || '');
const searchFocused = ref(false);
const activeFilter = ref<FilterKey>('all');
const filterMenuOpen = ref(false);
const headerMenuOpen = ref(false);
const showNewChat = ref(false);
let searchTimeout: ReturnType<typeof setTimeout>;

watch(searchQuery, (val) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(route('chats.index'), { search: val || undefined }, {
            preserveState: true,
            preserveScroll: true,
            only: ['chats'],
        });
    }, 300);
});

const filteredChats = computed(() => {
    let list = props.chats.data;
    if (activeFilter.value === 'unread') {
        list = list.filter((c) => c.unread_count > 0);
    } else if (activeFilter.value === 'groups') {
        list = list.filter((c) => c.is_group);
    } else if (activeFilter.value === 'favorites') {
        list = list.filter((c) => c.is_pinned);
    }
    return list;
});

const unreadTotal = computed(() =>
    props.chats.data.reduce((sum, c) => sum + (c.unread_count > 0 ? 1 : 0), 0)
);
const groupsTotal = computed(() =>
    props.chats.data.filter((c) => c.is_group).length
);
const favoritesTotal = computed(() =>
    props.chats.data.filter((c) => c.is_pinned).length
);

function setFilter(key: FilterKey) {
    activeFilter.value = key;
    filterMenuOpen.value = false;
}

function clearSearch() {
    searchQuery.value = '';
}

function onEscape(e: KeyboardEvent) {
    if (e.key === 'Escape') {
        filterMenuOpen.value = false;
        headerMenuOpen.value = false;
        if (searchFocused.value) clearSearch();
    }
}
window.addEventListener('keydown', onEscape);
onBeforeUnmount(() => window.removeEventListener('keydown', onEscape));
</script>

<template>
    <!-- New-chat panel replaces the sidebar when active -->
    <NewChatPanel v-if="showNewChat" @close="showNewChat = false" />

    <div v-else class="w-[400px] h-full flex flex-col bg-[var(--wa-panel)]">
        <!-- Panel header -->
        <div class="h-[60px] px-4 flex items-center justify-between shrink-0">
            <h1 class="text-[var(--wa-text)] text-xl font-normal">Чаты</h1>
            <div class="flex items-center gap-1">
                <button
                    @click="showNewChat = true"
                    class="wa-icon-btn"
                    title="Новый чат"
                    type="button"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
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
                            :href="route('chats.archived')"
                            @click="headerMenuOpen = false"
                            class="menu-item"
                        >
                            <svg class="menu-item-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v1a2 2 0 01-2 2M5 8v11a2 2 0 002 2h10a2 2 0 002-2V8M10 12h4" />
                            </svg>
                            <span>Архив</span>
                        </Link>
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

        <!-- Filter chips -->
        <div class="px-3 pb-2 flex items-center gap-2 shrink-0">
            <button
                @click="setFilter('all')"
                class="chip"
                :class="{ 'chip-active': activeFilter === 'all' }"
            >
                Все
            </button>
            <button
                @click="setFilter('unread')"
                class="chip"
                :class="{ 'chip-active': activeFilter === 'unread' }"
            >
                Непрочитанные<span v-if="unreadTotal" class="ml-1.5">{{ unreadTotal }}</span>
            </button>
            <button
                v-if="activeFilter === 'favorites'"
                @click="setFilter('favorites')"
                class="chip chip-active"
            >
                Избранные<span v-if="favoritesTotal" class="ml-1.5">{{ favoritesTotal }}</span>
            </button>
            <button
                v-if="activeFilter === 'groups'"
                @click="setFilter('groups')"
                class="chip chip-active"
            >
                Группы<span v-if="groupsTotal" class="ml-1.5">{{ groupsTotal }}</span>
            </button>

            <!-- Dropdown trigger -->
            <div class="relative">
                <button
                    @click="filterMenuOpen = !filterMenuOpen"
                    class="chip chip-chevron"
                    :class="{ 'chip-chevron-open': filterMenuOpen }"
                    title="Ещё фильтры"
                    type="button"
                >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                <div
                    v-if="filterMenuOpen"
                    @click="filterMenuOpen = false"
                    class="fixed inset-0 z-40"
                ></div>

                <div
                    v-if="filterMenuOpen"
                    class="absolute left-0 top-full mt-2 min-w-[220px] rounded-lg shadow-xl py-2 z-50 border filter-menu"
                    :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border-strong)' }"
                >
                    <button
                        @click="setFilter('favorites')"
                        class="flex items-center justify-between w-full px-4 py-2 text-sm hover:bg-[var(--wa-panel-hover)]"
                        :style="{ color: 'var(--wa-text)' }"
                    >
                        <span>Избранные</span>
                        <span v-if="favoritesTotal" class="text-[var(--wa-text-secondary)] text-xs">{{ favoritesTotal }}</span>
                    </button>
                    <button
                        @click="setFilter('groups')"
                        class="flex items-center justify-between w-full px-4 py-2 text-sm hover:bg-[var(--wa-panel-hover)]"
                        :style="{ color: 'var(--wa-text)' }"
                    >
                        <span>Группы</span>
                        <span v-if="groupsTotal" class="text-[var(--wa-text-secondary)] text-xs">{{ groupsTotal }}</span>
                    </button>
                    <div class="my-1 h-px" :style="{ background: 'var(--wa-border)' }"></div>
                    <button
                        class="flex items-center gap-2 w-full px-4 py-2 text-sm hover:bg-[var(--wa-panel-hover)] opacity-60 cursor-not-allowed"
                        :style="{ color: 'var(--wa-text)' }"
                        disabled
                        title="Скоро"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                        </svg>
                        Новый список
                    </button>
                </div>
            </div>
        </div>

        <!-- Chat list -->
        <div class="flex-1 overflow-y-auto wa-scrollbar">
            <!-- Archived entry (like WhatsApp Web) -->
            <Link
                v-if="archivedCount > 0 && activeFilter === 'all'"
                :href="route('chats.archived')"
                class="flex items-center px-4 py-3 gap-5 cursor-pointer transition archived-link"
            >
                <div class="w-5 flex justify-center">
                    <svg class="w-5 h-5 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v1a2 2 0 01-2 2M5 8v11a2 2 0 002 2h10a2 2 0 002-2V8M10 12h4" />
                    </svg>
                </div>
                <span class="text-[var(--wa-text)] text-[15px] flex-1">В архиве</span>
                <span class="text-xs font-medium" :style="{ color: 'var(--wa-accent)' }">
                    {{ archivedCount }}
                </span>
            </Link>

            <div
                v-if="filteredChats.length === 0"
                class="flex items-center justify-center h-full text-sm text-[var(--wa-text-secondary)] px-6 text-center"
            >
                Нет чатов в этом разделе
            </div>
            <ChatListItem
                v-for="chat in filteredChats"
                :key="chat.id"
                :chat="chat"
                :is-selected="chat.id === selectedChatId"
            />
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
.chip {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    padding: 0.3125rem 0.875rem;
    border-radius: 9999px;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--wa-text);
    background-color: transparent;
    border: 1px solid var(--wa-border-strong);
    transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
    line-height: 1.25rem;
}
.chip:hover {
    background-color: var(--wa-panel-hover);
}
.chip-active {
    background-color: var(--wa-accent-soft);
    color: var(--wa-accent);
    border-color: var(--wa-accent-soft);
    font-weight: 600;
}
.chip-active:hover {
    background-color: var(--wa-accent-soft);
}
.chip-chevron {
    width: 2rem;
    height: 2rem;
    padding: 0;
    justify-content: center;
    color: var(--wa-text-secondary);
}
.chip-chevron-open {
    background-color: var(--wa-panel-hover);
}
.filter-menu {
    animation: filter-menu-pop 0.12s ease-out;
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
</style>
