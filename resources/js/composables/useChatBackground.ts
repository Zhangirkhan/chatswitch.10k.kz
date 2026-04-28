import { ref, watch } from 'vue';
import { useTheme } from './useTheme';
import {
    applyWallpaper,
    findWallpaper,
    getStoredWallpaperId,
    storeWallpaperId,
    wallpapers,
    type Wallpaper,
} from '@/config/wallpapers';

const currentWallpaperId = ref<string>(getStoredWallpaperId());

let initialized = false;

export function initChatBackground(): void {
    const { theme } = useTheme();
    applyWallpaper(findWallpaper(currentWallpaperId.value), theme.value);

    if (initialized) return;
    initialized = true;

    watch(theme, (value) => {
        applyWallpaper(findWallpaper(currentWallpaperId.value), value);
    });

    watch(currentWallpaperId, (id) => {
        applyWallpaper(findWallpaper(id), theme.value);
        storeWallpaperId(id);
    });
}

export function useChatBackground() {
    const { theme } = useTheme();

    return {
        theme,
        wallpapers,
        currentWallpaperId,
        setWallpaper(id: string) {
            currentWallpaperId.value = id;
        },
        getCurrent(): Wallpaper {
            return findWallpaper(currentWallpaperId.value);
        },
    };
}
