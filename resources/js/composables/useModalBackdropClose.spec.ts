import { describe, expect, it } from 'vitest';
import { shouldCloseModalOnBackdropClick } from './useModalBackdropClose';

describe('shouldCloseModalOnBackdropClick', () => {
    const backdrop = document.createElement('div');

    it('closes when pointerdown and click both happen on backdrop', () => {
        expect(
            shouldCloseModalOnBackdropClick({
                eventTarget: backdrop,
                eventCurrentTarget: backdrop,
                pointerDownOnBackdrop: true,
                selectedText: '',
            }),
        ).toBe(true);
    });

    it('does not close when pointerdown started inside the panel', () => {
        expect(
            shouldCloseModalOnBackdropClick({
                eventTarget: backdrop,
                eventCurrentTarget: backdrop,
                pointerDownOnBackdrop: false,
                selectedText: '',
            }),
        ).toBe(false);
    });

    it('does not close when text is selected', () => {
        expect(
            shouldCloseModalOnBackdropClick({
                eventTarget: backdrop,
                eventCurrentTarget: backdrop,
                pointerDownOnBackdrop: true,
                selectedText: 'selected text',
            }),
        ).toBe(false);
    });
});
