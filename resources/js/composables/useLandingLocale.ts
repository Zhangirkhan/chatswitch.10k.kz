import { useI18n } from '@/composables/useI18n';
import { useLocalSetting } from '@/composables/useLocalSetting';
import type { AppLocale } from '@/i18n/types';
import { isAppLocale } from '@/i18n/types';
import { computed, watch } from 'vue';

const LANDING_LOCALE_KEY = 'landing_locale';
const COOKIE_NAME = 'landing_locale';
const COOKIE_MAX_AGE_SECONDS = 60 * 60 * 24 * 365;

const landingLocale = useLocalSetting<AppLocale>(LANDING_LOCALE_KEY, 'kk');
let initialized = false;

function persistLandingCookie(value: AppLocale): void {
    if (typeof document === 'undefined') {
        return;
    }

    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${COOKIE_NAME}=${encodeURIComponent(value)}; Path=/; Max-Age=${COOKIE_MAX_AGE_SECONDS}; SameSite=Lax${secure}`;
}

function localeFromUrl(): AppLocale | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const raw = new URLSearchParams(window.location.search).get('lang');
    if (raw !== null && isAppLocale(raw)) {
        return raw;
    }

    return null;
}

export function initLandingLocale(serverLocale?: string): void {
    if (initialized || typeof window === 'undefined') {
        return;
    }

    initialized = true;

    const { setLocale } = useI18n();
    const fromUrl = localeFromUrl();

    if (fromUrl !== null) {
        landingLocale.value = fromUrl;
    } else if (serverLocale !== undefined && isAppLocale(serverLocale)) {
        const stored = window.localStorage.getItem(`accel.settings.${LANDING_LOCALE_KEY}`);
        if (stored === null) {
            landingLocale.value = serverLocale;
        }
    }

    setLocale(landingLocale.value);
    persistLandingCookie(landingLocale.value);
}

export function useLandingLocale() {
    const { t, locales, supportedLocales, setLocale: setUiLocale } = useI18n();

    watch(landingLocale, (value) => {
        if (value !== undefined) {
            setUiLocale(value);
            persistLandingCookie(value);
        }
    });

    const locale = computed({
        get: () => landingLocale.value,
        set: (value: AppLocale) => {
            landingLocale.value = value;
            setUiLocale(value);
            persistLandingCookie(value);
        },
    });

    function setLocale(value: AppLocale): void {
        locale.value = value;
    }

    const currentLocale = computed(() =>
        locales.find((option) => option.value === locale.value) ?? locales.find((option) => option.value === 'kk') ?? locales[0],
    );

    return {
        locale,
        locales,
        supportedLocales,
        currentLocale,
        t,
        setLocale,
    };
}
