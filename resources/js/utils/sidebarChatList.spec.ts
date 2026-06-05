import { describe, expect, it } from 'vitest';
import type { Chat, Paginated } from '@/types';
import { isSidebarChatStub, mergeSidebarChats } from './sidebarChatList';

const chat = (id: number): Chat =>
    ({
        id,
        unread_count: 0,
    }) as Chat;

const paginated = (data: Chat[], total: number): Paginated<Chat> => ({
    data,
    current_page: 1,
    last_page: Math.max(1, total),
    per_page: 50,
    total,
});

describe('sidebarChatList', () => {
    it('detects show-page stub payload', () => {
        expect(isSidebarChatStub(paginated([chat(1)], 120))).toBe(true);
        expect(isSidebarChatStub(paginated([chat(1), chat(2)], 2))).toBe(false);
    });

    it('keeps loaded list when show returns only selected chat', () => {
        const current = paginated([chat(1), chat(2), chat(3)], 120);
        const incoming = paginated([chat(2)], 120);

        const merged = mergeSidebarChats(current, incoming);

        expect(merged.data).toHaveLength(3);
        expect(merged.data.map((row) => row.id)).toEqual([1, 2, 3]);
    });
});
