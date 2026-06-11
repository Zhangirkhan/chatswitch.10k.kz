import type { Message } from '@/types';

export const OPEN_CHAT_MESSAGE_EVENT = 'accel:open-chat-message';

export function dispatchOpenChatMessage(message: Message): void {
    if (typeof window === 'undefined' || !message?.id) {
        return;
    }

    window.dispatchEvent(new CustomEvent(OPEN_CHAT_MESSAGE_EVENT, {
        detail: { message },
    }));
}

export function onOpenChatMessage(
    chatId: () => number,
    handler: (message: Message) => void,
): () => void {
    const listener = (event: Event): void => {
        const detail = (event as CustomEvent<{ message?: Message }>).detail;
        const message = detail?.message;
        if (!message?.id || message.chat_id !== chatId()) {
            return;
        }
        handler(message);
    };

    window.addEventListener(OPEN_CHAT_MESSAGE_EVENT, listener);

    return () => window.removeEventListener(OPEN_CHAT_MESSAGE_EVENT, listener);
}
