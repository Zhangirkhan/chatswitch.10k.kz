<script setup lang="ts">
import UserAvatar from '@/Components/UserAvatar.vue';
import { useI18n } from '@/composables/useI18n';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type AdminItem = {
    kind: 'admin';
    i18nKey:
        | 'onboarding'
        | 'connections'
        | 'departments'
        | 'users'
        | 'roles'
        | 'promotions'
        | 'funnels'
        | 'clients'
        | 'contactFields'
        | 'products'
        | 'services'
        | 'knowledge'
        | 'aiQuality'
        | 'toneProfile'
        | 'system';
    icon: string;
    routeName: string;
    /** Имя модуля в `page.props.modules`. Если задано и модуль выключен — пункт скрыт. */
    moduleKey?: string;
};

type ProfileItem = {
    kind: 'profile';
    id: 'profile' | 'account' | 'chats' | 'notifications' | 'shortcuts' | 'contact';
    icon: string;
    adminOnly?: boolean;
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

const { t } = useI18n();

const searchQuery = ref('');

const adminItems: AdminItem[] = [
    {
        kind: 'admin',
        i18nKey: 'onboarding',
        icon: 'onboarding',
        routeName: 'settings.onboarding',
    },
    {
        kind: 'admin',
        i18nKey: 'connections',
        icon: 'connection',
        routeName: 'settings.connections',
    },
    {
        kind: 'admin',
        i18nKey: 'departments',
        icon: 'departments',
        routeName: 'settings.departments',
    },
    {
        kind: 'admin',
        i18nKey: 'users',
        icon: 'users',
        routeName: 'settings.users',
    },
    {
        kind: 'admin',
        i18nKey: 'roles',
        icon: 'users',
        routeName: 'settings.roles',
    },
    {
        kind: 'admin',
        i18nKey: 'promotions',
        icon: 'promotions',
        routeName: 'settings.promotions',
        moduleKey: 'funnels',
    },
    {
        kind: 'admin',
        i18nKey: 'funnels',
        icon: 'funnel',
        routeName: 'settings.funnels',
        moduleKey: 'funnels',
    },
    {
        kind: 'admin',
        i18nKey: 'clients',
        icon: 'clients',
        routeName: 'clients.index',
        moduleKey: 'clients',
    },
    {
        kind: 'admin',
        i18nKey: 'contactFields',
        icon: 'clients',
        routeName: 'settings.contact-fields',
    },
    {
        kind: 'admin',
        i18nKey: 'products',
        icon: 'products',
        routeName: 'settings.knowledge.products',
        moduleKey: 'products',
    },
    {
        kind: 'admin',
        i18nKey: 'services',
        icon: 'services',
        routeName: 'settings.knowledge.services',
        moduleKey: 'services',
    },
    {
        kind: 'admin',
        i18nKey: 'knowledge',
        icon: 'knowledge',
        routeName: 'settings.knowledge.rules',
        moduleKey: 'knowledge',
    },
    {
        kind: 'admin',
        i18nKey: 'aiQuality',
        icon: 'ai-quality',
        routeName: 'settings.ai-quality',
        moduleKey: 'ai_quality',
    },
    {
        kind: 'admin',
        i18nKey: 'toneProfile',
        icon: 'tone',
        routeName: 'settings.tone-profile',
    },
    {
        kind: 'admin',
        i18nKey: 'system',
        icon: 'system',
        routeName: 'settings.system',
    },
];

const profileItems: ProfileItem[] = [
    { kind: 'profile', id: 'profile', icon: 'user' },
    { kind: 'profile', id: 'account', icon: 'lock' },
    { kind: 'profile', id: 'chats', icon: 'chat' },
    { kind: 'profile', id: 'notifications', icon: 'bell' },
    { kind: 'profile', id: 'contact', icon: 'contact' },
    { kind: 'profile', id: 'shortcuts', icon: 'keyboard' },
];

function sidebarItemLabel(item: Item): string {
    if (item.kind === 'admin') {
        return t(`settings.sidebar.${item.i18nKey}.label`);
    }
    return t(`settings.sidebar.${item.id}.label`);
}

function sidebarItemDescription(item: Item): string {
    if (item.kind === 'admin') {
        return t(`settings.sidebar.${item.i18nKey}.description`);
    }
    return t(`settings.sidebar.${item.id}.description`);
}

function matchesQuery(item: Item): boolean {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return true;
    const label = sidebarItemLabel(item).toLowerCase();
    const description = sidebarItemDescription(item).toLowerCase();
    return label.includes(q) || description.includes(q);
}

const modulesEnabled = computed<Record<string, boolean>>(() => (page.props.modules ?? {}) as Record<string, boolean>);

function isModuleEnabledFor(item: AdminItem): boolean {
    if (!item.moduleKey) return true;
    const value = modulesEnabled.value[item.moduleKey];
    return value === undefined ? true : Boolean(value);
}

const filteredAdminItems = computed(() => (isAdmin.value
    ? adminItems.filter(isModuleEnabledFor).filter(matchesQuery)
    : []));
const filteredProfileItems = computed(() => profileItems
    .filter((item) => !item.adminOnly || isAdmin.value)
    .filter(matchesQuery));

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

function logout() {
    router.post(route('logout'));
}
</script>

<template>
    <aside class="w-[260px] sm:w-[320px] lg:w-[400px] h-full flex flex-col bg-[var(--ui-surface)] shrink-0">
        <!-- Header with user name -->
        <div class="h-[60px] px-6 flex items-center shrink-0 border-b border-[var(--ui-border)]">
            <h1 class="text-[var(--ui-text)] text-xl font-semibold truncate">
                {{ user?.name }}
            </h1>
        </div>

        <!-- Search -->
        <div class="px-3 pb-2 shrink-0">
            <div class="settings-search relative rounded-full">
                <svg
                    class="settings-search-icon absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 transition-colors"
                    fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input
                    v-model="searchQuery"
                    type="text"
                    :placeholder="t('settings.sidebar.search')"
                    :aria-label="t('settings.sidebar.searchAria')"
                    class="w-full pl-12 pr-4 py-[9px] bg-transparent rounded-full text-sm text-[var(--ui-text)] border-0 focus:ring-0 focus:outline-none relative z-[1]"
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
                <UserAvatar
                    :name="user?.name"
                    :avatar-url="user?.avatar_url"
                    :size="150"
                />
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
                    <div class="shrink-0 w-6 flex items-center justify-center text-[var(--ui-icon)]">
                        <svg v-if="item.icon === 'onboarding'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <svg v-else-if="item.icon === 'connection'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
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
                        <svg v-else-if="item.icon === 'funnel'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 4h18l-7 9v6l-4 2v-8L3 4z" />
                        </svg>
                        <svg v-else-if="item.icon === 'promotions'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 .953.343 1.087.835l.415 1.66a2 2 0 001.85 1.505h4.293a1 1 0 01.97 1.243l-1.5 6A2 2 0 0116.5 17H8a2 2 0 01-1.98-1.65L4.5 5.65A2 2 0 016.48 4H7z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 21a2 2 0 100-4 2 2 0 000 4zM17 21a2 2 0 100-4 2 2 0 000 4z" />
                        </svg>
                        <svg v-else-if="item.icon === 'products'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5l9-4 9 4-9 4-9-4z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5v9l9 4 9-4v-9M12 11.5v9" />
                        </svg>
                        <svg v-else-if="item.icon === 'services'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18M5 7h14M7 7l-3 6h6L7 7zM17 7l-3 6h6l-3-6z" />
                        </svg>
                        <svg v-else-if="item.icon === 'knowledge'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 5.5A2.5 2.5 0 016.5 3H20v16H6.5A2.5 2.5 0 004 21.5v-16z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h8M8 11h8M8 15h5" />
                        </svg>
                        <svg v-else-if="item.icon === 'tone'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
                        </svg>
                        <svg v-else-if="item.icon === 'ai-quality'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09L9 18.75l.813-2.846a4.5 4.5 0 003.09-3.09L15.75 12l-2.846-.813a4.5 4.5 0 00-3.09-3.09L9 5.25z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456L18 9.75l-.259-1.035a3.375 3.375 0 00-2.456-2.456L14.25 6l1.035-.259a3.375 3.375 0 002.456-2.456L18 2.25z" />
                        </svg>
                        <svg v-else class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-[15px] text-[var(--ui-text)] truncate">{{ sidebarItemLabel(item) }}</div>
                        <div class="text-xs text-[var(--ui-text-secondary)] truncate">{{ sidebarItemDescription(item) }}</div>
                    </div>
                </Link>

                <!-- Divider between admin items and WhatsApp items -->
                <div
                    v-if="filteredProfileItems.length > 0"
                    class="mx-6 my-2 h-px"
                    :style="{ background: 'var(--ui-border)' }"
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
                <div class="shrink-0 w-6 flex items-center justify-center text-[var(--ui-icon)]">
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
                    <svg v-else-if="item.icon === 'contact'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5M21 12c0 4.418-4.03 8-9 8a9.77 9.77 0 01-4-.8L3 20l1.8-5.4A7.77 7.77 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                    </svg>
                    <svg v-else-if="item.icon === 'keyboard'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 10.5h.008v.008H6V10.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 3h.008v.008H6v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM9 10.5h.008v.008H9V10.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM9 13.5h.008v.008H9v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm1.125-3h.008v.008H10.5V10.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 3h.008v.008h-.008v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm1.125-3h.008v.008H12V10.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM12 13.5h.008v.008H12v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm1.125-3h.008v.008h-.008V10.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 3h.008v.008h-.008v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm1.125-3h.008v.008H15V10.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 3h.008v.008H15v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm1.125-3h.008v.008h-.008V10.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 3h.008v.008h-.008v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM6 16.5h12M3.75 7.5h16.5c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125H3.75c-.621 0-1.125-.504-1.125-1.125v-9.75C2.625 8.004 3.129 7.5 3.75 7.5z" />
                    </svg>
                    <svg v-else-if="item.icon === 'modules'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <rect x="3" y="3" width="7" height="7" rx="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        <rect x="14" y="3" width="7" height="7" rx="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        <rect x="3" y="14" width="7" height="7" rx="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        <rect x="14" y="14" width="7" height="7" rx="1.5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <svg v-else class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" />
                    </svg>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-[15px] text-[var(--ui-text)] truncate">{{ sidebarItemLabel(item) }}</div>
                    <div class="text-xs text-[var(--ui-text-secondary)] truncate">{{ sidebarItemDescription(item) }}</div>
                </div>
            </Link>
        </div>

        <!-- Logout at bottom -->
        <button
            type="button"
            @click="logout"
            class="settings-item w-full flex items-center gap-4 px-6 py-[14px] text-left shrink-0 logout-item"
        >
            <div class="shrink-0 w-6 flex items-center justify-center text-[var(--ui-danger)]">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                </svg>
            </div>
            <div class="text-[15px] text-[var(--ui-danger)]">{{ t('settings.sidebar.logout') }}</div>
        </button>
    </aside>
</template>

<style scoped>
.settings-search {
    background-color: var(--ui-surface-inset);
    border: 1px solid var(--ui-border-strong);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.02);
    transition: border-color 0.15s ease, background-color 0.15s ease, box-shadow 0.15s ease;
}
.settings-search:focus-within {
    border-color: var(--ui-accent);
    background-color: var(--ui-input-bg);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--ui-accent) 14%, transparent);
}
.settings-search-icon {
    color: var(--ui-icon);
}
.settings-search:focus-within .settings-search-icon {
    color: var(--ui-accent);
}
.settings-item {
    border-left: 3px solid transparent;
    position: relative;
    transition: background-color 0.15s ease, border-color 0.15s ease;
}
.settings-item:hover {
    background-color: var(--ui-surface-muted);
}
.settings-item.is-active {
    background: color-mix(in srgb, var(--ui-accent) 18%, var(--ui-surface-muted));
    border-left-color: var(--ui-accent);
}
.settings-item.is-active > div:first-child {
    color: var(--ui-accent);
}
.logout-item {
    border-top: 1px solid var(--ui-border-strong);
}
.logout-item:hover {
    background-color: var(--ui-surface-muted);
}
</style>
