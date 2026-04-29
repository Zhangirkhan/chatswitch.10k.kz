import { scheduleUnreadChatsPropsReload } from '@/composables/useUnreadFavicon';
import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';

const SETTINGS_KEY = 'chatswitch.settings.notifications.enabled';

function readNotificationsEnabled(): boolean {
    if (typeof window === 'undefined') return false;
    try {
        const raw = window.localStorage.getItem(SETTINGS_KEY);
        if (raw === null) return false;
        return JSON.parse(raw) === true;
    } catch {
        return false;
    }
}

/**
 * Не показывать баннер, пока пользователь явно смотрит на эту вкладку (фокус ввода в документе).
 * Иначе: другая вкладка, свёрнутое окно, другое приложение в фокусе — можно показать.
 */
function shouldShowWhenNotInForeground(): boolean {
    if (typeof document === 'undefined') return false;
    if (document.visibilityState === 'hidden' || document.hidden) {
        return true;
    }
    if (typeof document.hasFocus === 'function' && !document.hasFocus()) {
        return true;
    }
    return false;
}

function defaultIconUrl(): string {
    if (typeof window === 'undefined') return '';
    return new URL('/favicon.ico', window.location.origin).href;
}

type DesktopPayload = {
    title: string;
    body: string;
    icon: string | null;
    chat_id: number;
    is_muted: boolean;
};

type MessageReceivedPayload = {
    message?: { id?: number; chat_id?: number; sent_by_user_id?: number | null };
    desktop?: DesktopPayload | null;
};

type ChatsNotifyPayload = {
    kind: string;
    chat_id: number;
    title: string;
    body: string;
    icon?: string | null;
    is_muted: boolean;
};

/**
 * Системные уведомления по Echo на `chats.list.{userId}`.
 * Нужны: включённый пункт в профиле, разрешение браузера, вкладка не на переднем плане.
 * Несколько сообщений в одном чату схлопываются за счёт одного `tag` на чат.
 */
export function useChatsListDesktopNotifications(
    getUserId: () => number | null | undefined,
    getCurrentUserId: () => number | null | undefined,
): void {
    let listChannel: {
        listen: (ev: string, cb: (e: unknown) => void) => void;
        stopListening?: (ev: string, cb: (e: unknown) => void) => void;
    } | null = null;
    let echoWaitInterval: ReturnType<typeof setInterval> | number | null = null;
    let echoWaitTimeout: ReturnType<typeof setTimeout> | number | null = null;

    const onMessageReceived = (raw: unknown): void => {
        const e = raw as MessageReceivedPayload;
        if (e.message?.id) {
            scheduleUnreadChatsPropsReload();
        }
        const d = e.desktop;
        if (!d) return;
        if (d.is_muted) return;
        const currentUserId = getCurrentUserId() ?? null;
        const sentBy = e.message?.sent_by_user_id;
        if (currentUserId !== null && sentBy === currentUserId) return;

        fireNotification({
            chat_id: d.chat_id,
            title: d.title,
            body: d.body,
            icon: d.icon,
            is_muted: d.is_muted,
            tag: `chatswitch-msg-${d.chat_id}`,
            renotify: true,
        });
    };

    const onChatsNotify = (raw: unknown): void => {
        const e = raw as ChatsNotifyPayload;
        if (!e?.chat_id) return;
        scheduleUnreadChatsPropsReload();
        const tag =
            e.kind === 'call_incoming'
                ? `chatswitch-call-${e.chat_id}`
                : `chatswitch-assign-${e.chat_id}`;
        fireNotification({
            chat_id: e.chat_id,
            title: e.title,
            body: e.body,
            icon: (e.icon as string | null | undefined) ?? null,
            is_muted: e.is_muted,
            tag,
            renotify: true,
        });
    };

    function fireNotification(opts: {
        chat_id: number;
        title: string;
        body: string;
        icon: string | null;
        is_muted: boolean;
        tag: string;
        renotify?: boolean;
    }): void {
        if (!readNotificationsEnabled()) return;
        if (typeof Notification === 'undefined') return;
        if (Notification.permission !== 'granted') return;
        if (!shouldShowWhenNotInForeground()) return;
        if (opts.is_muted) return;

        try {
            const notificationOptions: NotificationOptions & { renotify?: boolean } = {
                body: opts.body.slice(0, 500),
                icon: opts.icon || defaultIconUrl(),
                tag: opts.tag,
                renotify: opts.renotify ?? false,
            };
            const n = new Notification(opts.title, notificationOptions);
            n.onclick = () => {
                window.focus();
                n.close();
                router.visit(route('chats.show', opts.chat_id));
            };
        } catch (err) {
            console.warn('Desktop notification failed', err);
        }
    }

    function subscribeEcho(): void {
        const Echo = (window as unknown as { Echo?: { private: (ch: string) => unknown } }).Echo;
        const uid = getUserId();
        if (!Echo || !uid) return;

        try {
            try {
                listChannel?.stopListening?.('.message.received', onMessageReceived);
                listChannel?.stopListening?.('.chats.notify', onChatsNotify);
            } catch {
                /* ignore */
            }

            const ch = Echo.private(`chats.list.${uid}`) as {
                listen: (ev: string, cb: (e: unknown) => void) => void;
                stopListening?: (ev: string, cb: (e: unknown) => void) => void;
            };
            listChannel = ch;
            ch.listen('.message.received', onMessageReceived);
            ch.listen('.chats.notify', onChatsNotify);
        } catch {
            listChannel = null;
        }
    }

    onMounted(() => {
        subscribeEcho();
        if (!(window as unknown as { Echo?: unknown }).Echo) {
            echoWaitInterval = window.setInterval(() => {
                if ((window as unknown as { Echo?: unknown }).Echo) {
                    if (echoWaitInterval !== null) {
                        window.clearInterval(echoWaitInterval);
                        echoWaitInterval = null;
                    }
                    if (echoWaitTimeout !== null) {
                        window.clearTimeout(echoWaitTimeout);
                        echoWaitTimeout = null;
                    }
                    subscribeEcho();
                }
            }, 300);
            echoWaitTimeout = window.setTimeout(() => {
                if (echoWaitInterval !== null) {
                    window.clearInterval(echoWaitInterval);
                    echoWaitInterval = null;
                }
                echoWaitTimeout = null;
            }, 15_000);
        }
    });

    onUnmounted(() => {
        if (echoWaitInterval !== null) {
            window.clearInterval(echoWaitInterval);
            echoWaitInterval = null;
        }
        if (echoWaitTimeout !== null) {
            window.clearTimeout(echoWaitTimeout);
            echoWaitTimeout = null;
        }
        try {
            listChannel?.stopListening?.('.message.received', onMessageReceived);
            listChannel?.stopListening?.('.chats.notify', onChatsNotify);
        } catch {
            /* ignore */
        }
        listChannel = null;
    });
}
