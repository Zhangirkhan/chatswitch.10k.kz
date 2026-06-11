import axios from 'axios';
import { computed, onBeforeUnmount, ref, type Ref } from 'vue';

export type SpeechDictationState = 'idle' | 'requesting' | 'recording' | 'transcribing';

const MAX_RECORDING_SECONDS = 120;
const MIN_HOLD_MS = 500;

export type SpeechDictationContext = {
    chatId?: number;
    language?: string;
};

type SpeechDictationCallbacks = {
    onTranscript: (text: string) => void;
    onError: (code: string) => void;
    onRequesting?: () => void;
    onPreview?: (text: string) => void;
};

export type MicPermissionState = PermissionState | 'unknown';

function createMediaRecorder(stream: MediaStream): { recorder: MediaRecorder; mimeType: string } {
    const candidates = [
        'audio/webm;codecs=opus',
        'audio/webm',
        'audio/ogg;codecs=opus',
        'audio/mp4',
    ];

    for (const candidate of candidates) {
        if (candidate && !MediaRecorder.isTypeSupported(candidate)) {
            continue;
        }

        try {
            return {
                recorder: candidate ? new MediaRecorder(stream, { mimeType: candidate }) : new MediaRecorder(stream),
                mimeType: candidate,
            };
        } catch {
            continue;
        }
    }

    return {
        recorder: new MediaRecorder(stream),
        mimeType: '',
    };
}

function fileExtensionForMime(mimeType: string): string {
    if (mimeType.includes('mp4') || mimeType.includes('m4a')) {
        return 'm4a';
    }

    return mimeType.includes('ogg') ? 'ogg' : 'webm';
}

export function resolveMicErrorCode(error: unknown): string {
    if (axios.isAxiosError(error) && error.code === 'ERR_CANCELED') {
        return 'cancelled';
    }

    if (!(error instanceof DOMException)) {
        return 'micDenied';
    }

    switch (error.name) {
        case 'NotAllowedError':
        case 'PermissionDeniedError':
            return 'micDenied';
        case 'NotFoundError':
        case 'DevicesNotFoundError':
            return 'micNotFound';
        case 'NotReadableError':
        case 'TrackStartError':
            return 'micBusy';
        case 'SecurityError':
            return 'insecureContext';
        case 'OverconstrainedError':
            return 'micNotFound';
        default:
            return 'micDenied';
    }
}

export async function queryMicPermission(): Promise<MicPermissionState> {
    if (!navigator.permissions?.query) {
        return 'unknown';
    }

    try {
        const result = await navigator.permissions.query({ name: 'microphone' as PermissionName });

        return result.state;
    } catch {
        return 'unknown';
    }
}

export function useSpeechDictation(
    callbacks: SpeechDictationCallbacks,
    context: Ref<SpeechDictationContext> = ref({}),
) {
    const state = ref<SpeechDictationState>('idle');
    const recordingSeconds = ref(0);
    const recordStream = ref<MediaStream | null>(null);

    const mediaRecorder = ref<MediaRecorder | null>(null);
    const recordedChunks = ref<Blob[]>([]);

    let recordInterval: ReturnType<typeof setInterval> | null = null;
    let autoStopTimer: ReturnType<typeof setTimeout> | null = null;
    let recordingCancelled = false;
    let recordingStartedAt = 0;
    let transcribeAbort: AbortController | null = null;

    const isActive = computed(() => state.value !== 'idle');

    function clearTimers(): void {
        if (recordInterval !== null) {
            clearInterval(recordInterval);
            recordInterval = null;
        }

        if (autoStopTimer !== null) {
            clearTimeout(autoStopTimer);
            autoStopTimer = null;
        }
    }

    function stopStream(): void {
        recordStream.value?.getTracks().forEach((track) => track.stop());
        recordStream.value = null;
    }

    function resetRecordingBuffers(): void {
        recordingSeconds.value = 0;
        recordedChunks.value = [];
        mediaRecorder.value = null;
    }

    async function transcribeBlob(blob: Blob, mimeType: string): Promise<void> {
        state.value = 'transcribing';
        transcribeAbort = new AbortController();

        const ext = fileExtensionForMime(mimeType);
        const file = new File([blob], `dictation-${Date.now()}.${ext}`, {
            type: blob.type || mimeType || 'audio/webm',
        });

        const formData = new FormData();
        formData.append('audio', file);

        const ctx = context.value;
        if (ctx.language) {
            formData.append('language', ctx.language);
        }
        if (ctx.chatId) {
            formData.append('chat_id', String(ctx.chatId));
        }

        try {
            const { data } = await axios.post<{ text?: string }>(route('ai-chat.transcribe'), formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
                signal: transcribeAbort.signal,
            });

            const text = String(data.text ?? '').trim();
            if (!text) {
                callbacks.onError('empty');
                return;
            }

            callbacks.onTranscript(text);
        } catch (error: unknown) {
            if (axios.isAxiosError(error) && error.code === 'ERR_CANCELED') {
                return;
            }

            if (axios.isAxiosError(error)) {
                const status = error.response?.status;
                const message = error.response?.data?.message;

                if (status === 503) {
                    callbacks.onError('unavailable');
                    return;
                }

                if (typeof message === 'string' && message.trim() !== '') {
                    callbacks.onError(message);
                    return;
                }
            }

            callbacks.onError('failed');
        } finally {
            transcribeAbort = null;
            state.value = 'idle';
        }
    }

    async function start(): Promise<boolean> {
        if (state.value !== 'idle') {
            return false;
        }

        if (!window.isSecureContext) {
            callbacks.onError('insecureContext');
            return false;
        }

        if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
            callbacks.onError('notSupported');
            return false;
        }

        state.value = 'requesting';
        callbacks.onRequesting?.();

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                },
            });
            recordStream.value = stream;
            recordedChunks.value = [];
            recordingCancelled = false;
            recordingStartedAt = Date.now();

            const { recorder, mimeType } = createMediaRecorder(stream);
            mediaRecorder.value = recorder;

            recorder.ondataavailable = (event) => {
                if (event.data.size > 0) {
                    recordedChunks.value.push(event.data);
                }
            };

            recorder.onstop = async () => {
                stopStream();
                clearTimers();

                const resolvedMime = recorder.mimeType || mimeType || 'audio/webm';
                const blob = new Blob(recordedChunks.value, { type: resolvedMime });
                resetRecordingBuffers();

                if (recordingCancelled) {
                    state.value = 'idle';
                    return;
                }

                if (blob.size === 0) {
                    state.value = 'idle';
                    callbacks.onError('empty');
                    return;
                }

                await transcribeBlob(blob, resolvedMime);
            };

            recorder.start(250);
            state.value = 'recording';
            recordingSeconds.value = 0;
            recordInterval = setInterval(() => {
                recordingSeconds.value += 1;
            }, 1000);

            autoStopTimer = setTimeout(() => {
                stop();
            }, MAX_RECORDING_SECONDS * 1000);

            return true;
        } catch (error: unknown) {
            clearTimers();
            stopStream();
            resetRecordingBuffers();
            state.value = 'idle';
            callbacks.onError(resolveMicErrorCode(error));

            return false;
        }
    }

    function stop(): boolean {
        if (state.value === 'transcribing') {
            transcribeAbort?.abort();
            transcribeAbort = null;
            state.value = 'idle';
            return true;
        }

        if (state.value !== 'recording' || !mediaRecorder.value) {
            return false;
        }

        const heldMs = Date.now() - recordingStartedAt;
        if (heldMs < MIN_HOLD_MS) {
            recordingCancelled = true;
            clearTimers();
            mediaRecorder.value.stop();
            callbacks.onError('holdTooShort');
            return false;
        }

        clearTimers();
        mediaRecorder.value.stop();
        return true;
    }

    function cancel(): void {
        if (state.value === 'transcribing') {
            transcribeAbort?.abort();
            transcribeAbort = null;
            state.value = 'idle';
            return;
        }

        recordingCancelled = true;
        clearTimers();

        if (mediaRecorder.value && mediaRecorder.value.state !== 'inactive') {
            mediaRecorder.value.stop();
        } else {
            stopStream();
            resetRecordingBuffers();
            state.value = 'idle';
        }
    }

    onBeforeUnmount(() => {
        if (state.value === 'recording') {
            cancel();
        }
        if (state.value === 'transcribing') {
            transcribeAbort?.abort();
        }
    });

    return {
        state,
        recordingSeconds,
        recordStream,
        isActive,
        start,
        stop,
        cancel,
    };
}
