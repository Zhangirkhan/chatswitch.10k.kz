import { ref } from 'vue';

export function shouldCloseModalOnBackdropClick(options: {
    eventTarget: EventTarget | null;
    eventCurrentTarget: EventTarget | null;
    pointerDownOnBackdrop: boolean;
    selectedText: string;
}): boolean {
    if (options.eventTarget !== options.eventCurrentTarget) {
        return false;
    }

    if (!options.pointerDownOnBackdrop) {
        return false;
    }

    return options.selectedText.length === 0;
}

export function useModalBackdropClose(onClose: () => void) {
    const pointerDownOnBackdrop = ref(false);

    function onBackdropPointerDown(event: PointerEvent): void {
        pointerDownOnBackdrop.value = event.target === event.currentTarget;
    }

    function onPanelPointerDown(): void {
        pointerDownOnBackdrop.value = false;
    }

    function onBackdropClick(event: MouseEvent): void {
        const selection = window.getSelection();

        if (
            !shouldCloseModalOnBackdropClick({
                eventTarget: event.target,
                eventCurrentTarget: event.currentTarget,
                pointerDownOnBackdrop: pointerDownOnBackdrop.value,
                selectedText: selection?.toString() ?? '',
            })
        ) {
            return;
        }

        onClose();
    }

    return {
        onBackdropPointerDown,
        onPanelPointerDown,
        onBackdropClick,
    };
}
