<script setup lang="ts">
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import axios from 'axios';
import type { Message } from '@/types';
import EmojiPicker from './EmojiPicker.vue';

const props = defineProps<{
    chatId: number;
    replyTo?: Message | null;
}>();

const emit = defineEmits<{
    (e: 'messageSent', message: any): void;
    (e: 'cancelReply'): void;
}>();

const messageText = ref('');
const isSending = ref(false);
const textareaRef = ref<HTMLTextAreaElement | null>(null);

const mediaInput = ref<HTMLInputElement | null>(null);
const docInput = ref<HTMLInputElement | null>(null);
const stickerInput = ref<HTMLInputElement | null>(null);

const showEmoji = ref(false);
const showAttach = ref(false);

let typingTimeout: ReturnType<typeof setTimeout>;

const hasText = computed(() => messageText.value.trim().length > 0);

watch(() => props.replyTo, (val) => {
    if (val) nextTick(() => textareaRef.value?.focus());
});

function replyPreviewText(msg: Message): string {
    if (msg.body) return msg.body;
    if (msg.media?.length) return '[Медиа]';
    return '[Сообщение]';
}

function replyAuthor(msg: Message): string {
    if (msg.direction === 'outbound') return msg.sent_by_user?.name || 'Вы';
    return msg.sender_name || msg.sender_phone || 'Контакт';
}

async function sendMessage() {
    const text = messageText.value.trim();
    if (!text || isSending.value) return;

    isSending.value = true;
    const prevText = text;
    messageText.value = '';
    autoResize();

    try {
        const payload: Record<string, unknown> = { message: text };
        if (props.replyTo?.whatsapp_message_id) {
            payload.quoted_message_id = props.replyTo.whatsapp_message_id;
        }
        const { data } = await axios.post(route('chats.send-message', props.chatId), payload);
        if (data.message) {
            emit('messageSent', data.message);
            emit('cancelReply');
        }
    } catch (err) {
        console.error('Send failed:', err);
        messageText.value = prevText;
    } finally {
        isSending.value = false;
    }
}

function handleKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
}

function onInput() {
    autoResize();
    clearTimeout(typingTimeout);
    typingTimeout = setTimeout(() => {
        axios.post(route('chats.typing', props.chatId)).catch(() => {});
    }, 500);
}

function autoResize() {
    const el = textareaRef.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
}

function insertEmoji(emoji: string) {
    const el = textareaRef.value;
    if (!el) {
        messageText.value += emoji;
        return;
    }
    const start = el.selectionStart ?? messageText.value.length;
    const end = el.selectionEnd ?? messageText.value.length;
    messageText.value = messageText.value.slice(0, start) + emoji + messageText.value.slice(end);
    nextTick(() => {
        el.focus();
        const pos = start + emoji.length;
        el.setSelectionRange(pos, pos);
        autoResize();
    });
}

function toggleAttach() {
    showAttach.value = !showAttach.value;
    showEmoji.value = false;
}

function toggleEmoji() {
    showEmoji.value = !showEmoji.value;
    showAttach.value = false;
}

function pickPhotoVideo() {
    showAttach.value = false;
    mediaInput.value?.click();
}

function pickDocument() {
    showAttach.value = false;
    docInput.value?.click();
}

function pickSticker() {
    showAttach.value = false;
    stickerInput.value?.click();
}

function stubAction(name: string) {
    showAttach.value = false;
    alert(`«${name}» — скоро будет доступно.`);
}

async function uploadFile(file: File, type?: string) {
    const formData = new FormData();
    formData.append('file', file);
    if (type) formData.append('type', type);

    try {
        const { data } = await axios.post(route('chats.upload-file', props.chatId), formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (data.message) emit('messageSent', data.message);
    } catch (err) {
        console.error('Upload failed:', err);
        alert('Не удалось загрузить файл');
    }
}

async function onMediaSelected(e: Event) {
    const t = e.target as HTMLInputElement;
    const file = t.files?.[0];
    if (!file) return;
    const type = file.type.startsWith('video/') ? 'video'
        : file.type === 'image/gif' ? 'gif'
        : 'image';
    await uploadFile(file, type);
    t.value = '';
}

async function onDocSelected(e: Event) {
    const t = e.target as HTMLInputElement;
    const file = t.files?.[0];
    if (!file) return;
    await uploadFile(file, 'document');
    t.value = '';
}

async function onStickerSelected(e: Event) {
    const t = e.target as HTMLInputElement;
    const file = t.files?.[0];
    if (!file) return;
    await uploadFile(file, 'sticker');
    t.value = '';
}

// ===== Voice recording =====
const recording = ref(false);
const recordingTime = ref(0);
const mediaRecorder = ref<MediaRecorder | null>(null);
const recordedChunks = ref<Blob[]>([]);
const recordStream = ref<MediaStream | null>(null);
let recordInterval: ReturnType<typeof setInterval> | null = null;
let recordingCancelled = false;

function formatRecordTime(sec: number): string {
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return `${m}:${s.toString().padStart(2, '0')}`;
}

async function startRecording() {
    if (!navigator.mediaDevices?.getUserMedia) {
        alert('Запись голосовых недоступна в этом браузере');
        return;
    }
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        recordStream.value = stream;
        recordedChunks.value = [];
        recordingCancelled = false;

        const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
            ? 'audio/webm;codecs=opus'
            : (MediaRecorder.isTypeSupported('audio/ogg;codecs=opus') ? 'audio/ogg;codecs=opus' : '');
        const rec = mimeType ? new MediaRecorder(stream, { mimeType }) : new MediaRecorder(stream);
        mediaRecorder.value = rec;

        rec.ondataavailable = (e) => {
            if (e.data.size > 0) recordedChunks.value.push(e.data);
        };
        rec.onstop = async () => {
            stream.getTracks().forEach((t) => t.stop());
            recordStream.value = null;
            if (recordingCancelled) {
                recordedChunks.value = [];
                return;
            }
            const blob = new Blob(recordedChunks.value, { type: rec.mimeType || 'audio/webm' });
            const ext = (rec.mimeType || 'audio/webm').includes('ogg') ? 'ogg' : 'webm';
            const file = new File([blob], `voice-${Date.now()}.${ext}`, { type: blob.type });
            await uploadFile(file, 'voice');
        };

        rec.start();
        recording.value = true;
        recordingTime.value = 0;
        recordInterval = setInterval(() => recordingTime.value++, 1000);
    } catch (err) {
        console.error('Mic error:', err);
        alert('Нет доступа к микрофону');
    }
}

function stopRecording() {
    if (recordInterval) { clearInterval(recordInterval); recordInterval = null; }
    recording.value = false;
    mediaRecorder.value?.stop();
}

function cancelRecording() {
    recordingCancelled = true;
    if (recordInterval) { clearInterval(recordInterval); recordInterval = null; }
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
    <div class="shrink-0 relative">
        <!-- Reply preview -->
        <div
            v-if="replyTo"
            class="px-4 pt-2 pb-1 flex items-stretch gap-2 border-t"
            :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border)' }"
        >
            <div
                class="flex-1 flex items-center gap-3 rounded-md pl-2 pr-3 py-2 border-l-4"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-accent)' }"
            >
                <div class="flex-1 min-w-0">
                    <div class="text-xs font-medium" :style="{ color: 'var(--wa-accent)' }">
                        {{ replyAuthor(replyTo) }}
                    </div>
                    <div class="text-sm truncate" :style="{ color: 'var(--wa-text-secondary)' }">
                        {{ replyPreviewText(replyTo) }}
                    </div>
                </div>
                <button
                    @click="emit('cancelReply')"
                    class="w-7 h-7 flex items-center justify-center rounded-full hover:bg-[var(--wa-panel-hover)]"
                    type="button"
                    title="Отменить"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Main input bar -->
        <div class="bg-[var(--wa-panel-header)] px-4 py-3 flex items-end gap-2">
            <!-- Recording state -->
            <template v-if="recording">
                <button @click="cancelRecording" class="wa-input-btn text-red-500" title="Отменить">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M8 7V4a1 1 0 011-1h6a1 1 0 011 1v3" />
                    </svg>
                </button>
                <div class="flex-1 flex items-center gap-3 px-3 py-2 rounded-lg wa-shadow" :style="{ background: 'var(--wa-panel-input)' }">
                    <span class="w-3 h-3 rounded-full bg-red-500 animate-pulse"></span>
                    <span class="text-sm" :style="{ color: 'var(--wa-text)' }">Запись… {{ formatRecordTime(recordingTime) }}</span>
                    <div class="flex-1"></div>
                    <span class="text-xs" :style="{ color: 'var(--wa-text-secondary)' }">Отпустите «✕» чтобы отменить</span>
                </div>
                <button @click="stopRecording" class="wa-input-btn" title="Отправить">
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24" :style="{ color: 'var(--wa-accent)' }">
                        <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" />
                    </svg>
                </button>
            </template>

            <!-- Normal input -->
            <template v-else>
                <!-- Emoji button -->
                <button
                    data-emoji-trigger
                    @click="toggleEmoji"
                    class="wa-input-btn"
                    :class="{ 'wa-input-btn-active': showEmoji }"
                    title="Эмодзи"
                    type="button"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </button>

                <!-- Attach button -->
                <div class="relative">
                    <button
                        @click="toggleAttach"
                        class="wa-input-btn"
                        :class="{ 'wa-input-btn-active': showAttach }"
                        title="Прикрепить"
                        type="button"
                    >
                        <svg class="w-6 h-6 transition-transform" :class="{ 'rotate-45': showAttach }" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                        </svg>
                    </button>

                    <!-- Attach menu -->
                    <transition name="attach">
                        <div
                            v-if="showAttach"
                            class="absolute bottom-full left-0 mb-2 w-[220px] rounded-lg shadow-2xl border py-1.5 attach-menu"
                            :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border-strong)' }"
                        >
                            <button class="attach-item" @click="pickPhotoVideo" type="button">
                                <span class="attach-icon" style="background: #bf59cf;">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                </span>
                                Фото или видео
                            </button>
                            <button class="attach-item" @click="stubAction('Камера')" type="button">
                                <span class="attach-icon" style="background: #ff2e74;">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                </span>
                                Камера
                            </button>
                            <button class="attach-item" @click="pickDocument" type="button">
                                <span class="attach-icon" style="background: #5f66cd;">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                </span>
                                Документ
                            </button>
                            <button class="attach-item" @click="pickSticker" type="button">
                                <span class="attach-icon" style="background: #02a698;">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14 2l6 6v10a4 4 0 01-4 4H8a4 4 0 01-4-4V6a4 4 0 014-4h6zm0 0v6h6" />
                                    </svg>
                                </span>
                                Стикер
                            </button>
                            <button class="attach-item" @click="stubAction('Контакт')" type="button">
                                <span class="attach-icon" style="background: #0099ff;">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>
                                </span>
                                Контакт
                            </button>
                            <button class="attach-item" @click="stubAction('Опрос')" type="button">
                                <span class="attach-icon" style="background: #ffa115;">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-6m4 6V7m4 10v-4" />
                                    </svg>
                                </span>
                                Опрос
                            </button>
                        </div>
                    </transition>
                </div>

                <input ref="mediaInput" type="file" accept="image/*,video/*" class="hidden" @change="onMediaSelected" />
                <input ref="docInput" type="file" class="hidden" @change="onDocSelected" />
                <input ref="stickerInput" type="file" accept="image/webp,image/png,image/gif" class="hidden" @change="onStickerSelected" />

                <div class="flex-1 relative">
                    <textarea
                        ref="textareaRef"
                        v-model="messageText"
                        @keydown="handleKeydown"
                        @input="onInput"
                        rows="1"
                        placeholder="Введите сообщение"
                        class="w-full px-4 py-[9px] bg-[var(--wa-panel-input)] rounded-lg text-[15px] text-[var(--wa-text)] border-0 focus:ring-0 focus:outline-none resize-none max-h-[120px] overflow-y-auto wa-scrollbar leading-5 wa-shadow"
                        style="min-height: 42px;"
                    ></textarea>

                    <!-- Emoji picker -->
                    <EmojiPicker
                        v-if="showEmoji"
                        @select="insertEmoji"
                        @close="showEmoji = false"
                    />
                </div>

                <button
                    v-if="hasText"
                    @click="sendMessage"
                    :disabled="isSending"
                    class="wa-input-btn"
                    title="Отправить"
                    type="button"
                >
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                    </svg>
                </button>
                <button
                    v-else
                    @click="startRecording"
                    class="wa-input-btn"
                    title="Голосовое сообщение"
                    type="button"
                >
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5-3c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/>
                    </svg>
                </button>
            </template>
        </div>
    </div>
</template>

<style scoped>
.wa-input-btn {
    padding: 0.5rem;
    color: var(--wa-icon);
    transition: color 0.15s ease;
    flex-shrink: 0;
}
.wa-input-btn:hover { color: var(--wa-text); }
.wa-input-btn:disabled { opacity: 0.5; }
.wa-input-btn-active { color: var(--wa-accent); }

.attach-menu {
    animation: picker-pop 0.14s ease-out;
    z-index: 50;
}
@keyframes picker-pop {
    from { opacity: 0; transform: translateY(6px) scale(0.98); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
.attach-enter-active, .attach-leave-active { transition: opacity 0.15s ease, transform 0.15s ease; }
.attach-enter-from, .attach-leave-to { opacity: 0; transform: translateY(8px); }

.attach-item {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    width: 100%;
    padding: 0.5rem 0.875rem;
    font-size: 0.875rem;
    color: var(--wa-text);
    text-align: left;
    transition: background-color 0.12s ease;
}
.attach-item:hover {
    background-color: var(--wa-panel-hover);
}
.attach-icon {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    flex-shrink: 0;
}
</style>
