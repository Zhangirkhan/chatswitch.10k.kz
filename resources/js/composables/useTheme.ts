import { ref, watch } from 'vue';

export type Theme = 'light' | 'dark';

const STORAGE_KEY = 'accel.theme';

function getInitialTheme(): Theme {
    if (typeof window === 'undefined') return 'dark';
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored === 'light' || stored === 'dark') return stored;
    return window.matchMedia?.('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
}

const theme = ref<Theme>(getInitialTheme());

function apply(value: Theme) {
    if (typeof document === 'undefined') return;
    document.documentElement.dataset.theme = value;
    document.documentElement.style.colorScheme = value;
    localStorage.setItem(STORAGE_KEY, value);
}

apply(theme.value);
watch(theme, apply);

export function useTheme() {
    return {
        theme,
        toggle() {
            theme.value = theme.value === 'dark' ? 'light' : 'dark';
        },
        set(value: Theme) {
            theme.value = value;
        },
    };
}

export function initTheme(): void {
    apply(theme.value);
}
