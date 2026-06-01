import type { ClientSummary } from '@/Components/AiChat/aiWorkspaceTypes';
import type { ClientProfile } from '@/Components/Clients/clientProfileTypes';
import axios from 'axios';
import { ref } from 'vue';

const profileCache = new Map<string, ClientProfile>();
const summaryCache = new Map<string, ClientSummary>();

export function contactProfileCacheKey(contactId: number, chatId?: number | null): string {
    return `${contactId}:${chatId ?? 'none'}`;
}

export function setContactProfileCache(contactId: number, chatId: number | null | undefined, value: ClientProfile): void {
    profileCache.set(contactProfileCacheKey(contactId, chatId), value);
}

export function invalidateContactProfileCache(contactId: number): void {
    for (const key of [...profileCache.keys()]) {
        if (key.startsWith(`${contactId}:`)) {
            profileCache.delete(key);
            summaryCache.delete(key);
        }
    }
}

export function useContactProfile() {
    const profile = ref<ClientProfile | null>(null);
    const summary = ref<ClientSummary | null>(null);
    const profileLoading = ref(false);
    const summaryLoading = ref(false);
    const profileError = ref<string | null>(null);

    async function loadProfile(
        contactId: number,
        chatId?: number | null,
        options?: { withAi?: boolean; force?: boolean },
    ): Promise<ClientProfile | null> {
        const cacheKey = contactProfileCacheKey(contactId, chatId);
        if (!options?.force) {
            const cached = profileCache.get(cacheKey);
            if (cached && !options?.withAi) {
                profile.value = cached;
                return cached;
            }
        }

        profileLoading.value = true;
        profileError.value = null;
        try {
            const params: Record<string, number | boolean> = {};
            if (chatId) {
                params.chat_id = chatId;
            }
            if (options?.withAi) {
                params.with_ai = 1;
            }
            const { data } = await axios.get(route('clients.profile', contactId), { params });
            const nextProfile = data.profile as ClientProfile;
            profile.value = nextProfile;
            profileCache.set(cacheKey, nextProfile);
            return nextProfile;
        } catch (e: unknown) {
            profile.value = null;
            const err = e as { response?: { data?: { message?: string } } };
            profileError.value = err.response?.data?.message || 'Не удалось загрузить профиль';
            return null;
        } finally {
            profileLoading.value = false;
        }
    }

    async function enrichProfileInBackground(contactId: number, chatId?: number | null): Promise<void> {
        const cacheKey = contactProfileCacheKey(contactId, chatId);
        try {
            const params: Record<string, number | boolean> = { with_ai: 1 };
            if (chatId) {
                params.chat_id = chatId;
            }
            const { data } = await axios.get(route('clients.profile', contactId), { params });
            const enriched = data.profile as ClientProfile;
            profile.value = enriched;
            profileCache.set(cacheKey, enriched);
        } catch {
            // ignore
        }
    }

    async function loadSummary(contactId: number, chatId?: number | null, force = false): Promise<ClientSummary | null> {
        const cacheKey = contactProfileCacheKey(contactId, chatId);
        if (!force) {
            const cached = summaryCache.get(cacheKey);
            if (cached) {
                summary.value = cached;
                return cached;
            }
        }

        summaryLoading.value = true;
        try {
            const params = chatId ? { chat_id: chatId } : {};
            const { data } = await axios.get(route('clients.summary', contactId), { params });
            const nextSummary = data.client_summary as ClientSummary;
            summary.value = nextSummary;
            summaryCache.set(cacheKey, nextSummary);
            return nextSummary;
        } catch {
            summary.value = null;
            return null;
        } finally {
            summaryLoading.value = false;
        }
    }

    function primeFromCache(contactId: number, chatId?: number | null): void {
        const cacheKey = contactProfileCacheKey(contactId, chatId);
        profile.value = profileCache.get(cacheKey) ?? null;
        summary.value = summaryCache.get(cacheKey) ?? null;
    }

    return {
        profile,
        summary,
        profileLoading,
        summaryLoading,
        profileError,
        loadProfile,
        enrichProfileInBackground,
        loadSummary,
        primeFromCache,
        invalidateContactProfileCache,
    };
}
