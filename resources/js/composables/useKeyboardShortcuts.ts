import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

export type ShortcutAction =
    | 'focus-search'
    | 'new-chat'
    | 'new-group'
    | 'next-chat'
    | 'prev-chat'
    | 'close-chat'
    | 'toggle-chat-search'
    | 'toggle-contact-info'
    | 'toggle-pin'
    | 'toggle-mute'
    | 'toggle-unread'
    | 'archive-chat'
    | 'toggle-emoji'
    | 'toggle-dictation';

const SHORTCUT_EVENT = 'accel:shortcut';

export function dispatchShortcut(action: ShortcutAction): void {
    window.dispatchEvent(new CustomEvent(SHORTCUT_EVENT, { detail: action }));
}

export function onShortcut(action: ShortcutAction, handler: () => void): () => void {
    const listener = (event: Event) => {
        if ((event as CustomEvent).detail === action) handler();
    };
    window.addEventListener(SHORTCUT_EVENT, listener);
    return () => window.removeEventListener(SHORTCUT_EVENT, listener);
}

/**
 * Vue-flavored subscription helper. Auto-cleans on unmount.
 */
export function useShortcut(action: ShortcutAction, handler: () => void): void {
    let off: (() => void) | null = null;
    onMounted(() => {
        off = onShortcut(action, handler);
    });
    onBeforeUnmount(() => {
        off?.();
    });
}

function focusTarget(name: string): void {
    const el = document.querySelector<HTMLElement>(`[data-shortcut-target="${name}"]`);
    if (!el) return;
    el.focus();
    if (el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement) {
        el.select?.();
    }
}

function isTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) return false;
    const tag = target.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
    if (target.isContentEditable) return true;
    return false;
}

function normalizeKey(event: KeyboardEvent): string {
    const key = event.key.length === 1 ? event.key.toLowerCase() : event.key;
    return key;
}

let installed = false;

export function installKeyboardShortcuts(): void {
    if (installed || typeof window === 'undefined') return;
    installed = true;

    window.addEventListener('keydown', (event) => {
        const target = event.target;
        const typing = isTypingTarget(target);
        const mod = event.ctrlKey || event.metaKey;
        const key = normalizeKey(event);
        const path = window.location.pathname;

        // Escape — always works when not typing. Closes chat if one is open.
        if (key === 'Escape' && !typing) {
            if (/^\/chats\/\d+/.test(path)) {
                event.preventDefault();
                router.visit(route('chats.index'));
            }
            return;
        }

        if (!mod && !event.altKey) return;

        // --- Modifier combos ---------------------------------------------------

        // Ctrl+/  or Ctrl+K  —  focus chat list search
        if (mod && !event.shiftKey && !event.altKey && (key === '/' || key === 'k')) {
            event.preventDefault();
            if (!path.startsWith('/chats')) {
                router.visit(route('chats.index'), {
                    onSuccess: () => setTimeout(() => focusTarget('chat-search'), 100),
                });
            } else {
                focusTarget('chat-search');
            }
            return;
        }

        // Ctrl+,  —  open profile / settings
        if (mod && !event.shiftKey && !event.altKey && key === ',') {
            event.preventDefault();
            router.visit(route('profile.edit'));
            return;
        }

        // Ctrl+Shift+C  —  new chat (Ctrl+N is reserved by browsers)
        if (mod && event.shiftKey && !event.altKey && key === 'C') {
            event.preventDefault();
            if (!path.startsWith('/chats')) {
                router.visit(route('chats.index'), {
                    onSuccess: () => setTimeout(() => dispatchShortcut('new-chat'), 100),
                });
            } else {
                dispatchShortcut('new-chat');
            }
            return;
        }

        // Ctrl+Shift+G  —  new group
        if (mod && event.shiftKey && !event.altKey && key === 'G') {
            event.preventDefault();
            dispatchShortcut('new-group');
            return;
        }

        // Alt+↓ / Alt+↑  —  next/prev chat (Ctrl+Tab is reserved by browsers)
        if (event.altKey && !mod && !event.shiftKey && key === 'ArrowDown') {
            event.preventDefault();
            dispatchShortcut('next-chat');
            return;
        }
        if (event.altKey && !mod && !event.shiftKey && key === 'ArrowUp') {
            event.preventDefault();
            dispatchShortcut('prev-chat');
            return;
        }

        // Ctrl+Shift+F  —  search inside current chat
        if (mod && event.shiftKey && !event.altKey && key === 'F') {
            event.preventDefault();
            dispatchShortcut('toggle-chat-search');
            return;
        }

        // Ctrl+I  —  contact info panel
        if (mod && !event.shiftKey && !event.altKey && key === 'i') {
            event.preventDefault();
            dispatchShortcut('toggle-contact-info');
            return;
        }

        // Ctrl+E  —  emoji picker
        if (mod && !event.shiftKey && !event.altKey && key === 'e') {
            event.preventDefault();
            dispatchShortcut('toggle-emoji');
            return;
        }

        // Ctrl+Shift+P  —  pin/unpin chat
        if (mod && event.shiftKey && !event.altKey && key === 'P') {
            event.preventDefault();
            dispatchShortcut('toggle-pin');
            return;
        }

        // Ctrl+Shift+M  —  mute/unmute chat
        if (mod && event.shiftKey && !event.altKey && key === 'M') {
            event.preventDefault();
            dispatchShortcut('toggle-mute');
            return;
        }

        // Ctrl+Shift+E  —  archive chat
        if (mod && event.shiftKey && !event.altKey && key === 'E') {
            event.preventDefault();
            dispatchShortcut('archive-chat');
            return;
        }

        // Ctrl+Shift+D  —  speech dictation (when AI input focused)
        if (mod && event.shiftKey && !event.altKey && key === 'D') {
            event.preventDefault();
            dispatchShortcut('toggle-dictation');
            return;
        }

        // Ctrl+Shift+U  —  toggle unread
        if (mod && event.shiftKey && !event.altKey && key === 'U') {
            event.preventDefault();
            dispatchShortcut('toggle-unread');
            return;
        }
    });
}
