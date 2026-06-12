import {
    computed,
    h,
    inject,
    onUnmounted,
    provide,
    shallowRef,
    watchEffect,
    type ComputedRef,
    type ShallowRef,
    type Slots,
    type VNode,
} from 'vue';

export type SuperAdminAccentGroup = 'overview' | 'operations' | 'billing' | 'platform';

export type SuperAdminPageChromeState = {
    eyebrow?: string;
    title: string;
    subtitle?: string;
    accentGroup?: SuperAdminAccentGroup;
    titleRow?: VNode | null;
    actionsVnode: VNode | null;
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

function buildActionsVnode(slots: Slots): VNode | null {
    if (!slots.actions) {
        return null;
    }

    return h(
        'div',
        { class: 'ui-super-admin-topbar-chrome__actions' },
        slots.actions(),
    );
}

type RegisterSuperAdminPageChromeInput = {
    eyebrow?: string;
    title: string;
    subtitle?: string;
    accentGroup?: SuperAdminAccentGroup;
    titleRow?: VNode | null;
    slots?: Slots;
    actionsVnode?: VNode | null;
};

export function useRegisterSuperAdminPageChrome(input: () => RegisterSuperAdminPageChromeInput): void {
    const context = inject<SuperAdminPageChromeContext>(SUPER_ADMIN_PAGE_CHROME_KEY);

    if (!context) {
        throw new Error('useRegisterSuperAdminPageChrome must be used within SuperAdminLayout');
    }

    watchEffect(() => {
        const value = input();
        const actionsFromSlots = value.slots ? buildActionsVnode(value.slots) : null;

        context.register({
            eyebrow: value.eyebrow,
            title: value.title,
            subtitle: value.subtitle,
            accentGroup: value.accentGroup,
            titleRow: value.titleRow ?? null,
            actionsVnode: value.actionsVnode ?? actionsFromSlots,
        });
    });

    onUnmounted(() => {
        context.clear();
    });
}
