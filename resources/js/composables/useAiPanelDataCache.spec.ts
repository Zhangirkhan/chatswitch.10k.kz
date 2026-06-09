import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    fetchClientSummary,
    getCachedAutoDraft,
    getCachedClientSummary,
    isAiPanelOpenForChat,
    setCachedAutoDraft,
    setCachedClientSummary,
} from './useAiPanelDataCache';
import { updateChatAiPanelPrefs } from './useChatAiPanelPrefs';
import type { ClientSummary } from '@/Components/AiChat/aiWorkspaceTypes';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
    },
}));

const summaryFixture = (): ClientSummary => ({
    contact_id: 5,
    identity: {
        display_name: 'Test Client',
        phone: '+77001234567',
        avatar: null,
        companies: [],
    },
    crm: {
        deal: null,
        upcoming_events_count: 0,
        open_tasks_count: 0,
    },
    memory_updated_at: null,
    ai: {
        headline: 'Interested in pricing',
        sections: [],
        confidence: 'medium',
    },
    primary_chat_id: 10,
});

beforeEach(() => {
    sessionStorage.clear();
    localStorage.clear();
    vi.mocked(axios.get).mockReset();
});

describe('useAiPanelDataCache', () => {
    it('returns cached client summary without network', async () => {
        setCachedClientSummary(5, 10, summaryFixture());

        const result = await fetchClientSummary(5, 10);

        expect(result?.identity.display_name).toBe('Test Client');
        expect(axios.get).not.toHaveBeenCalled();
    });

    it('stores auto draft by chat and message id', () => {
        setCachedAutoDraft(10, 99, 'Hello draft');

        expect(getCachedAutoDraft(10, 99)).toBe('Hello draft');
        expect(getCachedAutoDraft(10, 100)).toBeNull();
    });

    it('reads ai panel open flag per chat from localStorage', () => {
        updateChatAiPanelPrefs(10, { open: true });
        updateChatAiPanelPrefs(11, { open: false });

        expect(isAiPanelOpenForChat(10)).toBe(true);
        expect(isAiPanelOpenForChat(11)).toBe(false);
    });

    it('falls back to memory after session read', () => {
        setCachedClientSummary(7, 11, summaryFixture());

        expect(getCachedClientSummary(7, 11)?.ai.headline).toBe('Interested in pricing');
    });
});
