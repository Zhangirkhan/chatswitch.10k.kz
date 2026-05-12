import { ref } from 'vue';
import { applyUnreadChatsFavicon } from './useUnreadFavicon';

/**
 * Модульный синглтон: живой счётчик непрочитанных чатов.
 *
 * Инициализируется из `page.props.unreadChatsCount` в `AuthenticatedLayout`,
 * инкрементируется/декрементируется из `ChatSidebar` по WebSocket-событиям.
 * Читается в `AuthenticatedLayout` для бейджа на иконке чатов в рейле.
 */
const _count = ref<number>(0);
let _initialized = false;

export function useLiveUnreadCount() {
    function init(n: number): void {
        _count.value = Math.max(0, Number(n) || 0);
        _initialized = true;
        applyUnreadChatsFavicon(_count.value);
    }

    function set(n: number): void {
        _count.value = Math.max(0, Number(n) || 0);
        applyUnreadChatsFavicon(_count.value);
    }

    function increment(delta = 1): void {
        _count.value = Math.max(0, _count.value + delta);
        applyUnreadChatsFavicon(_count.value);
    }

    function decrement(delta = 1): void {
        _count.value = Math.max(0, _count.value - delta);
        applyUnreadChatsFavicon(_count.value);
    }

    return {
        count: _count,
        initialized: () => _initialized,
        init,
        set,
        increment,
        decrement,
    };
}
