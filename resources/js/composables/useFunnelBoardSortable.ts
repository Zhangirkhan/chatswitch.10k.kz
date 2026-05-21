import Sortable from 'sortablejs';
import type { Ref } from 'vue';
import { nextTick, onBeforeUnmount, watch } from 'vue';

export type SortableMovePayload = {
    cardId: number;
    fromStageId: number;
    toStageId: number;
};

export function useFunnelBoardSortable(options: {
    stages: Ref<Array<{ id: number; cards: Array<{ id: number }> }>>;
    columnListRefs: Ref<Map<number, HTMLElement>>;
    onMove: (payload: SortableMovePayload) => void;
    onDragStart?: (cardId: number) => void;
    onDragEnd?: (cardId: number | null) => void;
    disabled?: Ref<boolean>;
}): void {
    const instances = new Map<number, Sortable>();

    function destroyAll(): void {
        for (const instance of instances.values()) {
            instance.destroy();
        }
        instances.clear();
    }

    function init(): void {
        destroyAll();

        if (options.disabled?.value) {
            return;
        }

        for (const stage of options.stages.value) {
            const el = options.columnListRefs.value.get(stage.id);
            if (!el) {
                continue;
            }

            const stageId = stage.id;
            const instance = Sortable.create(el, {
                group: 'funnel-board',
                animation: 180,
                easing: 'cubic-bezier(0.22, 1, 0.36, 1)',
                ghostClass: 'funnel-card--ghost',
                dragClass: 'funnel-card--dragging',
                chosenClass: 'funnel-card--chosen',
                delay: 0,
                delayOnTouchOnly: true,
                touchStartThreshold: 4,
                filter: '.funnel-card__select, .funnel-card__history-btn, .funnel-card__actions, .funnel-card__actions-btn',
                preventOnFilter: true,
                draggable: '.funnel-card',
                onStart(evt) {
                    const item = evt.item as HTMLElement;
                    const cardId = Number(item.dataset.cardId);
                    if (Number.isFinite(cardId)) {
                        options.onDragStart?.(cardId);
                    }
                },
                onEnd(evt) {
                    const item = evt.item as HTMLElement;
                    const cardId = Number(item.dataset.cardId);
                    options.onDragEnd?.(Number.isFinite(cardId) ? cardId : null);

                    const fromEl = evt.from as HTMLElement;
                    const toEl = evt.to as HTMLElement;
                    const fromStageId = Number(fromEl.dataset.stageId);
                    const toStageId = Number(toEl.dataset.stageId);

                    if (
                        !Number.isFinite(cardId)
                        || !Number.isFinite(fromStageId)
                        || !Number.isFinite(toStageId)
                        || (fromStageId === toStageId && evt.oldIndex === evt.newIndex)
                    ) {
                        return;
                    }

                    options.onMove({ cardId, fromStageId, toStageId });
                },
            });

            instances.set(stageId, instance);
        }
    }

    watch(
        () => [options.stages.value, options.columnListRefs.value.size],
        () => {
            void nextTick(() => init());
        },
        { deep: true },
    );

    watch(
        () => options.disabled?.value,
        () => {
            void nextTick(() => init());
        },
    );

    onBeforeUnmount(() => {
        destroyAll();
    });

    void nextTick(() => init());
}
