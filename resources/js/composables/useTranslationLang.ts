import { computed, type ComputedRef, type Ref } from 'vue';
import type { AppLocale } from '@/i18n/types';
import { useLocalSetting } from './useLocalSetting';
import type { MessageLanguageTarget } from '@/utils/messageLanguage';

export type TranslationLang = MessageLanguageTarget | 'off';

const MIGRATION_FLAG = 'accel.settings.translate_migrated_v2';

function migrateFromLegacyLangSetting(enabled: Ref<boolean>): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (window.localStorage.getItem(MIGRATION_FLAG) === 'true') {
        return;
    }

    try {
        const raw = window.localStorage.getItem('accel.settings.translate_lang');
        if (raw !== null) {
            const legacy = JSON.parse(raw) as TranslationLang;
            if (legacy !== 'off') {
                enabled.value = true;
            }
        }
    } catch {
        // ignore corrupt legacy value
    }

    window.localStorage.setItem(MIGRATION_FLAG, 'true');
}

export function localeToTranslationTarget(locale: AppLocale): MessageLanguageTarget {
    return locale;
}

/**
 * Настройка перевода входящих сообщений.
 * Целевой язык совпадает с языком интерфейса (ui_locale).
 */
export function useTranslationLang(uiLocale?: Ref<AppLocale> | ComputedRef<AppLocale>) {
    const enabled = useLocalSetting<boolean>('translate_enabled', false);

    migrateFromLegacyLangSetting(enabled);

    const targetLang = computed<MessageLanguageTarget>(() => {
        const locale = uiLocale?.value ?? 'ru';

        return localeToTranslationTarget(locale);
    });

    return { enabled, targetLang };
}
