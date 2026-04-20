<script setup lang="ts">
import { Link, usePage, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useTheme } from '@/composables/useTheme';

const page = usePage<any>();
const user = computed(() => page.props.auth.user);
const roles = computed(() => user.value?.roles || []);
const isAdmin = computed(() => roles.value.includes('administrator'));
const archivedCount = computed<number>(() => Number(page.props.archivedCount || 0));

const showUserMenu = ref(false);
const { theme, toggle: toggleTheme } = useTheme();

const logout = () => {
    router.post(route('logout'));
};

function initial(name?: string): string {
    return (name || '?').charAt(0).toUpperCase();
}
</script>

<template>
    <div class="h-screen w-screen flex bg-[var(--wa-bg)] text-[var(--wa-text)] overflow-hidden">
        <!-- LEFT ICON RAIL -->
        <aside
            class="w-[60px] shrink-0 flex flex-col items-center py-3"
            :style="{ background: 'var(--wa-rail-bg)' }"
        >
            <nav class="flex flex-col items-center gap-1 flex-1">
                <Link
                    :href="route('chats.index')"
                    class="wa-rail-btn"
                    :class="{ active: route().current('chats.index') || route().current('chats.show') }"
                    title="Чаты"
                >
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M19.005 3.175H4.674C3.642 3.175 3 3.789 3 4.821V21.02l3.544-3.514h12.461c1.033 0 2.064-1.06 2.064-2.093V4.821c-.001-1.032-1.032-1.646-2.064-1.646zm-4.989 9.869H7.041V11.1h6.975v1.944zm3-4H7.041V7.1h9.975v1.944z"/>
                    </svg>
                </Link>

                <Link
                    :href="route('chats.archived')"
                    class="wa-rail-btn relative"
                    :class="{ active: route().current('chats.archived') }"
                    title="Архив"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v1a2 2 0 01-2 2M5 8v11a2 2 0 002 2h10a2 2 0 002-2V8M10 12h4" />
                    </svg>
                    <span
                        v-if="archivedCount > 0"
                        class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] rounded-full text-[10px] font-semibold flex items-center justify-center px-1 leading-none"
                        :style="{ background: 'var(--wa-unread)', color: 'var(--wa-unread-text)' }"
                    >
                        {{ archivedCount > 99 ? '99+' : archivedCount }}
                    </span>
                </Link>

                <template v-if="isAdmin">
                    <Link
                        :href="route('settings.connections')"
                        class="wa-rail-btn"
                        :class="{ active: route().current('settings.connections*') }"
                        title="Подключения"
                    >
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 16l-4-4m0 0l4-4m-4 4h16m-4 4l4-4m0 0l-4-4" />
                        </svg>
                    </Link>
                    <Link
                        :href="route('settings.departments')"
                        class="wa-rail-btn"
                        :class="{ active: route().current('settings.departments*') }"
                        title="Отделы"
                    >
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                    </Link>
                    <Link
                        :href="route('settings.users')"
                        class="wa-rail-btn"
                        :class="{ active: route().current('settings.users*') }"
                        title="Пользователи"
                    >
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                    </Link>
                </template>
            </nav>

            <div class="flex flex-col items-center gap-1 pb-1">
                <!-- Theme toggle -->
                <button
                    @click="toggleTheme"
                    class="wa-rail-btn"
                    :title="theme === 'dark' ? 'Светлая тема' : 'Тёмная тема'"
                >
                    <svg v-if="theme === 'dark'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m12.728 0l-.707-.707M6.343 6.343l-.707-.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                    <svg v-else class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                    </svg>
                </button>

                <Link
                    v-if="isAdmin"
                    :href="route('settings.system')"
                    class="wa-rail-btn"
                    :class="{ active: route().current('settings.system*') }"
                    title="Настройки"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </Link>

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
                        class="absolute bottom-0 left-full ml-2 w-56 rounded-lg shadow-xl py-2 z-50 border"
                        :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border-strong)' }"
                    >
                        <div class="px-4 py-2 border-b" :style="{ borderColor: 'var(--wa-border)' }">
                            <div class="text-sm text-[var(--wa-text)] truncate">{{ user?.name }}</div>
                            <div class="text-xs text-[var(--wa-text-secondary)] truncate">{{ user?.email }}</div>
                        </div>
                        <Link
                            :href="route('profile.edit')"
                            class="block px-4 py-2 text-sm text-[var(--wa-text)] hover:bg-[var(--wa-panel-hover)]"
                        >
                            Профиль
                        </Link>
                        <button
                            @click="logout"
                            class="block w-full text-left px-4 py-2 text-sm text-[var(--wa-text)] hover:bg-[var(--wa-panel-hover)]"
                        >
                            Выйти
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
</style>
