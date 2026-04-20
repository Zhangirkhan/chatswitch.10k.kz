<script setup lang="ts">
import { Link, usePage, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useTheme } from '@/composables/useTheme';

const page = usePage<any>();
const user = computed(() => page.props.auth.user);
const roles = computed<string[]>(() => user.value?.roles || []);
const isAdmin = computed(() => roles.value.includes('administrator'));
const unreadChatsCount = computed<number>(() => Number(page.props.unreadChatsCount || 0));

const showUserMenu = ref(false);
const { theme, toggle: toggleTheme } = useTheme();

const logout = () => {
    router.post(route('logout'));
};

function initial(name?: string): string {
    return (name || '?').charAt(0).toUpperCase();
}

function notifySoon() {
    // Status / Channels / Communities are not implemented yet.
    // Keep them as visual stubs to match WhatsApp Web; do nothing on click.
}
</script>

<template>
    <div class="h-screen w-screen flex bg-[var(--wa-bg)] text-[var(--wa-text)] overflow-hidden">
        <!-- LEFT ICON RAIL (matches WhatsApp Web 1:1) -->
        <aside
            class="w-[60px] shrink-0 flex flex-col items-center py-3"
            :style="{ background: 'var(--wa-rail-bg)' }"
        >
            <nav class="flex flex-col items-center gap-1 flex-1">
                <!-- Chats -->
                <Link
                    :href="route('chats.index')"
                    class="wa-rail-btn relative"
                    :class="{ active: route().current('chats.index') || route().current('chats.show') || route().current('chats.archived') }"
                    title="Чаты"
                >
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M19.005 3.175H4.674C3.642 3.175 3 3.789 3 4.821V21.02l3.544-3.514h12.461c1.033 0 2.064-1.06 2.064-2.093V4.821c-.001-1.032-1.032-1.646-2.064-1.646zm-4.989 9.869H7.041V11.1h6.975v1.944zm3-4H7.041V7.1h9.975v1.944z"/>
                    </svg>
                    <span
                        v-if="unreadChatsCount > 0"
                        class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] rounded-full text-[10px] font-semibold flex items-center justify-center px-1 leading-none"
                        :style="{ background: 'var(--wa-unread)', color: 'var(--wa-unread-text)' }"
                    >
                        {{ unreadChatsCount > 99 ? '99+' : unreadChatsCount }}
                    </span>
                </Link>

                <!-- Status -->
                <button
                    type="button"
                    class="wa-rail-btn"
                    title="Статус"
                    @click="notifySoon"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="9" stroke-dasharray="3 2" />
                        <circle cx="12" cy="12" r="4" fill="currentColor" stroke="none" />
                    </svg>
                </button>

                <!-- Channels -->
                <button
                    type="button"
                    class="wa-rail-btn"
                    title="Каналы"
                    @click="notifySoon"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 12a8 8 0 10-14.93 4L4 20l4.07-1.07A8 8 0 0020 12z" />
                        <circle cx="8.5" cy="12" r="1" fill="currentColor" stroke="none" />
                        <circle cx="12" cy="12" r="1" fill="currentColor" stroke="none" />
                        <circle cx="15.5" cy="12" r="1" fill="currentColor" stroke="none" />
                    </svg>
                </button>

                <!-- Communities -->
                <button
                    type="button"
                    class="wa-rail-btn"
                    title="Сообщества"
                    @click="notifySoon"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                </button>
            </nav>

            <div class="flex flex-col items-center gap-1 pb-1">
                <!-- Settings (moved all admin sections in here) -->
                <Link
                    v-if="isAdmin"
                    :href="route('settings.connections')"
                    class="wa-rail-btn"
                    :class="{ active: route().current('settings.*') }"
                    title="Настройки"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </Link>

                <!-- Profile menu -->
                <div class="relative">
                    <button
                        @click="showUserMenu = !showUserMenu"
                        class="w-10 h-10 rounded-full bg-[#6b7c85] flex items-center justify-center text-white text-sm font-medium hover:ring-2 hover:ring-[var(--wa-accent)] transition"
                        :title="user?.name"
                    >
                        {{ initial(user?.name) }}
                    </button>
                    <div
                        v-if="showUserMenu"
                        @click="showUserMenu = false"
                        class="fixed inset-0 z-40"
                    ></div>
                    <div
                        v-if="showUserMenu"
                        class="absolute bottom-0 left-full ml-2 w-60 rounded-lg shadow-xl py-2 z-50 border"
                        :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border-strong)' }"
                    >
                        <div class="px-4 py-2 border-b" :style="{ borderColor: 'var(--wa-border)' }">
                            <div class="text-sm text-[var(--wa-text)] truncate">{{ user?.name }}</div>
                            <div class="text-xs text-[var(--wa-text-secondary)] truncate">{{ user?.email }}</div>
                        </div>
                        <Link
                            :href="route('profile.edit')"
                            @click="showUserMenu = false"
                            class="user-menu-item"
                        >
                            <svg class="user-menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                            <span>Профиль</span>
                        </Link>
                        <button
                            type="button"
                            @click="toggleTheme"
                            class="user-menu-item w-full"
                        >
                            <svg v-if="theme === 'dark'" class="user-menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m12.728 0l-.707-.707M6.343 6.343l-.707-.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                            </svg>
                            <svg v-else class="user-menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                            </svg>
                            <span>{{ theme === 'dark' ? 'Светлая тема' : 'Тёмная тема' }}</span>
                        </button>
                        <div class="my-1 h-px" :style="{ background: 'var(--wa-border)' }"></div>
                        <button
                            type="button"
                            @click="logout"
                            class="user-menu-item w-full"
                        >
                            <svg class="user-menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                            </svg>
                            <span>Выйти</span>
                        </button>
                    </div>
                </div>
            </div>
        </aside>

        <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
            <slot />
        </div>
    </div>
</template>

<style scoped>
.wa-rail-btn {
    position: relative;
    width: 2.75rem;
    height: 2.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 0.5rem;
    color: var(--wa-icon);
    transition: background-color 0.15s ease, color 0.15s ease;
}
.wa-rail-btn:hover {
    background-color: var(--wa-rail-btn-hover);
    color: var(--wa-text);
}
.wa-rail-btn.active {
    background-color: var(--wa-selected);
    color: var(--wa-text);
}
.user-menu-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
    color: var(--wa-text);
    text-align: left;
    transition: background-color 0.15s ease;
}
.user-menu-item:hover {
    background-color: var(--wa-panel-hover);
}
.user-menu-icon {
    width: 1.125rem;
    height: 1.125rem;
    color: var(--wa-icon);
    flex-shrink: 0;
}
</style>
