export const APP_LOCALES = ['ru', 'kk', 'en'] as const;

export type AppLocale = (typeof APP_LOCALES)[number];

export function isAppLocale(value: string): value is AppLocale {
    return (APP_LOCALES as readonly string[]).includes(value);
}

export interface SidebarNavItemCopy {
    label: string;
    description: string;
}

export interface MessageCatalog {
    nav: {
        chats: string;
        clients: string;
        broadcasts: string;
        aiChat: string;
        analytics: string;
        calendar: string;
        calendarToday: string;
        funnels: string;
        profile: string;
    };
    whatsapp: {
        status: {
            connected: string;
            qrPending: string;
            connecting: string;
            disconnected: string;
        };
    };
    settings: {
        chats: {
            title: string;
        };
        sidebar: {
            search: string;
            searchAria: string;
            logout: string;
            onboarding: SidebarNavItemCopy;
            connections: SidebarNavItemCopy;
            departments: SidebarNavItemCopy;
            users: SidebarNavItemCopy;
            promotions: SidebarNavItemCopy;
            funnels: SidebarNavItemCopy;
            clients: SidebarNavItemCopy;
            contactFields: SidebarNavItemCopy;
            products: SidebarNavItemCopy;
            services: SidebarNavItemCopy;
            knowledge: SidebarNavItemCopy;
            aiQuality: SidebarNavItemCopy;
            toneProfile: SidebarNavItemCopy;
            system: SidebarNavItemCopy;
            profile: SidebarNavItemCopy;
            account: SidebarNavItemCopy;
            chats: SidebarNavItemCopy;
            notifications: SidebarNavItemCopy;
            shortcuts: SidebarNavItemCopy;
        };
        departments: {
            title: string;
            subtitle: string;
            addButton: string;
            intro: string;
            searchPlaceholder: string;
            filterStatusAll: string;
            filterStatusActive: string;
            filterStatusInactive: string;
            filterLevelAll: string;
            filterLevelRoot: string;
            filterLevelNested: string;
            filterMembersAll: string;
            filterMembersWithUsers: string;
            filterMembersEmpty: string;
            resetFilters: string;
            shownOf: string;
            filterActive: string;
            emptyFiltered: string;
            emptyDefault: string;
            childDept: string;
            inactiveBadge: string;
            levelBadge: string;
            usersCountOne: string;
            usersCountMany: string;
            newDept: string;
            editDept: string;
            saving: string;
            create: string;
            deleteTitle: string;
            deleteDescription: string;
            deleteDescriptionChildren: string;
            errorSave: string;
            errorNameRequired: string;
            scheduleNotSet: string;
        };
        users: {
            title: string;
            subtitle: string;
            addButton: string;
            intro: string;
            searchPlaceholder: string;
            filterRoleAll: string;
            filterDeptAll: string;
            filterStatusAll: string;
            filterStatusActive: string;
            filterStatusInactive: string;
            resetFilters: string;
            shownRange: string;
            pageOf: string;
            colName: string;
            colActions: string;
            colEmail: string;
            colPhone: string;
            colRole: string;
            colDepartments: string;
            colWhatsapp: string;
            colStatus: string;
            empty: string;
            rowEditHint: string;
            edit: string;
            delete: string;
            statusActive: string;
            statusInactive: string;
            newUser: string;
            editUser: string;
            saving: string;
            deleteTitle: string;
            deleteDescription: string;
            errorSave: string;
            errorNameRequired: string;
            toastSaved: string;
            toastCreated: string;
            toastDeleted: string;
        };
        roles: {
            administrator: string;
            manager: string;
            employee: string;
        };
        weekdays: {
            mon: string;
            tue: string;
            wed: string;
            thu: string;
            fri: string;
            sat: string;
            sun: string;
        };
        connections: {
            title: string;
            subtitle: string;
            addConnection: string;
            creating: string;
            bootstrapping: string;
            serviceUnavailable: string;
            serviceUnavailableAction: string;
            limitsCount: string;
            limitsServer: string;
            limitsExhausted: string;
            emptyTitle: string;
            emptyHint: string;
            createFirst: string;
            multiSessionsTitle: string;
            multiSessionsHint: string;
            colorLabelMulti: string;
            colorLabelSingle: string;
            displayNamePlaceholder: string;
            pickRingColor: string;
            presetColors: string;
            saving: string;
            confirmLogoutTitle: string;
            confirmRemoveTitle: string;
            confirmLogoutDescription: string;
            confirmRemoveDescription: string;
            confirmLogout: string;
            confirmRemove: string;
            errorLogout: string;
            errorRemove: string;
            errorGeneric: string;
            errorCreate: string;
            errorInitialize: string;
            errorQr: string;
            errorStatus: string;
            errorVerify: string;
            errorDisplayNameRequired: string;
            errorSaveName: string;
        };
        interface: {
            language: string;
            languageHint: string;
        };
        theme: {
            light: string;
            dark: string;
        };
    };
    common: {
        cancel: string;
        save: string;
        close: string;
        done: string;
        delete: string;
    };
}

type LeafPaths<T, Prefix extends string = ''> = T extends string
    ? Prefix
    : {
          [K in keyof T & string]: LeafPaths<T[K], Prefix extends '' ? K : `${Prefix}.${K}`>;
      }[keyof T & string];

export type MessageKey = LeafPaths<MessageCatalog>;

export interface LocaleOption {
    value: AppLocale;
    label: string;
    flag: string;
}

export const LOCALE_OPTIONS: LocaleOption[] = [
    { value: 'ru', label: 'Русский', flag: '🇷🇺' },
    { value: 'kk', label: 'Қазақша', flag: '🇰🇿' },
    { value: 'en', label: 'English', flag: '🇬🇧' },
];
