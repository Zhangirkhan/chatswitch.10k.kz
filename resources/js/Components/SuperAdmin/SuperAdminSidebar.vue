<script setup lang="ts">
import SuperAdminNavIcon from '@/Components/SuperAdmin/SuperAdminNavIcon.vue';
import { useI18n } from '@/composables/useI18n';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

type NavItem = {
    href: string;
    labelKey: keyof typeof navKeyMap extends never ? string : string;
    match: string;
    badge?: number;
};

const navKeyMap = {
    dashboard: 'dashboard',
    aiSales: 'aiSales',
    companies: 'companies',
    invoices: 'invoices',
    plans: 'plans',
    signupRequests: 'signupRequests',
    contact: 'contact',
    mobileReleases: 'mobileReleases',
    platformChangelog: 'platformChangelog',
    platformBanners: 'platformBanners',
    auditLogs: 'auditLogs',
} as const;

type NavKey = keyof typeof navKeyMap;

type NavGroup = {
    groupKey: 'overview' | 'operations' | 'billing' | 'platform';
    items: Array<{ key: NavKey; href: string; match: string; badge?: number }>;
};

const props = defineProps<{
    mobileOpen?: boolean;
}>();

const emit = defineEmits<{
    navigate: [];
}>();

const { t } = useI18n();
const page = usePage<any>();

type SuperAdminNavProps = {
    pending_signups?: number;
    unread_feedback?: number;
    is_sandbox?: boolean;
};

const superAdminNav = computed(() => page.props.superAdminNav as SuperAdminNavProps | null);

const isSandboxSuperAdmin = computed(
    () => (page.props.isSandboxSuperAdmin as boolean | undefined) === true
        || superAdminNav.value?.is_sandbox === true,
);

const navGroups = computed((): NavGroup[] => {
    const groups: NavGroup[] = [
        {
            groupKey: 'overview',
            items: [{ key: 'dashboard', href: '/dashboard', match: '/dashboard' }],
        },
        {
            groupKey: 'operations',
            items: [
                { key: 'companies', href: '/companies', match: '/companies' },
                { key: 'aiSales', href: '/ai-sales', match: '/ai-sales' },
                {
                    key: 'signupRequests',
                    href: '/signup-requests',
                    match: '/signup-requests',
                    badge: superAdminNav.value?.pending_signups,
                },
                {
                    key: 'contact',
                    href: '/contact-messages',
                    match: '/contact-messages',
                    badge: superAdminNav.value?.unread_feedback,
                },
            ],
        },
        {
            groupKey: 'billing',
            items: [
                { key: 'invoices', href: '/invoices', match: '/invoices' },
                { key: 'plans', href: '/plans', match: '/plans' },
            ],
        },
        {
            groupKey: 'platform',
            items: [
                { key: 'mobileReleases', href: '/mobile-releases', match: '/mobile-releases' },
                { key: 'platformChangelog', href: '/platform-changelog', match: '/platform-changelog' },
                { key: 'platformBanners', href: '/platform-banners', match: '/platform-banners' },
                { key: 'auditLogs', href: '/audit-logs', match: '/audit-logs' },
            ],
        },
    ];

    if (isSandboxSuperAdmin.value) {
        return [
            groups[0],
            {
                groupKey: 'operations',
                items: groups[1].items.filter((item) => ['companies', 'aiSales'].includes(item.key)),
            },
            {
                groupKey: 'billing',
                items: [{ key: 'invoices', href: '/invoices', match: '/invoices' }],
            },
        ];
    }

    return groups;
});

function isActive(match: string): boolean {
    return page.url.startsWith(match);
}

function labelFor(key: NavKey): string {
    return t(`superAdmin.layout.nav.${key}`);
}

function onNavigate(): void {
    emit('navigate');
}
</script>

<template>
    <aside
        class="ui-super-admin-sidebar"
        :class="{ 'ui-super-admin-sidebar--open': mobileOpen }"
        aria-label="Super Admin navigation"
    >
        <div class="ui-super-admin-sidebar__brand">
            <span class="ui-super-admin-sidebar__logo">A</span>
            <div class="min-w-0">
                <p class="ui-super-admin-sidebar__title">{{ t('superAdmin.layout.brand') }}</p>
                <p class="ui-super-admin-sidebar__subtitle truncate">{{ t('superAdmin.layout.brandHint') }}</p>
            </div>
        </div>

        <nav class="ui-super-admin-sidebar__nav wa-scrollbar">
            <div v-for="group in navGroups" :key="group.groupKey" class="ui-super-admin-nav-group">
                <p class="ui-super-admin-nav-group__label">
                    {{ t(`superAdmin.layout.navGroups.${group.groupKey}`) }}
                </p>
                <div class="ui-super-admin-nav-group__items">
                    <Link
                        v-for="item in group.items"
                        :key="item.href"
                        :href="item.href"
                        class="ui-super-admin-nav-item"
                        :class="{ 'is-active': isActive(item.match) }"
                        @click="onNavigate"
                    >
                        <span class="ui-super-admin-nav-item__main">
                            <SuperAdminNavIcon :name="item.key" />
                            <span class="ui-super-admin-nav-item__label">{{ labelFor(item.key) }}</span>
                        </span>
                        <span
                            v-if="item.badge !== undefined && item.badge > 0"
                            class="ui-super-admin-nav-item__badge"
                        >
                            {{ item.badge > 99 ? '99+' : item.badge }}
                        </span>
                    </Link>
                </div>
            </div>
        </nav>
    </aside>
</template>
