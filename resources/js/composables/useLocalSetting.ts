import { ref, watch, type Ref } from 'vue';

const STORAGE_PREFIX = 'accel.settings.';

/**
 * A reactive ref backed by localStorage. Great for purely-client-side
 * preference toggles that should survive a reload.
 */
export function useLocalSetting<T>(key: string, initial: T): Ref<T> {
    const storageKey = STORAGE_PREFIX + key;

    const readStored = (): T => {
        if (typeof window === 'undefined') return initial;
        try {
            const raw = window.localStorage.getItem(storageKey);
            if (raw === null) return initial;
            return JSON.parse(raw) as T;
        } catch {
            return initial;
        }
    };

    const value = ref(readStored()) as Ref<T>;

    watch(
        value,
        (next) => {
            if (typeof window === 'undefined') return;
            try {
                window.localStorage.setItem(storageKey, JSON.stringify(next));
            } catch {
                // Storage quota or privacy mode — silently ignore.
            }
        },
        { deep: true },
    );

    return value;
}
