import axios from 'axios';
import type { ClientSummary } from '@/Components/AiChat/aiWorkspaceTypes';

const SUMMARY_TTL_MS = 15 * 60 * 1000;
const DRAFT_TTL_MS = 30 * 60 * 1000;
const SUMMARY_STORAGE_PREFIX = 'accel.aiPanel.summary.';
const DRAFT_STORAGE_PREFIX = 'accel.aiPanel.draft.';

type CacheEntry<T> = {
    fetchedAt: number;
    value: T;
};

const summaryMemory = new Map<string, CacheEntry<ClientSummary>>();
const draftMemory = new Map<string, CacheEntry<string>>();
const summaryInflight = new Map<string, Promise<ClientSummary | null>>();

function summaryKey(contactId: number, chatId: number): string {
    return `${contactId}:${chatId}`;
}

function draftKey(chatId: number, messageId: number): string {
    return `${chatId}:${messageId}`;
}

function isFresh(fetchedAt: number, ttlMs: number): boolean {
    return Date.now() - fetchedAt < ttlMs;
}

function readSession<T>(key: string): CacheEntry<T> | null {
    if (typeof window === 'undefined') {
        return null;
    }
    try {
        const raw = window.sessionStorage.getItem(key);
        if (!raw) {
            return null;
        }
        const parsed = JSON.parse(raw) as CacheEntry<T>;
        if (typeof parsed?.fetchedAt !== 'number') {
            return null;
        }

        return parsed;
    } catch {
        return null;
    }
}

function writeSession<T>(key: string, entry: CacheEntry<T>): void {
    if (typeof window === 'undefined') {
        return;
    }
    try {
        window.sessionStorage.setItem(key, JSON.stringify(entry));
    } catch {
        /* quota */
    }
}

export { isAiPanelOpenForChat } from '@/composables/useChatAiPanelPrefs';

/** Минимальный набор props при переключении чата с открытым AI-ассистентом. */
export const CHAT_SHOW_PARTIAL_PROPS = [
    'chat',
    'messages',
    'aiStatus',
    'sidebarInsights',
    'pendingOrchestratorApproval',
    'pendingFollowUpProposal',
    'contactChats',
    'funnelCatalog',
    'aiReadinessBanner',
] as const;

export function getCachedClientSummary(contactId: number, chatId: number): ClientSummary | null {
    const key = summaryKey(contactId, chatId);
    const mem = summaryMemory.get(key);
    if (mem && isFresh(mem.fetchedAt, SUMMARY_TTL_MS)) {
        return mem.value;
    }

    const stored = readSession<ClientSummary>(SUMMARY_STORAGE_PREFIX + key);
    if (stored && isFresh(stored.fetchedAt, SUMMARY_TTL_MS)) {
        summaryMemory.set(key, stored);

        return stored.value;
    }

    return null;
}

export function setCachedClientSummary(contactId: number, chatId: number, summary: ClientSummary): void {
    const key = summaryKey(contactId, chatId);
    const entry: CacheEntry<ClientSummary> = { fetchedAt: Date.now(), value: summary };
    summaryMemory.set(key, entry);
    writeSession(SUMMARY_STORAGE_PREFIX + key, entry);
}

export async function refreshClientSummary(contactId: number, chatId: number): Promise<ClientSummary | null> {
    const key = summaryKey(contactId, chatId);

    try {
        const res = await axios.get(route('ai-chat.client-summary', { contact: contactId }), {
            params: { chat_id: chatId },
        });
        const summary = (res.data?.client_summary as ClientSummary | null) ?? null;
        if (summary) {
            setCachedClientSummary(contactId, chatId, summary);
        }

        return summary;
    } finally {
        summaryInflight.delete(key);
    }
}

export async function fetchClientSummary(contactId: number, chatId: number): Promise<ClientSummary | null> {
    const cached = getCachedClientSummary(contactId, chatId);
    if (cached) {
        return cached;
    }

    const key = summaryKey(contactId, chatId);
    const inflight = summaryInflight.get(key);
    if (inflight) {
        return inflight;
    }

    const request = axios
        .get(route('ai-chat.client-summary', { contact: contactId }), {
            params: { chat_id: chatId },
        })
        .then((res) => {
            const summary = (res.data?.client_summary as ClientSummary | null) ?? null;
            if (summary) {
                setCachedClientSummary(contactId, chatId, summary);
            }

            return summary;
        })
        .finally(() => {
            summaryInflight.delete(key);
        });

    summaryInflight.set(key, request);

    return request;
}

export function prefetchClientSummary(contactId: number | null | undefined, chatId: number): void {
    if (!contactId) {
        return;
    }
    if (getCachedClientSummary(contactId, chatId)) {
        return;
    }
    void fetchClientSummary(contactId, chatId);
}

export function getCachedAutoDraft(chatId: number, messageId: number): string | null {
    const key = draftKey(chatId, messageId);
    const mem = draftMemory.get(key);
    if (mem && isFresh(mem.fetchedAt, DRAFT_TTL_MS)) {
        return mem.value;
    }

    const stored = readSession<string>(DRAFT_STORAGE_PREFIX + key);
    if (stored && isFresh(stored.fetchedAt, DRAFT_TTL_MS)) {
        draftMemory.set(key, stored);

        return stored.value;
    }

    return null;
}

export function setCachedAutoDraft(chatId: number, messageId: number, draft: string): void {
    const key = draftKey(chatId, messageId);
    const entry: CacheEntry<string> = { fetchedAt: Date.now(), value: draft };
    draftMemory.set(key, entry);
    writeSession(DRAFT_STORAGE_PREFIX + key, entry);
}
