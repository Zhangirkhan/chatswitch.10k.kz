import type { Chat, Paginated } from '@/types';

/** Chats/Show отдаёт в props только текущий чат, полный список — через chats.feed. */
export function isSidebarChatStub(p: Paginated<Chat>): boolean {
    return p.total > p.data.length;
}

export function mergeSidebarChats(current: Paginated<Chat>, incoming: Paginated<Chat>): Paginated<Chat> {
    if (!isSidebarChatStub(incoming) || current.data.length <= incoming.data.length) {
        return {
            ...incoming,
            data: [...incoming.data],
        };
    }

    const stubById = new Map(incoming.data.map((chat) => [chat.id, chat]));

    return {
        ...incoming,
        data: current.data.map((chat) => {
            const stub = stubById.get(chat.id);

            return stub ? { ...chat, ...stub } : chat;
        }),
    };
}

export function mergeSidebarChatRows(current: Chat[], incoming: Chat[], incomingMeta: Paginated<Chat>): Chat[] {
    if (!isSidebarChatStub(incomingMeta) || current.length <= incoming.length) {
        return [...incoming];
    }

    const stubById = new Map(incoming.map((chat) => [chat.id, chat]));

    return current.map((chat) => {
        const stub = stubById.get(chat.id);

        return stub ? { ...chat, ...stub } : chat;
    });
}
