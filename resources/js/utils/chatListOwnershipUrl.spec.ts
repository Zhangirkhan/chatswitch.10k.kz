import { describe, expect, it } from 'vitest';
import { appendChatListOwnership } from './chatListOwnershipUrl';

describe('appendChatListOwnership', () => {
    it('appends ownership=mine when filter is active', () => {
        expect(appendChatListOwnership('/chats', 'mine')).toBe('/chats?ownership=mine');
        expect(appendChatListOwnership('/chats?search=test', 'mine')).toBe('/chats?search=test&ownership=mine');
    });

    it('leaves url unchanged for other modes', () => {
        expect(appendChatListOwnership('/chats', 'all')).toBe('/chats');
        expect(appendChatListOwnership('/chats', undefined)).toBe('/chats');
    });
});
