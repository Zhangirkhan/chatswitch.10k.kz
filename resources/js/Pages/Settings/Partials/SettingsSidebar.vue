<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type AdminItem = {
    kind: 'admin';
    label: string;
    description: string;
    icon: string;
    routeName: string;
};

type ProfileItem = {
    kind: 'profile';
    id: string;
    label: string;
    description: string;
    icon: string;
};

type Item = AdminItem | ProfileItem;

const props = defineProps<{
    /** Currently active WhatsApp-style section (for Profile/Edit sub-navigation). */
    activeSection?: string;
}>();

const page = usePage<any>();
const user = computed(() => page.props.auth?.user);
const roles = computed<string[]>(() => user.value?.roles || []);
const isAdmin = computed(() => roles.value.includes('administrator'));

const searchQuery = ref('');

const adminItems: AdminItem[] = [
    {
        kind: 'admin',
        label: 'Подключения WhatsApp',
        description: 'Номера и QR-коды',
        icon: 'connection',
        routeName: 'settings.connections',
    },
    {
        kind: 'admin',
        label: 'Отделы',
        description: 'Структура компании',
        icon: 'departments',
        routeName: 'settings.departments',
    },
    {
        kind: 'admin',
        label: 'Пользователи',
        description: 'Операторы и права',
        icon: 'users',
        routeName: 'settings.users',
    },
    {
        kind: 'admin',
        label: 'Клиенты',
        description: 'Контакты и сведения',
        icon: 'clients',
        routeName: 'settings.clients',
    },
    {
        kind: 'admin',
        label: 'Система',
        description: 'Общие параметры',
        icon: 'system',
        routeName: 'settings.system',
    },
];

const profileItems: ProfileItem[] = [
    { kind: 'profile', id: 'profile', label: 'Профиль', description: 'Имя, фото профиля, имя пользователя', icon: 'user' },
    { kind: 'profile', id: 'chats', label: 'Чаты', description: 'Тема, обои, настройки чата', icon: 'chat' },
    { kind: 'profile', id: 'notifications', label: 'Уведомления', description: 'Сообщения, группы, звуки', icon: 'bell' },
    { kind: 'profile', id: 'shortcuts', label: 'Сочетания клавиш', description: 'Быстрые действия', icon: 'keyboard' },
];

function matchesQuery(item: Item): boolean {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return true;
    return item.label.toLowerCase().includes(q) || item.description.toLowerCase().includes(q);
}

const filteredAdminItems = computed(() => (isAdmin.value ? adminItems.filter(matchesQuery) : []));
const filteredProfileItems = computed(() => profileItems.filter(matchesQuery));

function isAdminActive(item: AdminItem): boolean {
    return route().current(item.routeName + '*');
}

function isProfileActive(item: ProfileItem): boolean {
    if (!route().current('profile.edit')) return false;
    // Only highlight when the user has explicitly opened a section; on the
    // bare settings list (no ?section=) nothing should be selected, matching
    // WhatsApp Web.
    return props.activeSection === item.id;
}

function initial(name?: string): string {
    return (name || '?').charAt(0).toUpperCase();
}

function logout() {
    router.post(route('logout'));
}
</script>

<template>
    <aside class="w-[400px] h-full flex flex-col bg-[var(--wa-panel)] shrink-0">
        <!-- Header with user name -->
        <div class="h-[60px] px-6 flex items-center shrink-0">
            <h1 class="text-[var(--wa-text)] text-xl font-normal truncate">
                {{ user?.name }}
            </h1>
        </div>

        <!-- Search -->
        <div class="px-3 pb-2 shrink-0">
            <div class="settings-search relative rounded-full">
                <svg
                    class="settings-search-icon absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 transition-colors"
                    fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Поиск"
                    class="w-full pl-12 pr-4 py-[9px] bg-transparent rounded-full text-sm text-[var(--wa-text)] border-0 focus:ring-0 focus:outline-none relative z-[1]"
                />
            </div>
        </div>

        <!-- Items list -->
        <div class="flex-1 overflow-y-auto wa-scrollbar">
            <!-- Large avatar header (hidden while searching) -->
            <div
                v-if="!searchQuery"
                class="flex justify-center py-6"
            >
                <div
                    class="w-[150px] h-[150px] rounded-full flex items-center justify-center text-5xl font-medium shadow"
                    :style="{ background: 'var(--wa-avatar-bg)', color: 'var(--wa-avatar-icon)' }"
                >
                    {{ initial(user?.name) }}
                </div>
            </div>

            <!-- Admin items (existing app sections) -->
            <template v-if="filteredAdminItems.length > 0">
                <Link
                    v-for="item in filteredAdminItems"
                    :key="item.routeName"
                    :href="route(item.routeName)"
                    class="settings-item w-full flex items-center gap-4 px-6 py-[14px] text-left transition"
                    :class="{ 'is-active': isAdminActive(item) }"
                >
                    <div class="shrink-0 w-6 flex items-center justify-center text-[var(--wa-icon)]">
                        <svg v-if="item.icon === 'connection'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 16l-4-4m0 0l4-4m-4 4h16m-4 4l4-4m0 0l-4-4" />
                        </svg>
                        <svg v-else-if="item.icon === 'departments'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                        <svg v-else-if="item.icon === 'users'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                        </svg>
                        <svg v-else-if="item.icon === 'clients'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 21v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2" />
                            <circle cx="9" cy="7" r="4" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M23 21v-2a4 4 0 00-3-3.87" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 3.13a4 4 0 010 7.75" />
                        </svg>
                        <svg v-else class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-[15px] text-[var(--wa-text)] truncate">{{ item.label }}</div>
                        <div class="text-xs text-[var(--wa-text-secondary)] truncate">{{ item.description }}</div>
                    </div>
                </Link>

                <!-- Divider between admin items and WhatsApp items -->
                <div
                    v-if="filteredProfileItems.length > 0"
                    class="mx-6 my-2 h-px"
                    :style="{ background: 'var(--wa-border)' }"
                ></div>
            </template>

            <!-- WhatsApp-style items -->
            <Link
                v-for="item in filteredProfileItems"
                :key="item.id"
                :href="route('profile.edit', { section: item.id })"
                class="settings-item w-full flex items-center gap-4 px-6 py-[14px] text-left transition"
                :class="{ 'is-active': isProfileActive(item) }"
            >
                <div class="shrink-0 w-6 flex items-center justify-center text-[var(--wa-icon)]">
                    <svg v-if="item.icon === 'user'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                    </svg>
                    <svg v-else-if="item.icon === 'key'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
                    </svg>
                    <svg v-else-if="item.icon === 'lock'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                    </svg>
                    <svg v-else-if="item.icon === 'chat'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
                    </svg>
                    <svg v-else-if="item.icon === 'bell'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                    </svg>
                    <svg v-else-if="item.icon === 'keyboard'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 10.5h.008v.008H6V10.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 3h.008v.008H6v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM9 10.5h.008v.008H9V10.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM9 13.5h.008v.008H9v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm1.125-3h.008v.008H10.5V10.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 3h.008v.008h-.008v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm1.125-3h.008v.008H12V10.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM12 13.5h.008v.008H12v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm1.125-3h.008v.008h-.008V10.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 3h.008v.008h-.008v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm1.125-3h.008v.008H15V10.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 3h.008v.008H15v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm1.125-3h.008v.008h-.008V10.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 3h.008v.008h-.008v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM6 16.5h12M3.75 7.5h16.5c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125H3.75c-.621 0-1.125-.504-1.125-1.125v-9.75C2.625 8.004 3.129 7.5 3.75 7.5z" />
                    </svg>
                    <svg v-else class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" />
                    </svg>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-[15px] text-[var(--wa-text)] truncate">{{ item.label }}</div>
                    <div class="text-xs text-[var(--wa-text-secondary)] truncate">{{ item.description }}</div>
                </div>
            </Link>
        </div>

        <!-- Logout at bottom -->
        <button
            type="button"
            @click="logout"
            class="settings-item w-full flex items-center gap-4 px-6 py-[14px] text-left shrink-0 logout-item"
        >
            <div class="shrink-0 w-6 flex items-center justify-center text-[var(--wa-danger)]">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                </svg>
            </div>
            <div class="text-[15px] text-[var(--wa-danger)]">Выход</div>
        </button>
    </aside>
</template>

<style scoped>
.settings-search {
    background-color: var(--wa-panel-header);
    border: 2px solid transparent;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.settings-search:focus-within {
    border-color: var(--wa-accent);
    background-color: var(--wa-panel);
}
.settings-search-icon {
    color: var(--wa-icon);
}
.settings-search:focus-within .settings-search-icon {
    color: var(--wa-accent);
}
.settings-item {
    transition: background-color 0.15s ease;
}
.settings-item:hover {
    background-color: var(--wa-panel-hover);
}
.settings-item.is-active {
    background-color: var(--wa-selected);
}
.logout-item {
    border-top: 1px solid var(--wa-border);
}
.logout-item:hover {
    background-color: var(--wa-panel-hover);
}
</style>
