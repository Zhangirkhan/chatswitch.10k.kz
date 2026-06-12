import {
    computed,
    inject,
    onUnmounted,
    provide,
    shallowRef,
    watchEffect,
    type ComputedRef,
    type ShallowRef,
} from 'vue';

export type SuperAdminAccentGroup = 'overview' | 'operations' | 'billing' | 'platform';

export type SuperAdminPageChromeBadge = {
    text: string;
    className: string;
};

export type SuperAdminPageChromeState = {
    eyebrow?: string;
    title: string;
    subtitle?: string;
    accentGroup?: SuperAdminAccentGroup;
    titleBadge?: SuperAdminPageChromeBadge;
};

type SuperAdminPageChromeContext = {
    chrome: ShallowRef<SuperAdminPageChromeState | null>;
    register: (state: SuperAdminPageChromeState) => void;
    clear: () => void;
};

const SUPER_ADMIN_PAGE_CHROME_KEY = Symbol('superAdminPageChrome');

export function provideSuperAdminPageChrome(): SuperAdminPageChromeContext {
    const chrome = shallowRef<SuperAdminPageChromeState | null>(null);

    const context: SuperAdminPageChromeContext = {
        chrome,
        register: (state) => {
            chrome.value = state;
        },
        clear: () => {
            chrome.value = null;
        },
    };

    provide(SUPER_ADMIN_PAGE_CHROME_KEY, context);

    return context;
}

export function useSuperAdminPageChrome(): ComputedRef<SuperAdminPageChromeState | null> {
    const context = inject<SuperAdminPageChromeContext>(SUPER_ADMIN_PAGE_CHROME_KEY);

    if (!context) {
        throw new Error('useSuperAdminPageChrome must be used within SuperAdminLayout');
    }

    return computed(() => context.chrome.value);
}

type RegisterSuperAdminPageChromeInput = {
    eyebrow?: string;
    title: string;
    subtitle?: string;
    accentGroup?: SuperAdminAccentGroup;
    titleBadge?: SuperAdminPageChromeBadge;
};

export function useRegisterSuperAdminPageChrome(input: () => RegisterSuperAdminPageChromeInput): void {
    const context = inject<SuperAdminPageChromeContext>(SUPER_ADMIN_PAGE_CHROME_KEY);

    if (!context) {
        throw new Error('useRegisterSuperAdminPageChrome must be used within SuperAdminLayout');
    }

    watchEffect(() => {
        const value = input();

        context.register({
            eyebrow: value.eyebrow,
            title: value.title,
            subtitle: value.subtitle,
            accentGroup: value.accentGroup,
            titleBadge: value.titleBadge,
        });
    });

    onUnmounted(() => {
        context.clear();
    });
}
