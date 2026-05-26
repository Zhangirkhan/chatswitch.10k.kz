import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

export type RecaptchaConfig = {
    enabled: boolean;
    siteKey: string | null;
    version: 'v2' | 'v3';
};

declare global {
    interface Window {
        grecaptcha?: {
            ready: (cb: () => void) => void;
            execute: (siteKey: string, options: { action: string }) => Promise<string>;
            render: (
                container: HTMLElement,
                options: { sitekey: string; callback?: (token: string) => void; 'expired-callback'?: () => void },
            ) => number;
            getResponse: (widgetId?: number) => string;
            reset: (widgetId?: number) => void;
        };
    }
}

let scriptPromise: Promise<void> | null = null;

function loadRecaptchaScript(version: 'v2' | 'v3', siteKey: string): Promise<void> {
    if (scriptPromise) {
        return scriptPromise;
    }

    scriptPromise = new Promise((resolve, reject) => {
        if (window.grecaptcha) {
            resolve();

            return;
        }

        const script = document.createElement('script');
        script.src =
            version === 'v3'
                ? `https://www.google.com/recaptcha/api.js?render=${encodeURIComponent(siteKey)}`
                : 'https://www.google.com/recaptcha/api.js';
        script.async = true;
        script.defer = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Не удалось загрузить reCAPTCHA'));
        document.head.appendChild(script);
    });

    return scriptPromise;
}

export function useRecaptcha() {
    const page = usePage();
    const config = computed(
        () => (page.props as { recaptcha?: RecaptchaConfig }).recaptcha ?? { enabled: false, siteKey: null, version: 'v3' },
    );

    const enabled = computed(() => config.value.enabled && !!config.value.siteKey);

    async function getToken(action: string): Promise<string> {
        if (!enabled.value || !config.value.siteKey) {
            return '';
        }

        const version = config.value.version === 'v2' ? 'v2' : 'v3';
        await loadRecaptchaScript(version, config.value.siteKey);

        if (!window.grecaptcha) {
            throw new Error('reCAPTCHA недоступна');
        }

        if (version === 'v3') {
            return new Promise((resolve, reject) => {
                window.grecaptcha!.ready(() => {
                    window
                        .grecaptcha!.execute(config.value.siteKey!, { action })
                        .then(resolve)
                        .catch(reject);
                });
            });
        }

        return '';
    }

    async function renderV2Widget(container: HTMLElement, onToken: (token: string) => void): Promise<number> {
        if (!config.value.siteKey) {
            throw new Error('reCAPTCHA site key missing');
        }

        await loadRecaptchaScript('v2', config.value.siteKey);

        return new Promise((resolve, reject) => {
            window.grecaptcha!.ready(() => {
                try {
                    const id = window.grecaptcha!.render(container, {
                        sitekey: config.value.siteKey!,
                        callback: onToken,
                        'expired-callback': () => onToken(''),
                    });
                    resolve(id);
                } catch (e) {
                    reject(e);
                }
            });
        });
    }

    function getV2Response(widgetId: number): string {
        return window.grecaptcha?.getResponse(widgetId) ?? '';
    }

    function resetV2(widgetId: number): void {
        window.grecaptcha?.reset(widgetId);
    }

    return {
        config,
        enabled,
        getToken,
        renderV2Widget,
        getV2Response,
        resetV2,
    };
}
