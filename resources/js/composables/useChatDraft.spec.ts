import { describe, it, expect, beforeEach } from 'vitest';
import { nextTick, ref } from 'vue';
import { useChatDraft } from './useChatDraft';

beforeEach(() => {
    localStorage.clear();
});

describe('useChatDraft', () => {
    it('starts empty when storage is empty', async () => {
        const chatIdRef = ref(1);
        const { text, quotedMessageId } = useChatDraft(() => chatIdRef.value);

        await nextTick();

        expect(text.value).toBe('');
        expect(quotedMessageId.value).toBeNull();
    });

    it('persists text to localStorage', async () => {
        const chatIdRef = ref(5);
        const { text } = useChatDraft(() => chatIdRef.value);

        await nextTick();
        text.value = 'hello';
        await nextTick();

        expect(localStorage.getItem('chatswitch.draft.5')).toBe(
            JSON.stringify({ text: 'hello', quoted: null }),
        );
    });

    it('loads draft from storage on mount', async () => {
        localStorage.setItem('chatswitch.draft.10', JSON.stringify({ text: 'saved', quoted: 'msg-1' }));

        const chatIdRef = ref(10);
        const { text, quotedMessageId } = useChatDraft(() => chatIdRef.value);

        await nextTick();

        expect(text.value).toBe('saved');
        expect(quotedMessageId.value).toBe('msg-1');
    });

    it('reloads when chat id changes', async () => {
        localStorage.setItem('chatswitch.draft.1', JSON.stringify({ text: 'one', quoted: null }));
        localStorage.setItem('chatswitch.draft.2', JSON.stringify({ text: 'two', quoted: null }));

        const chatIdRef = ref(1);
        const { text } = useChatDraft(() => chatIdRef.value);

        await nextTick();
        expect(text.value).toBe('one');

        chatIdRef.value = 2;
        await nextTick();

        expect(text.value).toBe('two');
    });

    it('reset clears state and removes storage key', async () => {
        const chatIdRef = ref(3);
        const { text, reset } = useChatDraft(() => chatIdRef.value);

        await nextTick();
        text.value = 'x';
        await nextTick();

        expect(localStorage.getItem('chatswitch.draft.3')).not.toBeNull();

        reset();
        await nextTick();

        expect(text.value).toBe('');
        expect(localStorage.getItem('chatswitch.draft.3')).toBeNull();
    });

    it('ignores invalid JSON in localStorage', async () => {
        localStorage.setItem('chatswitch.draft.7', 'not-json{');

        const chatIdRef = ref(7);
        const { text, quotedMessageId } = useChatDraft(() => chatIdRef.value);

        await nextTick();

        expect(text.value).toBe('');
        expect(quotedMessageId.value).toBeNull();
    });
});
