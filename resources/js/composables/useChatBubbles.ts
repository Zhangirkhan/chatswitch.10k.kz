import { ref, watch } from 'vue';
import { useTheme } from './useTheme';
import {
    applyMessageStyle,
    findMessageStyle,
    getStoredMessageStyleId,
    messageStylePresets,
    storeMessageStyleId,
    type MessageStylePreset,
} from '@/config/chatBubbles';

const currentMessageStyleId = ref<string>(getStoredMessageStyleId());

let initialized = false;

export function initChatBubbles(): void {
    const { theme } = useTheme();
    applyMessageStyle(findMessageStyle(currentMessageStyleId.value), theme.value);

    if (initialized) {
        return;
    }

    initialized = true;

    watch(theme, (value) => {
        applyMessageStyle(findMessageStyle(currentMessageStyleId.value), value);
    });

    watch(currentMessageStyleId, (id) => {
        applyMessageStyle(findMessageStyle(id), theme.value);
        storeMessageStyleId(id);
    });
}

export function useChatMessageStyle() {
    const { theme } = useTheme();

    return {
        theme,
        presets: messageStylePresets,
        currentMessageStyleId,
        setMessageStyle(id: string) {
            currentMessageStyleId.value = id;
        },
        getCurrent(): MessageStylePreset {
            return findMessageStyle(currentMessageStyleId.value);
        },
    };
}

/** @deprecated — используйте useChatMessageStyle */
export function useChatBubbles() {
    const style = useChatMessageStyle();

    return {
        theme: style.theme,
        presets: style.presets,
        currentBubblePresetId: style.currentMessageStyleId,
        setBubblePreset: style.setMessageStyle,
        getCurrent: style.getCurrent,
    };
}
