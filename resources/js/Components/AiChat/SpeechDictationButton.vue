<script setup lang="ts">
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import { computed, onMounted, ref, toRef, watch } from 'vue';
import { useAudioLevelMeter } from '@/composables/useAudioLevelMeter';
import { useI18n } from '@/composables/useI18n';
import { useLocalSetting } from '@/composables/useLocalSetting';
import { useSpeechPreview } from '@/composables/useSpeechPreview';
import { useToastStore } from '@/stores/toast';
import {
    queryMicPermission,
    useSpeechDictation,
    type MicPermissionState,
} from '@/composables/useSpeechDictation';

const props = withDefaults(
    defineProps<{
        disabled?: boolean;
        size?: 'sm' | 'md';
        interaction?: 'hold' | 'toggle';
        chatId?: number;
        language?: string;
        livePreview?: boolean;
    }>(),
    {
        disabled: false,
        size: 'md',
        interaction: 'hold',
        livePreview: false,
    },
);

const emit = defineEmits<{
    (e: 'transcript', text: string): void;
    (e: 'error', message: string): void;
    (e: 'active-change', active: boolean): void;
    (e: 'preview', text: string): void;
}>();

const { t, locale } = useI18n();
const { show: showToast } = useToastStore();
const livePreviewEnabled = useLocalSetting('speechDictation.livePreview', props.livePreview);

const errorMessages: Record<string, string> = {
    notSupported: 'misc.speechDictation.notSupported',
    micDenied: 'misc.speechDictation.micDenied',
    micBlocked: 'misc.speechDictation.micBlocked',
    micNotFound: 'misc.speechDictation.micNotFound',
    micBusy: 'misc.speechDictation.micBusy',
    insecureContext: 'misc.speechDictation.insecureContext',
    failed: 'misc.speechDictation.failed',
    empty: 'misc.speechDictation.empty',
    unavailable: 'misc.speechDictation.unavailable',
    holdTooShort: 'misc.speechDictation.holdTooShort',
    cancelled: 'misc.speechDictation.cancelled',
};

const micPermissionState = ref<MicPermissionState>('unknown');
const permissionModalOpen = ref(false);
const holding = ref(false);
const holdStartedAt = ref(0);

const context = computed(() => ({
    chatId: props.chatId,
    language: props.language ?? locale.value,
}));

const speechPreview = useSpeechPreview(props.language ?? locale.value);

const { state, recordingSeconds, recordStream, isActive, start, stop, cancel } = useSpeechDictation(
    {
        onTranscript(text) {
            micPermissionState.value = 'granted';
            speechPreview.reset();
            emit('preview', '');
            emit('transcript', text);
        },
        onError(code) {
            if (code === 'cancelled') {
                return;
            }

            speechPreview.reset();
            emit('preview', '');

            const key = errorMessages[code];
            if (code === 'micDenied') {
                void refreshMicPermission().then(() => {
                    if (micPermissionState.value === 'denied') {
                        permissionModalOpen.value = true;
                    }
                });
            }

            if (code === 'holdTooShort') {
                showToast({ message: t('misc.speechDictation.holdTooShort'), type: 'info' });
                return;
            }

            emit('error', key ? t(key) : code);
        },
        onRequesting() {
            showToast({ message: t('misc.speechDictation.requestingConfirm'), type: 'info' });
        },
        onPreview(text) {
            emit('preview', text);
        },
    },
    toRef(() => context.value),
);

const { level: audioLevel, attach: attachMeter, stop: stopMeter } = useAudioLevelMeter(recordStream);

const isRecording = computed(() => state.value === 'recording');
const isRequesting = computed(() => state.value === 'requesting');
const isTranscribing = computed(() => state.value === 'transcribing');
const isDisabled = computed(() => props.disabled || isRequesting.value);

const buttonClass = computed(() => [
    'speech-dictation-btn',
    props.size === 'sm' ? 'speech-dictation-btn--sm' : 'speech-dictation-btn--md',
    {
        'speech-dictation-btn--recording': isRecording.value,
        'speech-dictation-btn--requesting': isRequesting.value,
        'speech-dictation-btn--transcribing': isTranscribing.value,
        'speech-dictation-btn--holding': holding.value,
    },
]);

const title = computed(() => {
    if (isRequesting.value) {
        return t('misc.speechDictation.requesting');
    }

    if (isTranscribing.value) {
        return t('misc.speechDictation.cancelTranscribing');
    }

    if (isRecording.value) {
        return props.interaction === 'hold'
            ? t('misc.speechDictation.holdRelease')
            : t('misc.speechDictation.stop');
    }

    return props.interaction === 'hold'
        ? t('misc.speechDictation.holdStart')
        : t('misc.speechDictation.start');
});

const meterBars = computed(() => {
    const bars = 5;
    const active = Math.round(audioLevel.value * bars);

    return Array.from({ length: bars }, (_, index) => index < active);
});

const timerLabel = computed(() => {
    const minutes = Math.floor(recordingSeconds.value / 60);
    const seconds = recordingSeconds.value % 60;
    return `${minutes}:${seconds.toString().padStart(2, '0')}`;
});

async function refreshMicPermission(): Promise<void> {
    micPermissionState.value = await queryMicPermission();
}

function closePermissionModal(): void {
    permissionModalOpen.value = false;
}

function beginRecording(): void {
    void start().then((started) => {
        if (started) {
            micPermissionState.value = 'granted';
            if (livePreviewEnabled.value && speechPreview.supported) {
                speechPreview.start();
            }
        }
    });
}

function confirmMicAccess(): void {
    closePermissionModal();
    beginRecording();
}

function onPointerDown(event: PointerEvent): void {
    if (isDisabled.value) {
        return;
    }

    if (isTranscribing.value) {
        cancel();
        return;
    }

    if (props.interaction === 'toggle') {
        if (isRecording.value) {
            stop();
        } else if (state.value === 'idle') {
            beginRecording();
        }
        return;
    }

    event.preventDefault();
    holding.value = true;
    holdStartedAt.value = Date.now();

    if (state.value === 'idle') {
        beginRecording();
    }
}

function onPointerUp(): void {
    if (props.interaction !== 'hold' || !holding.value) {
        return;
    }

    holding.value = false;
    speechPreview.stop();

    if (isRecording.value) {
        stop();
    }
}

function onPointerLeave(): void {
    if (props.interaction === 'hold' && holding.value) {
        onPointerUp();
    }
}

watch(
    () => speechPreview.previewText.value,
    (text) => {
        if (livePreviewEnabled.value && isRecording.value) {
            emit('preview', text);
        }
    },
);

watch(recordStream, (stream) => {
    if (stream) {
        attachMeter(stream);
    } else {
        stopMeter();
    }
});

watch(isActive, (active) => {
    emit('active-change', active);
});

function toggleDictation(): void {
    if (props.interaction === 'toggle') {
        onPointerDown(new PointerEvent('pointerdown'));
        return;
    }

    if (isRecording.value) {
        stop();
        return;
    }

    if (state.value === 'idle' && !isDisabled.value) {
        beginRecording();
        window.setTimeout(() => {
            if (isRecording.value) {
                stop();
            }
        }, 3000);
    }
}

defineExpose({ toggleDictation, beginRecording, stop, cancel, isRecording, isActive });

onMounted(() => {
    void refreshMicPermission();
});
</script>

<template>
    <div class="speech-dictation">
        <div v-if="isRecording" class="speech-dictation__meter" aria-hidden="true">
            <span
                v-for="(active, index) in meterBars"
                :key="index"
                class="speech-dictation__meter-bar"
                :class="{ 'speech-dictation__meter-bar--active': active }"
            />
        </div>
        <button
            type="button"
            :class="buttonClass"
            :disabled="isDisabled"
            :title="title"
            :aria-label="title"
            style="touch-action: none"
            @pointerdown="onPointerDown"
            @pointerup="onPointerUp"
            @pointerleave="onPointerLeave"
            @pointercancel="onPointerUp"
        >
            <svg
                v-if="!isTranscribing && !isRequesting"
                class="speech-dictation-btn__icon"
                fill="currentColor"
                viewBox="0 0 24 24"
                aria-hidden="true"
            >
                <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5-3c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z" />
            </svg>
            <svg
                v-else
                class="speech-dictation-btn__icon speech-dictation-btn__icon--spin"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2.5"
                aria-hidden="true"
            >
                <path stroke-linecap="round" d="M21 12a9 9 0 11-9-9" />
            </svg>
        </button>
        <span
            v-if="isRecording"
            class="speech-dictation__timer"
            aria-live="polite"
        >
            {{ timerLabel }}
        </span>

        <DangerConfirmModal
            :open="permissionModalOpen"
            :title="t('misc.speechDictation.micBlockedTitle')"
            :description="`${t('misc.speechDictation.micBlocked')}\n\n${t('misc.speechDictation.micBlockedHint')}`"
            :confirm-label="t('misc.speechDictation.micPermissionRetry')"
            :cancel-label="t('common.close')"
            confirm-variant="primary"
            @close="closePermissionModal"
            @confirm="confirmMicAccess"
        />
    </div>
</template>

<style scoped>
.speech-dictation {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    flex-shrink: 0;
}

.speech-dictation__meter {
    display: inline-flex;
    align-items: flex-end;
    gap: 2px;
    height: 1rem;
}

.speech-dictation__meter-bar {
    width: 3px;
    height: 4px;
    border-radius: 1px;
    background: color-mix(in srgb, var(--wa-text-secondary) 35%, transparent);
    transition: height 0.08s ease, background 0.08s ease;
}

.speech-dictation__meter-bar--active {
    height: 12px;
    background: #dc2626;
}

.speech-dictation-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--wa-border);
    border-radius: 999px;
    background: var(--wa-panel);
    color: var(--wa-text-secondary);
    cursor: pointer;
    user-select: none;
    transition:
        background 0.2s ease,
        color 0.2s ease,
        border-color 0.2s ease,
        transform 0.18s ease,
        box-shadow 0.2s ease;
}

.speech-dictation-btn--sm {
    width: 2rem;
    height: 2rem;
}

.speech-dictation-btn--md {
    width: 2.5rem;
    height: 2.5rem;
}

.speech-dictation-btn__icon {
    width: 1.1rem;
    height: 1.1rem;
    pointer-events: none;
}

.speech-dictation-btn__icon--spin {
    animation: speech-dictation-spin 0.85s linear infinite;
}

.speech-dictation-btn:not(:disabled):hover {
    color: var(--wa-text);
    border-color: color-mix(in srgb, var(--wa-accent) 35%, var(--wa-border));
}

.speech-dictation-btn--recording,
.speech-dictation-btn--holding {
    color: #fff;
    background: #dc2626;
    border-color: #dc2626;
    animation: speech-dictation-pulse 1.2s ease-in-out infinite;
}

.speech-dictation-btn--requesting,
.speech-dictation-btn--transcribing {
    color: var(--wa-accent);
    border-color: color-mix(in srgb, var(--wa-accent) 40%, var(--wa-border));
    animation: none;
}

.speech-dictation-btn:disabled {
    opacity: 0.45;
    cursor: not-allowed;
    animation: none;
}

.speech-dictation__timer {
    font-size: 0.72rem;
    font-variant-numeric: tabular-nums;
    color: #dc2626;
    min-width: 2.2rem;
}

@keyframes speech-dictation-pulse {
    0%,
    100% {
        box-shadow: 0 0 0 0 color-mix(in srgb, #dc2626 45%, transparent);
    }

    50% {
        box-shadow: 0 0 0 6px transparent;
    }
}

@keyframes speech-dictation-spin {
    to {
        transform: rotate(360deg);
    }
}
</style>

<style>
.speech-dictation-highlight {
    animation: speech-dictation-highlight-fade 1.5s ease-out;
}

@keyframes speech-dictation-highlight-fade {
    0% {
        background: color-mix(in srgb, var(--wa-accent) 18%, var(--wa-panel)) !important;
    }

    100% {
        background: var(--wa-panel);
    }
}

.ai-workspace__composer-input.speech-dictation-highlight {
    background: color-mix(in srgb, var(--wa-accent) 12%, transparent) !important;
}
</style>
