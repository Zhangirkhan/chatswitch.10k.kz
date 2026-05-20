import { computed, ref, type Ref } from 'vue';
import { useLocalSetting } from '@/composables/useLocalSetting';

export type ResizablePanelEdge = 'left' | 'right';

/** Shared width for chat list + organization list sidebars (WhatsApp-style left panel). */
export const LIST_SIDEBAR_WIDTH_STORAGE_KEY = 'chats.sidebarWidth';

export const LIST_SIDEBAR_WIDTH_DEFAULTS = {
    defaultWidth: 400,
    minWidth: 280,
    maxWidth: 560,
} as const;

export type ResizablePanelWidthOptions = {
    storageKey: string;
    defaultWidth: number;
    minWidth: number;
    maxWidth: number;
    /** Панель слева — тянем правый край; панель справа — левый край. */
    edge: ResizablePanelEdge;
};

function clamp(value: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, value));
}

export function useResizablePanelWidth(options: ResizablePanelWidthOptions): {
    width: Ref<number>;
    widthPx: Ref<string>;
    isResizing: Ref<boolean>;
    onResizePointerDown: (event: PointerEvent) => void;
} {
    const width = useLocalSetting(options.storageKey, options.defaultWidth);
    const isResizing = ref(false);

    const widthPx = computed(() => `${Math.round(clamp(width.value, options.minWidth, options.maxWidth))}px`);

    const direction = options.edge === 'left' ? 1 : -1;

    function onResizePointerDown(event: PointerEvent): void {
        if (event.button !== 0) {
            return;
        }

        event.preventDefault();
        isResizing.value = true;

        const startX = event.clientX;
        const startWidth = width.value;

        const onMove = (moveEvent: PointerEvent): void => {
            const delta = (moveEvent.clientX - startX) * direction;
            width.value = clamp(startWidth + delta, options.minWidth, options.maxWidth);
        };

        const onUp = (): void => {
            isResizing.value = false;
            document.removeEventListener('pointermove', onMove);
            document.removeEventListener('pointerup', onUp);
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
        };

        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';
        document.addEventListener('pointermove', onMove);
        document.addEventListener('pointerup', onUp);
    }

    return {
        width,
        widthPx,
        isResizing,
        onResizePointerDown,
    };
}
