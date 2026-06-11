import { onBeforeUnmount, onMounted, watch, type Ref } from 'vue';
import axios from 'axios';
import type { Message } from '@/types';
import { onSocketReconnected, subscribeSocketState } from '@/composables/useConnectionStatus';

const ACTIVE_POLL_INTERVAL_MS = 3_000;

type Options = {
    chatId: () => number;
    messages: Ref<Message[]>;
    mergeMessage: (msg: Message) => void;
    onSynced?: () => void;
};

function lastMessageCursor(messages: Message[]): { id: number; timestamp: string } | null {
    if (messages.length === 0) {
        return null;
    }

    const last = messages[messages.length - 1]!;
    const timestamp = last.message_timestamp || last.created_at;
    if (!timestamp || last.id == null) {
        return null;
    }

    return { id: Number(last.id), timestamp: String(timestamp) };
}

export function useChatThreadSync({ chatId, messages, mergeMessage, onSynced }: Options): {
    syncNewMessages: () => Promise<void>;
} {
    let pollTimer: number | null = null;
    let syncing = false;
    let unsubscribeSocket: (() => void) | null = null;
    let unsubscribeReconnect: (() => void) | null = null;
    let onVisibility: (() => void) | null = null;

    async function syncNewMessages(): Promise<void> {
        const id = chatId();
        if (!id || syncing) {
            return;
        }

        const cursor = lastMessageCursor(messages.value);
        if (cursor === null) {
            return;
        }

        syncing = true;
        try {
            const { data } = await axios.get(route('api.chats.timeline', id), {
                params: {
                    after_id: cursor.id,
                    after_timestamp: cursor.timestamp,
                    limit: 50,
                },
            });

            const incoming = Array.isArray(data.messages) ? (data.messages as Message[]) : [];
            if (incoming.length === 0) {
                return;
            }

            for (const msg of incoming) {
                mergeMessage(msg);
            }
            onSynced?.();
        } catch {
            /* offline / 419 */
        } finally {
            syncing = false;
        }
    }

    function stopPoll(): void {
        if (pollTimer !== null) {
            window.clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function startPoll(): void {
        if (pollTimer !== null) {
            return;
        }

        pollTimer = window.setInterval(() => {
            if (typeof document !== 'undefined' && document.visibilityState !== 'visible') {
                return;
            }

            void syncNewMessages();
        }, ACTIVE_POLL_INTERVAL_MS);
    }

    onMounted(() => {
        startPoll();

        unsubscribeSocket = subscribeSocketState((connected) => {
            if (!connected) {
                void syncNewMessages();
            }
        });

        unsubscribeReconnect = onSocketReconnected(() => {
            void syncNewMessages();
        });

        onVisibility = (): void => {
            if (document.visibilityState === 'visible') {
                void syncNewMessages();
            }
        };
        document.addEventListener('visibilitychange', onVisibility);
    });

    watch(
        () => chatId(),
        () => {
            stopPoll();
            void syncNewMessages();
        },
    );

    onBeforeUnmount(() => {
        stopPoll();
        unsubscribeSocket?.();
        unsubscribeReconnect?.();
        if (onVisibility) {
            document.removeEventListener('visibilitychange', onVisibility);
        }
    });

    return { syncNewMessages };
}
