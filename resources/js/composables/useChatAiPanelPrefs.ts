export type ChatAiPanelMode = 'overview' | 'chat';
export type ChatAiPanelTab = 'assistant' | 'ai-status' | 'draft';

export type ChatAiPanelPrefs = {
    open: boolean;
    mode: ChatAiPanelMode;
    tab: ChatAiPanelTab;
};

const STORAGE_KEY = 'accel.settings.chats.aiPanelByChat';

const DEFAULT_PREFS: ChatAiPanelPrefs = {
    open: false,
    mode: 'overview',
    tab: 'assistant',
};

function readAll(): Record<string, ChatAiPanelPrefs> {
    if (typeof window === 'undefined') {
        return {};
    }

    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        if (!raw) {
            return {};
        }

        const parsed = JSON.parse(raw) as Record<string, unknown>;
        if (!parsed || typeof parsed !== 'object') {
            return {};
        }

        return Object.fromEntries(
            Object.entries(parsed).map(([chatId, value]) => [chatId, normalizePrefs(value)]),
        );
    } catch {
        return {};
    }
}

function writeAll(map: Record<string, ChatAiPanelPrefs>): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(map));
    } catch {
        /* quota or privacy mode */
    }
}

function normalizeMode(value: unknown): ChatAiPanelMode {
    return value === 'chat' ? 'chat' : 'overview';
}

function normalizeTab(value: unknown): ChatAiPanelTab {
    if (value === 'ai-status' || value === 'draft') {
        return value;
    }

    return 'assistant';
}

function normalizePrefs(value: unknown): ChatAiPanelPrefs {
    if (!value || typeof value !== 'object') {
        return { ...DEFAULT_PREFS };
    }

    const prefs = value as Partial<ChatAiPanelPrefs>;

    return {
        open: prefs.open === true,
        mode: normalizeMode(prefs.mode),
        tab: normalizeTab(prefs.tab),
    };
}

export function getChatAiPanelPrefs(chatId: number): ChatAiPanelPrefs {
    const map = readAll();
    return map[String(chatId)] ?? { ...DEFAULT_PREFS };
}

export function updateChatAiPanelPrefs(
    chatId: number,
    patch: Partial<ChatAiPanelPrefs>,
): ChatAiPanelPrefs {
    const map = readAll();
    const key = String(chatId);
    const next = { ...getChatAiPanelPrefs(chatId), ...patch };
    map[key] = next;
    writeAll(map);

    return next;
}

export function isAiPanelOpenForChat(chatId: number): boolean {
    return getChatAiPanelPrefs(chatId).open;
}
