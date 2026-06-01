import { ref, watch } from 'vue';
import { useTheme } from './useTheme';
import {
    applyBubblePreset,
    bubblePresets,
    findBubblePreset,
    getStoredBubblePresetId,
    storeBubblePresetId,
    type BubblePreset,
} from '@/config/chatBubbles';

const currentBubblePresetId = ref<string>(getStoredBubblePresetId());

let initialized = false;

export function initChatBubbles(): void {
    const { theme } = useTheme();
    applyBubblePreset(findBubblePreset(currentBubblePresetId.value), theme.value);

    if (initialized) {
        return;
    }

    initialized = true;

    watch(theme, (value) => {
        applyBubblePreset(findBubblePreset(currentBubblePresetId.value), value);
    });

    watch(currentBubblePresetId, (id) => {
        applyBubblePreset(findBubblePreset(id), theme.value);
        storeBubblePresetId(id);
    });
}

export function useChatBubbles() {
    const { theme } = useTheme();

    return {
        theme,
        presets: bubblePresets,
        currentBubblePresetId,
        setBubblePreset(id: string) {
            currentBubblePresetId.value = id;
        },
        getCurrent(): BubblePreset {
            return findBubblePreset(currentBubblePresetId.value);
        },
    };
}
