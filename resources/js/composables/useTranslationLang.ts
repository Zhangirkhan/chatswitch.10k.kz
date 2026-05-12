import { useLocalSetting } from './useLocalSetting';

export type TranslationLang = 'off' | 'ru' | 'kk' | 'en' | 'zh' | 'tr' | 'ar';

export interface LangOption {
    value: TranslationLang;
    label: string;
    flag: string;
}

export const TRANSLATION_LANG_OPTIONS: LangOption[] = [
    { value: 'off', label: 'Выключено',   flag: '🚫' },
    { value: 'ru',  label: 'Русский',      flag: '🇷🇺' },
    { value: 'kk',  label: 'Қазақша',      flag: '🇰🇿' },
    { value: 'en',  label: 'English',      flag: '🇬🇧' },
    { value: 'zh',  label: '中文',          flag: '🇨🇳' },
    { value: 'tr',  label: 'Türkçe',       flag: '🇹🇷' },
    { value: 'ar',  label: 'العربية',      flag: '🇸🇦' },
];

/**
 * Глобально разделяемый reactive-ref для языка перевода сообщений.
 * Значение сохраняется в localStorage и доступно во всех компонентах
 * без необходимости прокидывать props.
 */
export function useTranslationLang() {
    const lang = useLocalSetting<TranslationLang>('translate_lang', 'off');

    const currentOption = () =>
        TRANSLATION_LANG_OPTIONS.find((o) => o.value === lang.value)
        ?? TRANSLATION_LANG_OPTIONS[0];

    return { lang, currentOption, options: TRANSLATION_LANG_OPTIONS };
}
