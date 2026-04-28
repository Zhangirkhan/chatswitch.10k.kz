import { reactive } from 'vue';

export interface Toast {
    id: number;
    message: string;
    duration: number;
    action?: {
        label: string;
        handler: () => void | Promise<void>;
    };
    timerId?: ReturnType<typeof setTimeout>;
}

interface ShowToastOptions {
    message: string;
    duration?: number;
    action?: {
        label: string;
        handler: () => void | Promise<void>;
    };
}

interface ToastState {
    items: Toast[];
}

const state = reactive<ToastState>({
    items: [],
});

let nextId = 1;

export function useToastStore() {
    function dismiss(id: number): void {
        const index = state.items.findIndex((t) => t.id === id);
        if (index === -1) return;
        const toast = state.items[index];
        if (toast.timerId) clearTimeout(toast.timerId);
        state.items.splice(index, 1);
    }

    function show(options: ShowToastOptions): number {
        const id = nextId++;
        const duration = options.duration ?? 4000;

        const toast: Toast = {
            id,
            message: options.message,
            duration,
            action: options.action,
        };

        toast.timerId = setTimeout(() => dismiss(id), duration);
        state.items.push(toast);

        return id;
    }

    return {
        state,
        show,
        dismiss,
    };
}
