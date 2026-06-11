import { ref } from 'vue';

interface SpeechPreviewRecognition {
    continuous: boolean;
    interimResults: boolean;
    lang: string;
    onresult: ((event: { results: ArrayLike<{ 0?: { transcript?: string } }> }) => void) | null;
    onerror: (() => void) | null;
    start(): void;
    stop(): void;
}

type SpeechRecognitionCtor = new () => SpeechPreviewRecognition;

function getSpeechRecognitionCtor(): SpeechRecognitionCtor | null {
    const w = window as Window & {
        SpeechRecognition?: SpeechRecognitionCtor;
        webkitSpeechRecognition?: SpeechRecognitionCtor;
    };

    return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null;
}

export function useSpeechPreview(language: string) {
    const previewText = ref('');
    const supported = getSpeechRecognitionCtor() !== null;

    let recognition: SpeechPreviewRecognition | null = null;

    function mapLanguage(locale: string): string {
        if (locale === 'kk') {
            return 'kk-KZ';
        }
        if (locale === 'en') {
            return 'en-US';
        }

        return 'ru-RU';
    }

    function start(): void {
        stop();
        previewText.value = '';

        const Ctor = getSpeechRecognitionCtor();
        if (!Ctor) {
            return;
        }

        recognition = new Ctor();
        recognition.continuous = true;
        recognition.interimResults = true;
        recognition.lang = mapLanguage(language);

        recognition.onresult = (event) => {
            let text = '';
            for (let i = 0; i < event.results.length; i += 1) {
                text += event.results[i][0]?.transcript ?? '';
            }
            previewText.value = text.trim();
        };

        recognition.onerror = () => {
            stop();
        };

        try {
            recognition.start();
        } catch {
            stop();
        }
    }

    function stop(): void {
        if (!recognition) {
            return;
        }

        try {
            recognition.stop();
        } catch {
            // ignore
        }

        recognition = null;
    }

    function reset(): void {
        stop();
        previewText.value = '';
    }

    return {
        previewText,
        supported,
        start,
        stop,
        reset,
    };
}
