<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import EmojiPicker from '@/Pages/Chats/Partials/EmojiPicker.vue';
import { useI18n } from '@/composables/useI18n';
import { useToastStore } from '@/stores/toast';
import type { TeamMentionCandidate } from '@/utils/teamChatMentions';

const { t } = useI18n();

const { show: showToast } = useToastStore();

const props = withDefaults(
    defineProps<{
        modelValue: string;
        attachments?: File[];
        disabled?: boolean;
        placeholder?: string;
        mentionCandidates?: TeamMentionCandidate[];
    }>(),
    {
        attachments: () => [],
        disabled: false,
        placeholder: '',
        mentionCandidates: () => [],
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
    'update:attachments': [files: File[]];
    submit: [];
    typing: [];
    voice: [file: File];
}>();

const draft = computed({
    get: () => props.modelValue,
    set: (v: string) => emit('update:modelValue', v),
});

const pendingFiles = computed({
    get: () => props.attachments,
    set: (files: File[]) => emit('update:attachments', files),
});

const hasText = computed(() => draft.value.trim().length > 0);
const canSend = computed(() => hasText.value || pendingFiles.value.length > 0);
const effectivePlaceholder = computed(() =>
    props.placeholder !== '' ? props.placeholder : t('organization.messagePlaceholder'),
);
const mentionPlaceholder = computed(() => t('organization.messageWithMention'));

const showAttach = ref(false);
const showEmoji = ref(false);
const mediaInput = ref<HTMLInputElement | null>(null);
const docInput = ref<HTMLInputElement | null>(null);
const textareaRef = ref<HTMLTextAreaElement | null>(null);

const mentionOpen = ref(false);
const mentionQuery = ref('');
const mentionActiveIndex = ref(0);

const filteredMentionCandidates = computed(() => {
    const list = props.mentionCandidates.filter((p) => p.name.trim() !== '');
    const q = mentionQuery.value.trim().toLowerCase();
    if (!q) {
        return list.slice(0, 12);
    }
    const matched = list.filter((p) => {
        const name = p.name.toLowerCase();
        return name.startsWith(q) || name.includes(q);
    });
    return (matched.length > 0 ? matched : list).slice(0, 12);
});

watch(
    () => [mentionOpen.value, mentionQuery.value, filteredMentionCandidates.value.length] as const,
    () => {
        mentionActiveIndex.value = 0;
    },
);

const recording = ref(false);
const recordingTime = ref(0);
const mediaRecorder = ref<MediaRecorder | null>(null);
const recordStream = ref<MediaStream | null>(null);
const recordedChunks = ref<Blob[]>([]);
let recordInterval: ReturnType<typeof setInterval> | null = null;
let recordingCancelled = false;

function toggleAttach(): void {
    showAttach.value = !showAttach.value;
    showEmoji.value = false;
}

function toggleEmoji(): void {
    showEmoji.value = !showEmoji.value;
    showAttach.value = false;
}

function pickPhotoVideo(): void {
    showAttach.value = false;
    mediaInput.value?.click();
}

function pickDocument(): void {
    showAttach.value = false;
    docInput.value?.click();
}

function onMediaSelected(e: Event): void {
    const t = e.target as HTMLInputElement;
    const files = Array.from(t.files ?? []);
    t.value = '';
    if (!files.length) return;
    const max = 5;
    pendingFiles.value = [...pendingFiles.value, ...files].slice(0, max);
    emit('typing');
}

function onDocSelected(e: Event): void {
    const t = e.target as HTMLInputElement;
    const file = t.files?.[0];
    t.value = '';
    if (!file) return;
    const max = 5;
    pendingFiles.value = [...pendingFiles.value, file].slice(0, max);
    emit('typing');
}

function removePendingAttachment(index: number): void {
    pendingFiles.value = pendingFiles.value.filter((_, i) => i !== index);
}

function insertEmoji(emoji: string): void {
    const el = textareaRef.value;
    if (!el) {
        draft.value += emoji;
        emit('typing');
        return;
    }
    const start = el.selectionStart ?? draft.value.length;
    const end = el.selectionEnd ?? start;
    const next = draft.value.slice(0, start) + emoji + draft.value.slice(end);
    draft.value = next;
    emit('typing');
    requestAnimationFrame(() => {
        const pos = start + emoji.length;
        el.focus();
        el.setSelectionRange(pos, pos);
    });
}

function updateMentionStateFromCaret(): void {
    if (props.mentionCandidates.length === 0) {
        mentionOpen.value = false;
        mentionQuery.value = '';
        return;
    }
    const el = textareaRef.value;
    const text = draft.value;
    const pos = el?.selectionStart ?? text.length;
    const left = text.slice(0, pos).replace(/\s+$/g, '');
    const match = left.match(/(^|\s)@([^\s@]*)$/);
    if (!match) {
        mentionOpen.value = false;
        mentionQuery.value = '';
        return;
    }
    mentionOpen.value = true;
    mentionQuery.value = match[2] ?? '';
}

function applyMention(candidate: TeamMentionCandidate): void {
    const el = textareaRef.value;
    const name = candidate.name.trim();
    if (!el || !name) {
        return;
    }

    const text = draft.value;
    const pos = el.selectionStart ?? text.length;
    const left = text.slice(0, pos);
    const match = left.match(/(^|\s)@([^\s@]*)$/);
    const atIndex = match ? left.lastIndexOf('@') : pos;
    const insert = `@${name} `;
    const newText = `${text.slice(0, atIndex)}${insert}${text.slice(pos)}`;
    draft.value = newText;
    mentionOpen.value = false;
    mentionQuery.value = '';
    emit('typing');

    const newPos = atIndex + insert.length;
    nextTick(() => {
        el.focus();
        el.setSelectionRange(newPos, newPos);
        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, 120)}px`;
    });
}

function onInput(): void {
    emit('typing');
    const el = textareaRef.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 120)}px`;
    updateMentionStateFromCaret();
}

function onKeydown(e: KeyboardEvent): void {
    if (mentionOpen.value && filteredMentionCandidates.value.length > 0) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            mentionActiveIndex.value = Math.min(
                filteredMentionCandidates.value.length - 1,
                mentionActiveIndex.value + 1,
            );
            return;
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            mentionActiveIndex.value = Math.max(0, mentionActiveIndex.value - 1);
            return;
        }
        if (e.key === 'Tab') {
            e.preventDefault();
            const max = filteredMentionCandidates.value.length - 1;
            if (e.shiftKey) {
                mentionActiveIndex.value = mentionActiveIndex.value <= 0 ? max : mentionActiveIndex.value - 1;
            } else {
                mentionActiveIndex.value = mentionActiveIndex.value >= max ? 0 : mentionActiveIndex.value + 1;
            }
            return;
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            const picked = filteredMentionCandidates.value[mentionActiveIndex.value];
            if (picked) {
                applyMention(picked);
            }
            return;
        }
        if (e.key === 'Escape') {
            e.preventDefault();
            mentionOpen.value = false;
            mentionQuery.value = '';
            return;
        }
    }

    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        trySubmit();
    }
}

function onClick(): void {
    updateMentionStateFromCaret();
}

function trySubmit(): void {
    if (props.disabled || !canSend.value) return;
    emit('submit');
}

function formatRecordTime(sec: number): string {
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return `${m}:${s.toString().padStart(2, '0')}`;
}

async function startRecording(): Promise<void> {
    if (props.disabled || recording.value) return;
    showAttach.value = false;
    showEmoji.value = false;
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        recordStream.value = stream;
        recordedChunks.value = [];
        recordingCancelled = false;

        const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
            ? 'audio/webm;codecs=opus'
            : MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')
              ? 'audio/ogg;codecs=opus'
              : '';
        const rec = mimeType ? new MediaRecorder(stream, { mimeType }) : new MediaRecorder(stream);
        mediaRecorder.value = rec;

        rec.ondataavailable = (e: BlobEvent) => {
            if (e.data.size > 0) recordedChunks.value.push(e.data);
        };

        rec.onstop = () => {
            stream.getTracks().forEach((t) => t.stop());
            recordStream.value = null;
            if (recordingCancelled) {
                recordedChunks.value = [];
                return;
            }
            const blob = new Blob(recordedChunks.value, { type: rec.mimeType || 'audio/webm' });
            const ext = blob.type.includes('ogg') ? 'ogg' : 'webm';
            const file = new File([blob], `voice-${Date.now()}.${ext}`, { type: blob.type });
            emit('voice', file);
        };

        rec.start();
        recording.value = true;
        recordingTime.value = 0;
        recordInterval = setInterval(() => recordingTime.value++, 1000);
    } catch {
        showToast({ message: t('organization.micDenied'), type: 'warning' });
    }
}

function stopRecording(): void {
    if (recordInterval) {
        clearInterval(recordInterval);
        recordInterval = null;
    }
    recording.value = false;
    mediaRecorder.value?.stop();
}

function cancelRecording(): void {
    recordingCancelled = true;
    if (recordInterval) {
        clearInterval(recordInterval);
        recordInterval = null;
    }
    recording.value = false;
    if (mediaRecorder.value && mediaRecorder.value.state !== 'inactive') {
        mediaRecorder.value.stop();
    } else {
        recordStream.value?.getTracks().forEach((t) => t.stop());
        recordStream.value = null;
    }
}

onBeforeUnmount(() => {
    if (recording.value) cancelRecording();
});
</script>

<template>
    <div class="relative shrink-0">
        <div v-if="pendingFiles.length" class="px-3 pb-1 flex flex-wrap gap-1.5">
            <span
                v-for="(f, fi) in pendingFiles"
                :key="`${f.name}-${fi}-${f.size}`"
                class="inline-flex items-center gap-1 rounded-full border border-[var(--wa-border)] bg-[var(--wa-panel-header)] pl-2 pr-1 py-0.5 text-xs max-w-full"
            >
                <span class="truncate max-w-[10rem] text-[var(--wa-text)]">{{ f.name }}</span>
                <button
                    type="button"
                    class="shrink-0 rounded-full px-1 opacity-60 hover:opacity-100"
                    :aria-label="t('organization.removeAttachment', { name: f.name })"
                    :disabled="disabled"
                    @click="removePendingAttachment(fi)"
                >×</button>
            </span>
        </div>

        <div class="wa-input-bar">
            <template v-if="recording">
                <button type="button" class="wa-input-btn text-red-500" :title="t('organization.cancelRecording')" @click="cancelRecording">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M8 7V4a1 1 0 011-1h6a1 1 0 011 1v3" />
                    </svg>
                </button>
                <div class="wa-input-pill flex-1 flex items-center gap-3 px-3">
                    <span class="w-3 h-3 rounded-full bg-red-500 animate-pulse" />
                    <span class="text-sm text-[var(--wa-text)]">{{ t('organization.recording', { time: formatRecordTime(recordingTime) }) }}</span>
                </div>
                <button type="button" class="wa-input-btn" :title="t('organization.sendComment')" @click="stopRecording">
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24" :style="{ color: 'var(--wa-accent)' }">
                        <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" />
                    </svg>
                </button>
            </template>

            <template v-else>
                <div class="wa-input-attach">
                    <button
                        type="button"
                        class="wa-input-btn"
                        :class="{ 'wa-input-btn-active': showAttach }"
                        :title="t('organization.attachFile')"
                        :disabled="disabled"
                        @click="toggleAttach"
                    >
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                        </svg>
                    </button>

                    <transition name="attach">
                        <div
                            v-if="showAttach"
                            class="absolute bottom-full left-0 mb-2 w-[220px] rounded-lg shadow-2xl border py-1.5 attach-menu"
                            :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-control-rim)', boxShadow: 'var(--wa-control-rim-shadow)' }"
                        >
                            <button class="attach-item" type="button" @click="pickPhotoVideo">
                                <span class="attach-icon" style="background: #bf59cf;">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                </span>
                                {{ t('organization.attachPhotoVideo') }}
                            </button>
                            <button class="attach-item" type="button" @click="pickDocument">
                                <span class="attach-icon" style="background: #5f66cd;">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                </span>
                                {{ t('organization.attachDocument') }}
                            </button>
                        </div>
                    </transition>
                </div>

                <input ref="mediaInput" type="file" accept="image/*,video/*" class="hidden" multiple @change="onMediaSelected" />
                <input ref="docInput" type="file" class="hidden" multiple @change="onDocSelected" />

                <button
                    type="button"
                    class="wa-input-btn"
                    :class="{ 'wa-input-btn-active': showEmoji }"
                    :title="t('organization.emoji')"
                    :disabled="disabled"
                    @click="toggleEmoji"
                >
                    <svg class="w-6 h-6 block" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </button>

                <div class="wa-input-pill relative">
                    <div
                        v-if="mentionOpen"
                        class="team-mention-menu"
                    >
                        <button
                            v-for="(p, idx) in filteredMentionCandidates"
                            :key="p.id"
                            type="button"
                            class="team-mention-menu__item"
                            :class="{ 'is-active': mentionActiveIndex === idx }"
                            @mousedown.prevent
                            @click="applyMention(p)"
                        >
                            <span class="team-mention-menu__at">@</span>{{ p.name }}
                        </button>
                        <p
                            v-if="filteredMentionCandidates.length === 0"
                            class="team-mention-menu__empty"
                        >
                            {{ t('organization.noMentionMatches') }}
                        </p>
                    </div>
                    <textarea
                        ref="textareaRef"
                        v-model="draft"
                        rows="1"
                        class="wa-composer-field wa-scrollbar"
                        :placeholder="mentionCandidates.length ? mentionPlaceholder : effectivePlaceholder"
                        :disabled="disabled"
                        @input="onInput"
                        @keydown="onKeydown"
                        @click="onClick"
                    />
                </div>

                <EmojiPicker v-if="showEmoji" class="z-50" @select="insertEmoji" @close="showEmoji = false" />

                <button
                    v-if="canSend"
                    type="button"
                    class="wa-input-btn"
                    :title="t('organization.sendComment')"
                    :disabled="disabled"
                    @click="trySubmit"
                >
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" />
                    </svg>
                </button>
                <button
                    v-else
                    type="button"
                    class="wa-input-btn"
                    :title="t('organization.voiceMessage')"
                    :disabled="disabled"
                    @click="startRecording"
                >
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5-3c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z" />
                    </svg>
                </button>
            </template>
        </div>
    </div>
</template>

<style scoped>
.team-mention-menu {
    position: absolute;
    left: 0;
    right: 0;
    bottom: calc(100% + 6px);
    z-index: 50;
    max-height: 220px;
    overflow-y: auto;
    border-radius: 0.5rem;
    border: 1px solid var(--wa-control-border, var(--wa-border));
    background: var(--wa-panel);
    box-shadow: var(--wa-control-rim-shadow, 0 8px 24px rgba(0, 0, 0, 0.28));
}

.team-mention-menu__item {
    display: block;
    width: 100%;
    padding: 0.5rem 0.75rem;
    text-align: left;
    font-size: 0.8125rem;
    color: var(--wa-text);
    background: transparent;
    border: 0;
    cursor: pointer;
}

.team-mention-menu__item:hover,
.team-mention-menu__item.is-active {
    background: var(--wa-selected);
}

.team-mention-menu__at {
    color: var(--wa-accent);
    font-weight: 600;
}

.team-mention-menu__empty {
    margin: 0;
    padding: 0.5rem 0.75rem;
    font-size: 0.75rem;
    color: var(--wa-text-secondary);
}
</style>
