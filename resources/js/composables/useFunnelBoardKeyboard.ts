import type { Ref } from 'vue';
import { onBeforeUnmount, onMounted, watch } from 'vue';

type BoardCard = { id: number };
type BoardStage = { id: number; cards: BoardCard[]; is_inbox?: boolean };

export function useFunnelBoardKeyboard(options: {
    visibleStages: Ref<BoardStage[]>;
    focusStageIdx: Ref<number>;
    focusCardIdx: Ref<number>;
    enabled: Ref<boolean>;
    onOpenCard: (card: BoardCard) => void;
    onToggleSelect: (cardId: number) => void;
    onCloseOverlays: () => void;
    focusCardElement: (stageIdx: number, cardIdx: number) => void;
}): void {
    function clampFocus(): void {
        const stages = options.visibleStages.value;
        if (stages.length === 0) {
            options.focusStageIdx.value = 0;
            options.focusCardIdx.value = 0;
            return;
        }

        options.focusStageIdx.value = Math.max(0, Math.min(options.focusStageIdx.value, stages.length - 1));
        const cards = stages[options.focusStageIdx.value]?.cards ?? [];
        if (cards.length === 0) {
            options.focusCardIdx.value = 0;
            return;
        }
        options.focusCardIdx.value = Math.max(0, Math.min(options.focusCardIdx.value, cards.length - 1));
    }

    function moveFocus(stageDelta: number, cardDelta: number): void {
        const stages = options.visibleStages.value;
        if (stages.length === 0) {
            return;
        }

        let stageIdx = options.focusStageIdx.value;
        let cardIdx = options.focusCardIdx.value;

        if (cardDelta !== 0) {
            const cards = stages[stageIdx]?.cards ?? [];
            if (cards.length === 0) {
                return;
            }
            cardIdx += cardDelta;
            if (cardIdx < 0) {
                cardIdx = 0;
            } else if (cardIdx >= cards.length) {
                cardIdx = cards.length - 1;
            }
        }

        if (stageDelta !== 0) {
            stageIdx += stageDelta;
            if (stageIdx < 0) {
                stageIdx = 0;
            } else if (stageIdx >= stages.length) {
                stageIdx = stages.length - 1;
            }
            const nextCards = stages[stageIdx]?.cards ?? [];
            if (nextCards.length === 0) {
                cardIdx = 0;
            } else if (cardIdx >= nextCards.length) {
                cardIdx = nextCards.length - 1;
            }
        }

        options.focusStageIdx.value = stageIdx;
        options.focusCardIdx.value = cardIdx;
        options.focusCardElement(stageIdx, cardIdx);
    }

    function activeCard(): BoardCard | null {
        clampFocus();
        const stage = options.visibleStages.value[options.focusStageIdx.value];
        return stage?.cards[options.focusCardIdx.value] ?? null;
    }

    function onKeydown(event: KeyboardEvent): void {
        if (!options.enabled.value) {
            return;
        }

        const target = event.target as HTMLElement | null;
        if (
            target instanceof HTMLInputElement
            || target instanceof HTMLTextAreaElement
            || target instanceof HTMLSelectElement
            || target?.isContentEditable
        ) {
            return;
        }

        const card = activeCard();

        switch (event.key) {
            case 'ArrowRight':
                event.preventDefault();
                moveFocus(1, 0);
                break;
            case 'ArrowLeft':
                event.preventDefault();
                moveFocus(-1, 0);
                break;
            case 'ArrowDown':
                event.preventDefault();
                moveFocus(0, 1);
                break;
            case 'ArrowUp':
                event.preventDefault();
                moveFocus(0, -1);
                break;
            case 'Home':
                event.preventDefault();
                options.focusCardIdx.value = 0;
                options.focusCardElement(options.focusStageIdx.value, 0);
                break;
            case 'End': {
                event.preventDefault();
                const count = options.visibleStages.value[options.focusStageIdx.value]?.cards.length ?? 0;
                const last = Math.max(0, count - 1);
                options.focusCardIdx.value = last;
                options.focusCardElement(options.focusStageIdx.value, last);
                break;
            }
            case 'Enter':
                if (card) {
                    event.preventDefault();
                    options.onOpenCard(card);
                }
                break;
            case ' ':
                if (card) {
                    event.preventDefault();
                    options.onToggleSelect(card.id);
                }
                break;
            case 'Escape':
                options.onCloseOverlays();
                break;
            default:
                break;
        }
    }

    watch(options.visibleStages, () => {
        clampFocus();
    });

    onMounted(() => {
        window.addEventListener('keydown', onKeydown);
    });

    onBeforeUnmount(() => {
        window.removeEventListener('keydown', onKeydown);
    });
}
