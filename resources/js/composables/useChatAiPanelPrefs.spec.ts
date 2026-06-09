import { beforeEach, describe, expect, it } from 'vitest';
import {
    getChatAiPanelPrefs,
    isAiPanelOpenForChat,
    updateChatAiPanelPrefs,
} from './useChatAiPanelPrefs';

beforeEach(() => {
    localStorage.clear();
});

describe('useChatAiPanelPrefs', () => {
    it('returns defaults for unknown chat', () => {
        expect(getChatAiPanelPrefs(99)).toEqual({
            open: false,
            mode: 'overview',
            tab: 'assistant',
        });
        expect(isAiPanelOpenForChat(99)).toBe(false);
    });

    it('stores open state, mode and tab per chat independently', () => {
        updateChatAiPanelPrefs(1, { open: true, mode: 'chat', tab: 'draft' });
        updateChatAiPanelPrefs(2, { open: false, mode: 'overview', tab: 'ai-status' });

        expect(getChatAiPanelPrefs(1)).toEqual({
            open: true,
            mode: 'chat',
            tab: 'draft',
        });
        expect(getChatAiPanelPrefs(2)).toEqual({
            open: false,
            mode: 'overview',
            tab: 'ai-status',
        });
    });

    it('merges partial updates without overwriting other chats', () => {
        updateChatAiPanelPrefs(5, { open: true, mode: 'chat' });
        updateChatAiPanelPrefs(5, { tab: 'ai-status' });

        expect(getChatAiPanelPrefs(5)).toEqual({
            open: true,
            mode: 'chat',
            tab: 'ai-status',
        });
    });
});
