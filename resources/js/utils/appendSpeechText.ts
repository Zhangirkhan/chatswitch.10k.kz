export function appendSpeechText(current: string, incoming: string): string {
    const trimmed = incoming.trim();
    if (!trimmed) {
        return current;
    }

    const base = current.trim();
    return base ? `${base.trimEnd()} ${trimmed}` : trimmed;
}

export const SPEECH_DICTATION_HIGHLIGHT_CLASS = 'speech-dictation-highlight';

export function highlightSpeechInput(el: HTMLElement | null, durationMs = 1500): void {
    if (!el) {
        return;
    }

    el.classList.add(SPEECH_DICTATION_HIGHLIGHT_CLASS);
    window.setTimeout(() => {
        el.classList.remove(SPEECH_DICTATION_HIGHLIGHT_CLASS);
    }, durationMs);
}
