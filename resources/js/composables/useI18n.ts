import { messagesForLocale } from '@/i18n/messages';
import { translate } from '@/i18n/translate';
import type { AppLocale, LocaleOption, MessageKey } from '@/i18n/types';
import { APP_LOCALES, isAppLocale, LOCALE_OPTIONS } from '@/i18n/types';
import { computed, watch } from 'vue';
import { useLocalSetting } from './useLocalSetting';

const locale = useLocalSetting<AppLocale>('ui_locale', 'ru');
let initializedFromServer = false;

function applyDocumentLocale(value: AppLocale): void {
    if (typeof document === 'undefined') {
        return;
    }
    document.documentElement.lang = value;
}

watch(locale, applyDocumentLocale, { immediate: true });

export function initI18n(defaultLocale?: string): void {
    if (initializedFromServer || typeof window === 'undefined') {
        return;
    }

    initializedFromServer = true;

    const stored = window.localStorage.getItem('accel.settings.ui_locale');
    if (stored === null && defaultLocale && isAppLocale(defaultLocale)) {
        locale.value = defaultLocale;
    }

    applyDocumentLocale(locale.value);
}

export function useI18n() {
    const catalog = computed(() => messagesForLocale(locale.value));

    function t(key: MessageKey | string, params?: Record<string, string | number>): string {
        return translate(catalog.value, key, params);
    }

    function setLocale(value: AppLocale): void {
        locale.value = value;
    }

    const currentLocale = computed<LocaleOption>(
        () => LOCALE_OPTIONS.find((option) => option.value === locale.value) ?? LOCALE_OPTIONS[0],
    );

    return {
        locale,
        locales: LOCALE_OPTIONS,
        supportedLocales: APP_LOCALES,
        currentLocale,
        t,
        setLocale,
    };
}
