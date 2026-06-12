<script setup lang="ts">
import PlatformBannerStack from '@/Components/PlatformBannerStack.vue';
import SuperAdminSidebar from '@/Components/SuperAdmin/SuperAdminSidebar.vue';
import {
    provideSuperAdminPageChrome,
    useSuperAdminPageChrome,
} from '@/composables/useSuperAdminPageChrome';
import { useI18n } from '@/composables/useI18n';
import { useTheme } from '@/composables/useTheme';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

provideSuperAdminPageChrome();
const pageChrome = useSuperAdminPageChrome();

const page = usePage<any>();
const { t } = useI18n();
const user = computed(() => page.props.auth?.user);

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
const sidebarOpen = ref(false);

function closeSidebar(): void {
    sidebarOpen.value = false;
}

watch(
    () => page.url,
    () => {
        sidebarOpen.value = false;
    },
);
</script>

<template>
    <div class="ui-super-admin-shell">
        <PlatformBannerStack />

        <div
            v-if="sidebarOpen"
            class="ui-super-admin-backdrop lg:hidden"
            aria-hidden="true"
            @click="closeSidebar"
        />

        <SuperAdminSidebar :mobile-open="sidebarOpen" @navigate="closeSidebar" />

        <div class="ui-super-admin-main-column">
            <header class="ui-super-admin-topbar">
                <div class="ui-super-admin-topbar__left lg:hidden">
                    <button
                        type="button"
                        class="ui-btn ui-btn--ghost ui-super-admin-menu-btn"
                        :aria-expanded="sidebarOpen"
                        :aria-label="t('superAdmin.layout.menuAriaLabel')"
                        @click="sidebarOpen = !sidebarOpen"
                    >
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" />
                        </svg>
                    </button>
                    <span class="ui-super-admin-topbar__title">{{ t('superAdmin.layout.brand') }}</span>
                </div>
                <div
                    v-if="pageChrome"
                    class="ui-super-admin-topbar__page hidden lg:flex"
                >
                    <div
                        class="ui-super-admin-topbar-chrome"
                        :class="pageChrome.accentGroup ? `ui-super-admin-topbar-chrome--${pageChrome.accentGroup}` : undefined"
                    >
                        <p v-if="pageChrome.eyebrow" class="ui-super-admin-topbar-chrome__eyebrow">{{ pageChrome.eyebrow }}</p>
                        <component :is="pageChrome.titleRow" v-if="pageChrome.titleRow" />
                        <template v-else>
                            <h1 class="ui-super-admin-topbar-chrome__title">{{ pageChrome.title }}</h1>
                        </template>
                        <p v-if="pageChrome.subtitle" class="ui-super-admin-topbar-chrome__subtitle">{{ pageChrome.subtitle }}</p>
                    </div>
                </div>
                <div class="ui-super-admin-topbar__actions">
                    <div v-if="pageChrome?.actionsVnode" class="ui-super-admin-topbar__page-actions hidden lg:flex">
                        <component :is="pageChrome.actionsVnode" />
                    </div>
                    <button
                        type="button"
                        class="ui-btn ui-btn--ghost ui-btn--sm ui-super-admin-topbar__theme"
                        :aria-label="theme === 'dark' ? t('superAdmin.layout.theme.light') : t('superAdmin.layout.theme.dark')"
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
                    <span class="ui-super-admin-topbar__user hidden max-w-[14rem] truncate sm:inline">{{ user?.email }}</span>
                    <Link href="/logout" method="post" as="button" class="ui-btn ui-btn--secondary ui-btn--sm">
                        {{ t('superAdmin.layout.logout') }}
                    </Link>
                </div>
            </header>

            <main class="ui-super-admin-main wa-scrollbar">
                <div class="ui-super-admin-page">
                    <p v-if="flashSuccess" class="ui-alert mb-5 border-ui-accent-border bg-ui-accent-soft text-sm text-ui-text" role="status">
                        {{ flashSuccess }}
                    </p>
                    <p v-if="flashError" class="ui-alert mb-5 border-red-500/30 bg-red-500/10 text-sm text-red-200" role="alert">
                        {{ flashError }}
                    </p>
                    <p
                        v-if="validationBanner && !flashError"
                        class="ui-alert mb-5 border-red-500/30 bg-red-500/10 text-sm text-red-200"
                        role="alert"
                    >
                        {{ validationBanner }}
                    </p>
                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>
