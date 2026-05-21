import axios from 'axios';
import { notifyNetworkReachable, notifyNetworkUnreachable } from '@/composables/useConnectionStatus';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Не подставлять X-CSRF-TOKEN из <meta> один раз при загрузке: при долгой вкладке meta устаревает,
 * а Laravel в VerifyCsrfToken смотрит на X-CSRF-TOKEN раньше, чем на X-XSRF-TOKEN из cookie.
 * Cookie XSRF-TOKEN обновляется с каждым ответом; axios читает её на каждый запрос.
 */
window.axios.defaults.xsrfCookieName = 'XSRF-TOKEN';
window.axios.defaults.xsrfHeaderName = 'X-XSRF-TOKEN';

window.axios.interceptors.response.use(
    (response) => {
        notifyNetworkReachable();
        return response;
    },
    (error: { response?: { status?: number }; code?: string }) => {
        if (!error.response && error.code !== 'ERR_CANCELED') {
            notifyNetworkUnreachable();
        }
        if (error.response?.status === 419) {
            window.location.reload();
        }
        return Promise.reject(error);
    },
);

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

(window as any).Pusher = Pusher;

const reverbKey = import.meta.env.VITE_REVERB_APP_KEY;

if (reverbKey) {
    (window as any).Echo = new Echo({
        broadcaster: 'reverb',
        key: reverbKey,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
