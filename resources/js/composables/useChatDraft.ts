import { computed, ref, watch } from 'vue';

const STORAGE_KEY_PREFIX = 'chatswitch.draft.';

export function useChatDraft(chatId: () => number | undefined) {
    const text = ref<string>('');
    const quotedMessageId = ref<string | null>(null);

    const storageKey = computed(() => {
        const id = chatId();
        return id ? `${STORAGE_KEY_PREFIX}${id}` : null;
    });

    function loadDraft() {
        const key = storageKey.value;
        if (!key) {
            text.value = '';
            quotedMessageId.value = null;
            return;
        }

        try {
            const raw = localStorage.getItem(key);
            if (raw) {
                const parsed = JSON.parse(raw);
                text.value = typeof parsed.text === 'string' ? parsed.text : '';
                quotedMessageId.value = typeof parsed.quoted === 'string' ? parsed.quoted : null;
            } else {
                text.value = '';
                quotedMessageId.value = null;
            }
        } catch (_e) {
            text.value = '';
            quotedMessageId.value = null;
        }
    }

    function persist() {
        const key = storageKey.value;
        if (!key) return;
        try {
            if (!text.value && !quotedMessageId.value) {
                localStorage.removeItem(key);
                return;
            }
            localStorage.setItem(key, JSON.stringify({ text: text.value, quoted: quotedMessageId.value }));
        } catch (_e) {
            // ignore quota/permission errors
        }
    }

    function reset() {
        text.value = '';
        quotedMessageId.value = null;
        const key = storageKey.value;
        if (key) localStorage.removeItem(key);
    }

    watch(() => chatId(), () => loadDraft(), { immediate: true });
    watch([text, quotedMessageId], persist);

    return { text, quotedMessageId, reset };
}
