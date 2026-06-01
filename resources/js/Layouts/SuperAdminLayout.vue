<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useTheme } from '@/composables/useTheme';

const page = usePage<any>();
const user = computed(() => page.props.auth?.user);

type SuperAdminNavProps = {
    pending_signups?: number;
    is_sandbox?: boolean;
};

type NavItem = {
    href: string;
    label: string;
    match: string;
    badge?: number;
};

const superAdminNav = computed(() => page.props.superAdminNav as SuperAdminNavProps | null);

const flashSuccess = computed(() => {
    const flash = page.props.flash as { success?: string } | undefined;
    return typeof flash?.success === 'string' ? flash.success : '';
});

const flashError = computed(() => {
    const flash = page.props.flash as { error?: string } | undefined;
    return typeof flash?.error === 'string' ? flash.error : '';
});

const validationBanner = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;
    if (!errors || typeof errors !== 'object') {
        return '';
    }
    const first = Object.values(errors).find((v) => typeof v === 'string' && v.length > 0);
    return first ?? '';
});

const { theme, toggle: toggleTheme } = useTheme();

const navOpen = ref(false);

const isSandboxSuperAdmin = computed(
    () => (page.props.isSandboxSuperAdmin as boolean | undefined) === true
        || superAdminNav.value?.is_sandbox === true,
);

const navItems = computed((): NavItem[] => {
    const items: NavItem[] = [
        { href: '/dashboard', label: 'Дашборд', match: '/dashboard' },
        { href: '/companies', label: 'Компании', match: '/companies' },
        { href: '/invoices', label: 'Счета', match: '/invoices' },
    ];

    if (!isSandboxSuperAdmin.value) {
        items.push(
            { href: '/plans', label: 'Тарифы', match: '/plans' },
            {
                href: '/signup-requests',
                label: 'Заявки',
                match: '/signup-requests',
                badge: superAdminNav.value?.pending_signups,
            },
            { href: '/audit-logs', label: 'Журнал', match: '/audit-logs' },
        );
    }

    return items;
});

function isActive(match: string): boolean {
    return page.url.startsWith(match);
}

function closeNav(): void {
    navOpen.value = false;
}

watch(
    () => page.url,
    () => {
        navOpen.value = false;
    },
);
</script>

<template>
    <div class="super-admin-shell flex min-h-dvh max-h-dvh flex-col bg-ui-bg text-ui-text">
        <header class="sticky top-0 z-40 shrink-0 border-b border-ui-border bg-ui-surface/95 backdrop-blur supports-[backdrop-filter]:bg-ui-surface/80">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3 sm:px-6 sm:py-4">
                <div class="flex min-w-0 items-center gap-3">
                    <button
                        type="button"
                        class="ui-btn ui-btn--ghost inline-flex h-10 w-10 shrink-0 items-center justify-center !px-0 lg:hidden"
                        :aria-expanded="navOpen"
                        aria-controls="super-admin-nav"
                        aria-label="Меню"
                        @click="navOpen = !navOpen"
                    >
                        <svg v-if="!navOpen" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" />
                        </svg>
                        <svg v-else class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                    <span class="truncate text-base font-semibold tracking-tight sm:text-lg">Accel Super Admin</span>
                </div>
                <div class="flex shrink-0 items-center gap-2 sm:gap-3">
                    <button
                        type="button"
                        class="ui-btn ui-btn--ghost ui-btn--sm"
                        :aria-label="theme === 'dark' ? 'Светлая тема' : 'Тёмная тема'"
                        :title="theme === 'dark' ? 'Светлая тема' : 'Тёмная тема'"
                        @click="toggleTheme"
                    >
                        <svg v-if="theme === 'dark'" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <circle cx="12" cy="12" r="4" />
                            <path stroke-linecap="round" d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
                        </svg>
                        <svg v-else class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
                        </svg>
                    </button>
                    <span class="hidden max-w-[12rem] truncate text-sm text-ui-text-secondary sm:inline md:max-w-xs">{{ user?.email }}</span>
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        class="ui-btn ui-btn--secondary ui-btn--sm"
                    >
                        Выйти
                    </Link>
                </div>
            </div>

            <nav
                id="super-admin-nav"
                class="border-t border-ui-border lg:border-t-0"
                :class="navOpen ? 'block' : 'hidden lg:block'"
            >
                <div class="mx-auto flex max-w-6xl flex-col gap-1 px-4 py-3 sm:px-6 lg:flex-row lg:gap-2 lg:py-0 lg:pb-3">
                    <Link
                        v-for="item in navItems"
                        :key="item.href"
                        :href="item.href"
                        class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition-colors lg:py-1.5"
                        :class="isActive(item.match)
                            ? 'bg-ui-selected font-medium text-ui-text'
                            : 'text-ui-text-secondary hover:bg-ui-surface-hover hover:text-ui-text'"
                        @click="closeNav"
                    >
                        {{ item.label }}
                        <span
                            v-if="item.badge !== undefined && item.badge > 0"
                            class="ui-badge ui-badge--admin min-w-[1.25rem] justify-center px-1.5 py-0 text-xs"
                        >
                            {{ item.badge }}
                        </span>
                    </Link>
                </div>
                <p class="mx-auto max-w-6xl truncate px-4 pb-3 text-xs text-ui-text-muted sm:px-6 lg:hidden">{{ user?.email }}</p>
            </nav>
        </header>

        <main class="super-admin-main wa-scrollbar min-h-0 flex-1 overflow-y-auto overflow-x-hidden">
            <div class="mx-auto w-full max-w-6xl px-4 py-6 pb-10 sm:px-6 sm:py-8">
                <p
                    v-if="flashSuccess"
                    class="ui-alert mb-6 border-ui-accent-border bg-ui-accent-soft text-sm text-ui-text"
                    role="status"
                >
                    {{ flashSuccess }}
                </p>
                <p
                    v-if="flashError"
                    class="ui-alert mb-6 border-red-500/30 bg-red-500/10 text-sm text-red-200"
                    role="alert"
                >
                    {{ flashError }}
                </p>
                <p
                    v-if="validationBanner && !flashError"
                    class="ui-alert mb-6 border-red-500/30 bg-red-500/10 text-sm text-red-200"
                    role="alert"
                >
                    {{ validationBanner }}
                </p>
                <slot />
            </div>
        </main>
    </div>
</template>

<style scoped>
.super-admin-shell {
    height: 100dvh;
    max-height: 100dvh;
}

.super-admin-main {
    -webkit-overflow-scrolling: touch;
}
</style>
