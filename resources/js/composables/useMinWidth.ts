import { onMounted, onUnmounted, ref } from 'vue';

export function useMinWidth(query: string) {
    const matches = ref(false);
    let media: MediaQueryList | undefined;
    let update: (() => void) | undefined;

    onMounted(() => {
        media = window.matchMedia(query);
        update = (): void => {
            matches.value = media?.matches ?? false;
        };
        update();
        media.addEventListener('change', update);
    });

    onUnmounted(() => {
        if (media && update) {
            media.removeEventListener('change', update);
        }
    });

    return matches;
}
