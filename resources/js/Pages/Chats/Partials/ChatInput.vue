<script setup lang="ts">
import { ref, computed, watch, nextTick, onBeforeUnmount, onMounted } from 'vue';
import axios from 'axios';
import type { Message } from '@/types';
import EmojiPicker from './EmojiPicker.vue';
import { formatPhone } from '@/utils/phone';

const props = defineProps<{
    chatId: number;
    sessionId?: number | null;
    replyTo?: Message | null;
}>();

const emit = defineEmits<{
    (e: 'messageSent', message: any): void;
    (e: 'cancelReply'): void;
}>();

// Plain text that will be sent to backend/WhatsApp.
const messageText = ref('');
const isSending = ref(false);
const editorRef = ref<HTMLDivElement | null>(null);

// Formatting toolbar (like WhatsApp Web)
const formatBarOpen = ref(false);
const formatBarX = ref(0);
const formatBarY = ref(0);

const mediaInput = ref<HTMLInputElement | null>(null);
const docInput = ref<HTMLInputElement | null>(null);
const stickerInput = ref<HTMLInputElement | null>(null);

const showEmoji = ref(false);
const showAttach = ref(false);

let typingTimeout: ReturnType<typeof setTimeout>;

const hasText = computed(() => messageText.value.trim().length > 0);

watch(() => props.replyTo, (val) => {
    if (val) nextTick(() => editorRef.value?.focus());
});

function replyPreviewText(msg: Message): string {
    if (msg.body) return msg.body;
    if (msg.media?.length) return '[Медиа]';
    return '[Сообщение]';
}

function replyAuthor(msg: Message): string {
    if (msg.direction === 'outbound') return msg.sent_by_user?.name || 'Вы';
    return msg.sender_name || formatPhone(msg.sender_phone) || 'Контакт';
}

async function sendMessage() {
    const text = messageText.value.trim();
    if (!text || isSending.value) return;

    isSending.value = true;
    const prevText = text;
    messageText.value = '';
    clearEditor();

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
        // restore editor text for user convenience
        setEditorPlainText(prevText);
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
    syncPlainTextFromEditor();
    autoResizeEditor();
    clearTimeout(typingTimeout);
    typingTimeout = setTimeout(() => {
        axios.post(route('chats.typing', props.chatId)).catch(() => {});
    }, 500);
}

function autoResizeEditor() {
    const el = editorRef.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
}

function insertEmoji(emoji: string) {
    const el = editorRef.value;
    if (!el) return;
    el.focus();
    // Insert emoji at caret position.
    document.execCommand('insertText', false, emoji);
    syncPlainTextFromEditor();
    nextTick(autoResizeEditor);
}

function clearEditor() {
    const el = editorRef.value;
    if (!el) return;
    el.innerHTML = '';
    el.style.height = '';
    formatBarOpen.value = false;
}

function setEditorPlainText(text: string) {
    const el = editorRef.value;
    if (!el) return;
    el.textContent = text;
    syncPlainTextFromEditor();
    nextTick(autoResizeEditor);
}

function syncPlainTextFromEditor() {
    const el = editorRef.value;
    if (!el) return;
    // innerText preserves line breaks similar to textarea behavior.
    messageText.value = (el.innerText || '').replace(/\u00A0/g, ' ');
}

function selectionInsideEditor(): boolean {
    const root = editorRef.value;
    const sel = window.getSelection();
    if (!root || !sel || sel.rangeCount === 0) return false;
    const range = sel.getRangeAt(0);
    const node = range.commonAncestorContainer;
    return root.contains(node);
}

function updateFormatBarFromSelection() {
    const root = editorRef.value;
    const sel = window.getSelection();
    if (!root || !sel || sel.rangeCount === 0) {
        formatBarOpen.value = false;
        return;
    }
    if (!selectionInsideEditor()) {
        formatBarOpen.value = false;
        return;
    }
    const range = sel.getRangeAt(0);
    const rect = range.getBoundingClientRect();
    // If selection is collapsed, keep toolbar hidden (WhatsApp-like).
    if (sel.isCollapsed || rect.width === 0) {
        formatBarOpen.value = false;
        return;
    }
    const vw = window.innerWidth;
    const TOOLBAR_W = 360;
    const x = Math.max(8, Math.min(vw - TOOLBAR_W - 8, rect.left + rect.width / 2 - TOOLBAR_W / 2));
    const y = Math.max(8, rect.top - 48);
    formatBarX.value = x;
    formatBarY.value = y;
    formatBarOpen.value = true;
}

function applyFormat(cmd: string, value?: string) {
    const el = editorRef.value;
    if (!el) return;
    el.focus();
    try {
        // eslint-disable-next-line deprecation/deprecation
        document.execCommand(cmd, false, value);
    } finally {
        syncPlainTextFromEditor();
        nextTick(autoResizeEditor);
        updateFormatBarFromSelection();
    }
}

function onCopy(e: ClipboardEvent) {
    if (!selectionInsideEditor()) return;
    const sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) return;
    const range = sel.getRangeAt(0);
    const container = document.createElement('div');
    container.appendChild(range.cloneContents());
    const html = container.innerHTML;
    const text = sel.toString();
    if (!e.clipboardData) return;
    e.clipboardData.setData('text/html', html);
    e.clipboardData.setData('text/plain', text);
    e.preventDefault();
}

function onPaste(e: ClipboardEvent) {
    // Paste as plain text to avoid bringing external styling into the editor.
    if (!selectionInsideEditor()) return;
    const text = e.clipboardData?.getData('text/plain');
    if (typeof text !== 'string') return;
    e.preventDefault();
    applyFormat('insertText', text);
}

onMounted(() => {
    document.addEventListener('selectionchange', updateFormatBarFromSelection);
});

onBeforeUnmount(() => {
    document.removeEventListener('selectionchange', updateFormatBarFromSelection);
});

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

function detectMediaKind(file: File): 'image' | 'video' | 'gif' {
    if (file.type.startsWith('video/')) return 'video';
    if (file.type === 'image/gif') return 'gif';
    return 'image';
}

function onMediaSelected(e: Event) {
    const t = e.target as HTMLInputElement;
    const files = Array.from(t.files ?? []);
    t.value = '';
    if (!files.length) return;
    addPendingAttachments(files);
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

function onEmojiShortcut() {
    toggleEmoji();
}
window.addEventListener('chatswitch:toggle-emoji', onEmojiShortcut);

onBeforeUnmount(() => {
    if (recording.value) cancelRecording();
    clearPendingAttachments();
    unlockBodyScroll();
    window.removeEventListener('chatswitch:toggle-emoji', onEmojiShortcut);
});

// ===== Attachment preview composer =====
type PendingAttachment = {
    id: string;
    file: File;
    previewUrl: string;
    kind: 'image' | 'video' | 'gif';
    caption: string;
};

const pendingAttachments = ref<PendingAttachment[]>([]);
const activeAttachmentIndex = ref(0);
const attachmentCaptionRef = ref<HTMLTextAreaElement | null>(null);
const isUploadingAttachments = ref(false);

const activeAttachment = computed<PendingAttachment | null>(() =>
    pendingAttachments.value[activeAttachmentIndex.value] ?? null,
);
const activeAttachmentCaption = computed({
    get: () => activeAttachment.value?.caption ?? '',
    set: (value: string) => {
        const attachment = activeAttachment.value;
        if (attachment) {
            attachment.caption = value;
        }
    },
});
const showAttachmentPreview = computed(() => pendingAttachments.value.length > 0);

function addPendingAttachments(files: File[]) {
    const next = files.map<PendingAttachment>((file) => ({
        id: `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`,
        file,
        previewUrl: URL.createObjectURL(file),
        kind: detectMediaKind(file),
        caption: '',
    }));
    const firstNewIndex = pendingAttachments.value.length;
    pendingAttachments.value = [...pendingAttachments.value, ...next];
    activeAttachmentIndex.value = firstNewIndex;
    nextTick(() => attachmentCaptionRef.value?.focus());
}

function removePendingAttachment(index: number) {
    const item = pendingAttachments.value[index];
    if (!item) return;
    URL.revokeObjectURL(item.previewUrl);
    pendingAttachments.value.splice(index, 1);
    if (!pendingAttachments.value.length) {
        closeAttachmentPreview();
        return;
    }
    if (activeAttachmentIndex.value >= pendingAttachments.value.length) {
        activeAttachmentIndex.value = pendingAttachments.value.length - 1;
    }
}

function selectAttachment(index: number) {
    if (index < 0 || index >= pendingAttachments.value.length) return;
    activeAttachmentIndex.value = index;
}

function clearPendingAttachments() {
    pendingAttachments.value.forEach((a) => URL.revokeObjectURL(a.previewUrl));
    pendingAttachments.value = [];
    activeAttachmentIndex.value = 0;
}

function closeAttachmentPreview() {
    if (isUploadingAttachments.value) return;
    clearPendingAttachments();
}

function addMoreAttachments() {
    mediaInput.value?.click();
}

async function confirmSendAttachments() {
    if (!pendingAttachments.value.length || isUploadingAttachments.value) return;

    isUploadingAttachments.value = true;
    const items = [...pendingAttachments.value];

    try {
        for (let i = 0; i < items.length; i++) {
            const att = items[i];
            const formData = new FormData();
            formData.append('file', att.file);
            formData.append('type', att.kind);
            const caption = att.caption.trim();
            if (caption) formData.append('caption', caption);

            const { data } = await axios.post(
                route('chats.upload-file', props.chatId),
                formData,
                { headers: { 'Content-Type': 'multipart/form-data' } },
            );
            if (data.message) emit('messageSent', data.message);
        }
        clearPendingAttachments();
    } catch (err) {
        console.error('Upload failed:', err);
        alert('Не удалось загрузить файл');
    } finally {
        isUploadingAttachments.value = false;
    }
}

function onPreviewKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape') {
        e.preventDefault();
        closeAttachmentPreview();
    }
}

function onCaptionKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        confirmSendAttachments();
    }
}

let previousBodyOverflow = '';
function lockBodyScroll() {
    if (typeof document === 'undefined') return;
    previousBodyOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
}
function unlockBodyScroll() {
    if (typeof document === 'undefined') return;
    document.body.style.overflow = previousBodyOverflow;
}

// ===== Share contact =====
type ContactListItem = {
    id: number;
    whatsapp_id: string | null;
    phone_number: string | null;
    name: string | null;
    push_name: string | null;
    profile_picture_url: string | null;
};

const showContactPicker = ref(false);
const contactPickerLoading = ref(false);
const contactPickerSearch = ref('');
const contactPickerList = ref<ContactListItem[]>([]);
const pendingContact = ref<ContactListItem | null>(null);
const isSendingContact = ref(false);
let contactSearchTimer: ReturnType<typeof setTimeout> | null = null;

const contactPickerOpen = computed(
    () => showContactPicker.value || pendingContact.value !== null,
);

function contactDisplayName(c: ContactListItem): string {
    return (c.name || c.push_name || formatPhone(c.phone_number) || formatPhone(c.whatsapp_id) || 'Контакт').toString();
}

function contactDisplayPhone(c: ContactListItem): string {
    return formatPhone(c.phone_number || c.whatsapp_id || '');
}

async function loadContactList(q = '') {
    contactPickerLoading.value = true;
    try {
        const { data } = await axios.get(route('chats.contacts'), {
            params: { search: q || undefined },
        });
        contactPickerList.value = (data.contacts || []) as ContactListItem[];
    } catch (err) {
        console.error('Load contacts failed:', err);
        contactPickerList.value = [];
    } finally {
        contactPickerLoading.value = false;
    }
}

function openContactPicker() {
    showAttach.value = false;
    showContactPicker.value = true;
    contactPickerSearch.value = '';
    loadContactList('');
}

function closeContactPicker() {
    if (isSendingContact.value) return;
    showContactPicker.value = false;
    pendingContact.value = null;
    if (contactSearchTimer) {
        clearTimeout(contactSearchTimer);
        contactSearchTimer = null;
    }
}

function pickContact(contact: ContactListItem) {
    if (!contact.phone_number && !contact.whatsapp_id) {
        alert('У этого контакта нет номера телефона и его нельзя отправить.');
        return;
    }
    pendingContact.value = contact;
    showContactPicker.value = false;
}

function backToContactList() {
    pendingContact.value = null;
    showContactPicker.value = true;
}

watch(contactPickerSearch, (val) => {
    if (!showContactPicker.value) return;
    if (contactSearchTimer) clearTimeout(contactSearchTimer);
    contactSearchTimer = setTimeout(() => loadContactList(val), 250);
});

async function confirmSendContact() {
    const contact = pendingContact.value;
    if (!contact || isSendingContact.value) return;

    const phone = (contact.phone_number || contact.whatsapp_id || '').toString();
    if (!phone.replace(/\D/g, '')) {
        alert('У контакта не указан номер — отправка невозможна.');
        return;
    }

    isSendingContact.value = true;
    try {
        const payload = {
            contact_id: contact.id,
            name: contactDisplayName(contact),
            phone,
            avatar_url: contact.profile_picture_url,
        };
        const { data } = await axios.post(
            route('chats.send-contact', props.chatId),
            payload,
        );
        if (data.message) emit('messageSent', data.message);
        pendingContact.value = null;
        showContactPicker.value = false;
    } catch (err) {
        console.error('Send contact failed:', err);
        alert('Не удалось отправить контакт.');
    } finally {
        isSendingContact.value = false;
    }
}

// ===== Poll composer =====
const showPollModal = ref(false);
const isSendingPoll = ref(false);
const pollQuestion = ref('');
const pollOptions = ref<string[]>(['', '']);
const pollAllowMultiple = ref(false);

const pollCanSubmit = computed(() => {
    if (!pollQuestion.value.trim()) return false;
    const filled = pollOptions.value.map((o) => o.trim()).filter((o) => o.length > 0);
    return filled.length >= 2;
});

function openPollModal() {
    showAttach.value = false;
    pollQuestion.value = '';
    pollOptions.value = ['', ''];
    pollAllowMultiple.value = false;
    showPollModal.value = true;
}

function closePollModal() {
    if (isSendingPoll.value) return;
    showPollModal.value = false;
}

function addPollOption() {
    if (pollOptions.value.length >= 12) return;
    pollOptions.value.push('');
}

function removePollOption(index: number) {
    if (pollOptions.value.length <= 2) return;
    pollOptions.value.splice(index, 1);
}

async function submitPoll() {
    if (!pollCanSubmit.value || isSendingPoll.value) return;

    const question = pollQuestion.value.trim();
    const options = pollOptions.value
        .map((o) => o.trim())
        .filter((o) => o.length > 0);

    if (options.length < 2) return;

    isSendingPoll.value = true;
    try {
        const { data } = await axios.post(route('chats.send-poll', props.chatId), {
            question,
            options,
            allow_multiple_answers: pollAllowMultiple.value,
        });
        if (data.message) emit('messageSent', data.message);
        showPollModal.value = false;
    } catch (err) {
        console.error('Send poll failed:', err);
        alert('Не удалось создать опрос.');
    } finally {
        isSendingPoll.value = false;
    }
}

const anyOverlayOpen = computed(
    () => showAttachmentPreview.value || contactPickerOpen.value || showPollModal.value,
);
watch(anyOverlayOpen, (open) => {
    if (open) lockBodyScroll();
    else unlockBodyScroll();
});
</script>

<template>
    <!-- No WhatsApp session: number was deleted -->
    <div
        v-if="props.sessionId === null || props.sessionId === undefined"
        class="shrink-0 flex items-center justify-center gap-2 px-4 py-3 border-t text-sm"
        :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border)', color: 'var(--wa-text-secondary)' }"
    >
        <svg class="w-4 h-4 shrink-0 opacity-70" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 5.636l-12.728 12.728M5.636 5.636l12.728 12.728"/>
        </svg>
        <span>Номер WhatsApp отключён. Подключите новый номер в</span>
        <a :href="route('settings.connections')" class="underline font-medium" style="color:var(--wa-accent)">настройках</a>.
    </div>

    <div v-else class="relative shrink-0">
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
        <div class="wa-input-bar">
            <!-- Recording state -->
            <template v-if="recording">
                <button @click="cancelRecording" class="wa-input-btn text-red-500" title="Отменить">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M8 7V4a1 1 0 011-1h6a1 1 0 011 1v3" />
                    </svg>
                </button>
                <div class="wa-input-pill flex-1 flex items-center gap-3 px-3">
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
                <!-- Plus / attach button -->
                <div class="wa-input-attach">
                    <button
                        @click="toggleAttach"
                        class="wa-input-btn"
                        :class="{ 'wa-input-btn-active': showAttach }"
                        title="Прикрепить"
                        type="button"
                    >
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
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
                            <button class="attach-item" @click="openContactPicker" type="button">
                                <span class="attach-icon" style="background: #0099ff;">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>
                                </span>
                                Контакт
                            </button>
                            <button class="attach-item" @click="openPollModal" type="button">
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

                <input ref="mediaInput" type="file" accept="image/*,video/*" class="hidden" multiple @change="onMediaSelected" />
                <input ref="docInput" type="file" class="hidden" @change="onDocSelected" />
                <input ref="stickerInput" type="file" accept="image/webp,image/png,image/gif" class="hidden" @change="onStickerSelected" />

                <!-- Emoji button (outside input pill) -->
                <button
                    data-emoji-trigger
                    @click="toggleEmoji"
                    class="wa-input-btn"
                    :class="{ 'wa-input-btn-active': showEmoji }"
                    title="Эмодзи"
                    type="button"
                >
                    <svg class="w-6 h-6 block" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </button>

                <div class="wa-input-pill flex-1">
                    <div
                        ref="editorRef"
                        class="wa-rich-editor wa-scrollbar"
                        contenteditable="true"
                        role="textbox"
                        aria-multiline="true"
                        data-placeholder="Введите сообщение"
                        @keydown="handleKeydown"
                        @input="onInput"
                        @copy="onCopy"
                        @paste="onPaste"
                    ></div>
                </div>

                <EmojiPicker
                    v-if="showEmoji"
                    class="z-50"
                    @select="insertEmoji"
                    @close="showEmoji = false"
                />

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

        <!-- Text formatting toolbar (selection) -->
        <teleport to="body">
            <div
                v-if="formatBarOpen"
                class="wa-formatbar"
                :style="{ left: formatBarX + 'px', top: formatBarY + 'px' }"
            >
                <button type="button" class="wa-fbtn" title="Жирный" @mousedown.prevent @click="applyFormat('bold')">
                    <span class="wa-fbtn-txt wa-fbtn-txt--bold">B</span>
                </button>
                <button type="button" class="wa-fbtn" title="Курсив" @mousedown.prevent @click="applyFormat('italic')">
                    <span class="wa-fbtn-txt wa-fbtn-txt--italic">I</span>
                </button>
                <button type="button" class="wa-fbtn" title="Зачёркнутый" @mousedown.prevent @click="applyFormat('strikeThrough')">
                    <span class="wa-fbtn-txt wa-fbtn-txt--strike">S</span>
                </button>
                <div class="wa-fsep"></div>
                <button type="button" class="wa-fbtn" title="Код" @mousedown.prevent @click="applyFormat('formatBlock', 'pre')">
                    <svg class="wa-ficon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M8.7 16.7 4 12l4.7-4.7 1.4 1.4L6.8 12l3.3 3.3-1.4 1.4zm6.6 0-1.4-1.4L17.2 12l-3.3-3.3 1.4-1.4L20 12l-4.7 4.7z"/>
                    </svg>
                </button>
                <button type="button" class="wa-fbtn" title="Цитата" @mousedown.prevent @click="applyFormat('formatBlock', 'blockquote')">
                    <svg class="wa-ficon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M7 17h4V7H5v6h2v4zm10 0h4V7h-6v6h2v4z"/>
                    </svg>
                </button>
                <div class="wa-fsep"></div>
                <button type="button" class="wa-fbtn" title="Маркированный список" @mousedown.prevent @click="applyFormat('insertUnorderedList')">
                    <svg class="wa-ficon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M4 10.5a1.5 1.5 0 113 0 1.5 1.5 0 01-3 0zM4 17.5a1.5 1.5 0 113 0 1.5 1.5 0 01-3 0zM4 3.5a1.5 1.5 0 113 0 1.5 1.5 0 01-3 0zM9 4h12v2H9V4zm0 7h12v2H9v-2zm0 7h12v2H9v-2z"/>
                    </svg>
                </button>
                <button type="button" class="wa-fbtn" title="Нумерованный список" @mousedown.prevent @click="applyFormat('insertOrderedList')">
                    <svg class="wa-ficon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M6 5H4V4h3v4H4V7h2V5zm0 8H4v-1h1v-1H4v-1h2a1 1 0 011 1v1a1 1 0 01-1 1zm0 6H4v-1h2v-1H4v-1h2a1 1 0 011 1v1a1 1 0 01-1 1zM9 4h12v2H9V4zm0 7h12v2H9v-2zm0 7h12v2H9v-2z"/>
                    </svg>
                </button>
                <div class="wa-fsep"></div>
                <span class="wa-fcount" title="Длина текста">{{ messageText.length }}</span>
            </div>
        </teleport>

        <!-- Fullscreen attachment preview composer -->
        <Teleport to="body">
            <transition name="att-fade">
                <div
                    v-if="showAttachmentPreview"
                    class="att-preview"
                    tabindex="-1"
                    @keydown="onPreviewKeydown"
                >
                    <!-- Top toolbar -->
                    <div class="att-preview-top">
                        <button
                            class="att-tool-btn"
                            type="button"
                            :disabled="isUploadingAttachments"
                            title="Закрыть"
                            @click="closeAttachmentPreview"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>

                        <div class="att-preview-counter" v-if="pendingAttachments.length > 1">
                            {{ activeAttachmentIndex + 1 }} / {{ pendingAttachments.length }}
                        </div>

                        <div class="att-preview-top-actions">
                            <button
                                class="att-tool-btn"
                                type="button"
                                title="Удалить файл"
                                :disabled="isUploadingAttachments"
                                @click="removePendingAttachment(activeAttachmentIndex)"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M8 7V4a1 1 0 011-1h6a1 1 0 011 1v3" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Big preview area -->
                    <div class="att-preview-stage">
                        <template v-if="activeAttachment">
                            <img
                                v-if="activeAttachment.kind === 'image' || activeAttachment.kind === 'gif'"
                                :src="activeAttachment.previewUrl"
                                :alt="activeAttachment.file.name"
                                class="att-preview-media"
                            />
                            <video
                                v-else
                                :src="activeAttachment.previewUrl"
                                class="att-preview-media"
                                controls
                                playsinline
                            />
                        </template>
                    </div>

                    <!-- Bottom composer -->
                    <div class="att-preview-bottom">
                        <div class="att-preview-caption-row">
                            <div class="att-preview-caption-pill">
                                <textarea
                                    ref="attachmentCaptionRef"
                                    v-model="activeAttachmentCaption"
                                    rows="1"
                                    :placeholder="pendingAttachments.length > 1 ? `Подпись к файлу ${activeAttachmentIndex + 1}…` : 'Добавьте подпись…'"
                                    class="att-preview-caption-input wa-scrollbar"
                                    :disabled="isUploadingAttachments"
                                    @keydown="onCaptionKeydown"
                                ></textarea>
                            </div>

                            <button
                                class="att-send-btn"
                                type="button"
                                :disabled="isUploadingAttachments"
                                title="Отправить"
                                @click="confirmSendAttachments"
                            >
                                <svg v-if="!isUploadingAttachments" class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" />
                                </svg>
                                <span v-else class="att-spinner" aria-hidden="true"></span>
                            </button>
                        </div>

                        <!-- Thumbnails strip -->
                        <div class="att-preview-thumbs">
                            <button
                                v-for="(att, index) in pendingAttachments"
                                :key="att.id"
                                type="button"
                                class="att-preview-thumb"
                                :class="{ 'att-preview-thumb-active': index === activeAttachmentIndex }"
                                :disabled="isUploadingAttachments"
                                @click="selectAttachment(index)"
                            >
                                <img
                                    v-if="att.kind === 'image' || att.kind === 'gif'"
                                    :src="att.previewUrl"
                                    alt=""
                                />
                                <video v-else :src="att.previewUrl" muted playsinline />
                            </button>

                            <button
                                type="button"
                                class="att-preview-thumb att-preview-thumb-add"
                                :disabled="isUploadingAttachments"
                                title="Добавить ещё"
                                @click="addMoreAttachments"
                            >
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>

        <!-- Contact picker / confirm -->
        <Teleport to="body">
            <transition name="att-fade">
                <div
                    v-if="contactPickerOpen"
                    class="contact-picker-overlay"
                    @click.self="closeContactPicker"
                >
                    <div class="contact-picker-sheet" role="dialog" aria-modal="true">
                        <!-- List step -->
                        <template v-if="showContactPicker && !pendingContact">
                            <div class="contact-picker-header">
                                <button
                                    class="att-tool-btn"
                                    type="button"
                                    title="Закрыть"
                                    @click="closeContactPicker"
                                >
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                                <h3 class="contact-picker-title">Отправить контакт</h3>
                            </div>

                            <div class="contact-picker-search">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                <input
                                    v-model="contactPickerSearch"
                                    type="text"
                                    placeholder="Поиск контактов"
                                    class="contact-picker-search-input"
                                    autocomplete="off"
                                />
                            </div>

                            <div class="contact-picker-list wa-scrollbar">
                                <div v-if="contactPickerLoading" class="contact-picker-empty">
                                    Загрузка…
                                </div>
                                <div
                                    v-else-if="contactPickerList.length === 0"
                                    class="contact-picker-empty"
                                >
                                    Контакты не найдены.
                                </div>
                                <button
                                    v-for="c in contactPickerList"
                                    :key="c.id"
                                    type="button"
                                    class="contact-picker-row"
                                    @click="pickContact(c)"
                                >
                                    <span class="contact-avatar">
                                        <img
                                            v-if="c.profile_picture_url"
                                            :src="c.profile_picture_url"
                                            :alt="contactDisplayName(c)"
                                        />
                                        <svg v-else class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                                        </svg>
                                    </span>
                                    <span class="contact-meta">
                                        <span class="contact-meta-name">{{ contactDisplayName(c) }}</span>
                                        <span class="contact-meta-phone">{{ contactDisplayPhone(c) }}</span>
                                    </span>
                                </button>
                            </div>
                        </template>

                        <!-- Confirm step -->
                        <template v-else-if="pendingContact">
                            <div class="contact-picker-header">
                                <button
                                    class="att-tool-btn"
                                    type="button"
                                    title="Назад"
                                    :disabled="isSendingContact"
                                    @click="backToContactList"
                                >
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                                    </svg>
                                </button>
                                <h3 class="contact-picker-title">Отправить контакт?</h3>
                                <button
                                    class="att-tool-btn contact-picker-header-close"
                                    type="button"
                                    title="Закрыть"
                                    :disabled="isSendingContact"
                                    @click="closeContactPicker"
                                >
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            <div class="contact-confirm-body">
                                <div class="contact-confirm-card">
                                    <span class="contact-confirm-avatar">
                                        <img
                                            v-if="pendingContact.profile_picture_url"
                                            :src="pendingContact.profile_picture_url"
                                            :alt="contactDisplayName(pendingContact)"
                                        />
                                        <svg v-else class="w-10 h-10" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                                        </svg>
                                    </span>
                                    <div class="contact-confirm-info">
                                        <div class="contact-confirm-name">{{ contactDisplayName(pendingContact) }}</div>
                                        <div class="contact-confirm-phone">{{ contactDisplayPhone(pendingContact) }}</div>
                                    </div>
                                </div>
                            </div>

                            <div class="contact-confirm-actions">
                                <button
                                    type="button"
                                    class="contact-btn-cancel"
                                    :disabled="isSendingContact"
                                    @click="closeContactPicker"
                                >
                                    Отмена
                                </button>
                                <button
                                    type="button"
                                    class="contact-btn-send"
                                    :disabled="isSendingContact"
                                    @click="confirmSendContact"
                                >
                                    <span v-if="isSendingContact" class="att-spinner" aria-hidden="true"></span>
                                    <span v-else>Отправить</span>
                                </button>
                            </div>
                        </template>
                    </div>
                </div>
            </transition>
        </Teleport>

        <!-- Poll composer -->
        <Teleport to="body">
            <transition name="att-fade">
                <div
                    v-if="showPollModal"
                    class="contact-picker-overlay"
                    @click.self="closePollModal"
                >
                    <div class="contact-picker-sheet poll-sheet" role="dialog" aria-modal="true">
                        <div class="contact-picker-header">
                            <button
                                class="att-tool-btn"
                                type="button"
                                title="Закрыть"
                                :disabled="isSendingPoll"
                                @click="closePollModal"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                            <h3 class="contact-picker-title">Создать опрос</h3>
                        </div>

                        <div class="poll-body wa-scrollbar">
                            <label class="poll-label">Вопрос</label>
                            <input
                                v-model="pollQuestion"
                                type="text"
                                class="poll-input"
                                placeholder="О чём хотите спросить?"
                                maxlength="255"
                                :disabled="isSendingPoll"
                            />

                            <label class="poll-label poll-label-spaced">Варианты ответа</label>
                            <div class="poll-options">
                                <div
                                    v-for="(_, idx) in pollOptions"
                                    :key="idx"
                                    class="poll-option-row"
                                >
                                    <input
                                        v-model="pollOptions[idx]"
                                        type="text"
                                        class="poll-input"
                                        :placeholder="`Вариант ${idx + 1}`"
                                        maxlength="100"
                                        :disabled="isSendingPoll"
                                    />
                                    <button
                                        type="button"
                                        class="poll-option-remove"
                                        :disabled="isSendingPoll || pollOptions.length <= 2"
                                        title="Удалить вариант"
                                        @click="removePollOption(idx)"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>

                                <button
                                    v-if="pollOptions.length < 12"
                                    type="button"
                                    class="poll-add-option"
                                    :disabled="isSendingPoll"
                                    @click="addPollOption"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                                    </svg>
                                    Добавить вариант
                                </button>
                            </div>

                            <label class="poll-check">
                                <input
                                    v-model="pollAllowMultiple"
                                    type="checkbox"
                                    :disabled="isSendingPoll"
                                />
                                <span>Разрешить несколько ответов</span>
                            </label>
                        </div>

                        <div class="contact-confirm-actions">
                            <button
                                type="button"
                                class="contact-btn-cancel"
                                :disabled="isSendingPoll"
                                @click="closePollModal"
                            >
                                Отмена
                            </button>
                            <button
                                type="button"
                                class="contact-btn-send"
                                :disabled="isSendingPoll || !pollCanSubmit"
                                @click="submitPoll"
                            >
                                <span v-if="isSendingPoll" class="att-spinner" aria-hidden="true"></span>
                                <span v-else>Создать</span>
                            </button>
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>
    </div>
</template>


<style scoped>
.wa-input-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    min-height: 56px;

    /* Float on top of chat wallpaper (same pattern as messages). */
    border: 1px solid color-mix(in srgb, var(--wa-border-strong) 38%, transparent);
    border-radius: 24px;
    background: color-mix(in srgb, var(--wa-panel-header) 78%, transparent);
    box-shadow: 0 4px 24px rgba(0, 0, 0, 0.22);
    backdrop-filter: blur(14px) saturate(1.1);
    -webkit-backdrop-filter: blur(14px) saturate(1.1);

    margin: 10px 12px;

    align-items: flex-end;
}

.wa-input-attach {
    position: relative;
    display: flex;
    align-items: center;
    flex-shrink: 0;
}

.wa-input-btn {
    width: 36px;
    height: 36px;
    padding: 0;
    margin: 0;
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    color: #b0b0b0;
    background: transparent;
    transition: color 0.2s ease, background-color 0.2s ease, transform 0.2s ease;
}
.wa-input-btn:hover { background: rgba(255, 255, 255, 0.05); color: #ffffff; }
.wa-input-btn:active { transform: scale(0.96); }
.wa-input-btn:disabled { opacity: 0.5; }
.wa-input-btn-active { color: var(--wa-accent); }
.wa-input-btn svg { display: block; }

.wa-input-pill {
    position: relative;
    display: flex;
    align-items: center;
    gap: 4px;
    min-height: 36px;
    padding: 0 8px;
    border-radius: 9999px;
    background: transparent;
    box-shadow: none;
}

.wa-rich-editor {
    flex: 1;
    min-width: 0;
    display: block;
    margin: 0;
    padding: 10px 4px;
    border: 0;
    background: transparent;
    color: #ffffff;
    font-size: 15px;
    line-height: 22px;
    max-height: 120px;
    overflow-y: auto;
    caret-color: #ffffff;
    white-space: pre-wrap;
    word-break: break-word;
}
.wa-rich-editor:focus { outline: none; box-shadow: none; }
.wa-rich-editor:empty:before {
    content: attr(data-placeholder);
    color: #9aa0a6;
}

.wa-rich-editor blockquote {
    border-left: 3px solid var(--wa-accent);
    padding-left: 10px;
    margin: 6px 0;
    color: rgba(255, 255, 255, 0.9);
}
.wa-rich-editor pre {
    background: rgba(0, 0, 0, 0.28);
    padding: 8px 10px;
    border-radius: 10px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    font-size: 13px;
    line-height: 18px;
    margin: 6px 0;
    overflow-x: auto;
}

.wa-formatbar {
    position: fixed;
    z-index: 200;
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 10px;
    border-radius: 9999px;
    background: rgba(23, 23, 23, 0.96);
    border: 1px solid rgba(255, 255, 255, 0.08);
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.4);
    backdrop-filter: blur(10px);
}
.wa-fbtn {
    width: 30px;
    height: 30px;
    border-radius: 9999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: rgba(255, 255, 255, 0.88);
    transition: background-color 0.12s ease, transform 0.12s ease;
}
.wa-fbtn:hover { background: rgba(255, 255, 255, 0.08); }
.wa-fbtn:active { transform: scale(0.96); }
.wa-ficon { width: 18px; height: 18px; display: block; }
.wa-fbtn-txt { font-size: 15px; line-height: 1; }
.wa-fbtn-txt--bold { font-weight: 800; }
.wa-fbtn-txt--italic { font-style: italic; }
.wa-fbtn-txt--strike { text-decoration: line-through; }
.wa-fsep { width: 1px; height: 18px; background: rgba(255, 255, 255, 0.12); margin: 0 2px; }
.wa-fcount { margin-left: 6px; font-size: 12px; color: rgba(255, 255, 255, 0.6); min-width: 22px; text-align: right; }

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

<style>
/* Unscoped: the preview is teleported to <body>, so scoped styles would not reach it. */
.att-preview {
    position: fixed;
    inset: 0;
    z-index: 100;
    display: flex;
    flex-direction: column;
    background: rgba(0, 0, 0, 0.7);
    color: #e9edef;
    outline: none;
}

.att-fade-enter-active,
.att-fade-leave-active {
    transition: opacity 0.18s ease;
}
.att-fade-enter-from,
.att-fade-leave-to {
    opacity: 0;
}

.att-preview-top {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    min-height: 56px;
}

.att-preview-top-actions {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 8px;
}

.att-preview-counter {
    font-size: 14px;
    color: var(--wa-icon);
}

.att-tool-btn {
    width: 40px;
    height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    color: #e9edef;
    background: transparent;
    flex-shrink: 0;
    transition: background-color 0.15s ease;
}
.att-tool-btn:hover:not(:disabled) {
    background: rgba(255, 255, 255, 0.08);
}
.att-tool-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.att-preview-stage {
    flex: 1;
    min-height: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 8px 24px 24px;
}

.att-preview-media {
    max-width: min(100%, 1100px);
    max-height: 100%;
    object-fit: contain;
    border-radius: 6px;
    background: #000;
}

.att-preview-bottom {
    padding: 12px 16px 20px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    background: linear-gradient(to top, rgba(0, 0, 0, 0.35), transparent);
}

.att-preview-caption-row {
    display: flex;
    align-items: center;
    gap: 12px;
    max-width: 960px;
    width: 100%;
    margin: 0 auto;
}

.att-preview-caption-pill {
    flex: 1;
    display: flex;
    align-items: center;
    min-height: 44px;
    padding: 6px 16px;
    border-radius: 9999px;
    background: var(--wa-panel-input);
}

.att-preview-caption-input {
    flex: 1;
    min-width: 0;
    display: block;
    width: 100%;
    margin: 0;
    padding: 6px 0;
    border: 0;
    background: transparent;
    color: var(--wa-text);
    font-size: 15px;
    line-height: 20px;
    resize: none;
    max-height: 120px;
    overflow-y: auto;
}
.att-preview-caption-input::placeholder {
    color: var(--wa-text-secondary);
}
.att-preview-caption-input:focus {
    outline: none;
    box-shadow: none;
}

.att-send-btn {
    width: 56px;
    height: 56px;
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    color: #fff;
    background: var(--wa-accent);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
    transition: background-color 0.15s ease, transform 0.08s ease;
}
.att-send-btn:hover:not(:disabled) {
    background: var(--wa-accent-hover);
}
.att-send-btn:active:not(:disabled) {
    transform: scale(0.97);
}
.att-send-btn:disabled {
    opacity: 0.65;
    cursor: not-allowed;
}

.att-spinner {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 2px solid rgba(255, 255, 255, 0.4);
    border-top-color: #fff;
    animation: att-spin 0.8s linear infinite;
}
@keyframes att-spin {
    to { transform: rotate(360deg); }
}

.att-preview-thumbs {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    flex-wrap: wrap;
}

.att-preview-thumb {
    width: 56px;
    height: 56px;
    border-radius: 6px;
    overflow: hidden;
    border: 2px solid transparent;
    background: var(--wa-panel);
    padding: 0;
    flex-shrink: 0;
    transition: border-color 0.15s ease, transform 0.08s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.att-preview-thumb img,
.att-preview-thumb video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.att-preview-thumb:hover:not(:disabled) {
    transform: translateY(-1px);
}
.att-preview-thumb-active {
    border-color: #00a884;
}
.att-preview-thumb-add {
    color: #e9edef;
    background: rgba(255, 255, 255, 0.06);
    border: 2px dashed rgba(255, 255, 255, 0.18);
}
.att-preview-thumb-add:hover:not(:disabled) {
    background: rgba(255, 255, 255, 0.12);
}
.att-preview-thumb:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* ===== Contact picker ===== */
.contact-picker-overlay {
    position: fixed;
    inset: 0;
    z-index: 110;
    background: rgba(0, 0, 0, 0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
}

.contact-picker-sheet {
    width: 100%;
    max-width: 440px;
    max-height: min(640px, 100%);
    display: flex;
    flex-direction: column;
    background: var(--wa-panel-header, #262829);
    color: var(--wa-text, #e9edef);
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
    overflow: hidden;
}

.contact-picker-header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    min-height: 56px;
    border-bottom: 1px solid var(--wa-border, rgba(134, 150, 160, 0.2));
}

.contact-picker-title {
    flex: 1;
    font-size: 16px;
    font-weight: 500;
    margin: 0;
}

.contact-picker-header-close {
    margin-left: auto;
}

.contact-picker-search {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 10px 12px;
    padding: 0 12px;
    height: 40px;
    background: var(--wa-panel-input, #2a2c2d);
    color: var(--wa-text-secondary, #9aa0a4);
    border-radius: 9999px;
}

.contact-picker-search-input {
    flex: 1;
    min-width: 0;
    background: transparent;
    border: 0;
    color: var(--wa-text, #e9edef);
    font-size: 14px;
    line-height: 20px;
    padding: 8px 0;
}
.contact-picker-search-input:focus {
    outline: none;
}
.contact-picker-search-input::placeholder {
    color: var(--wa-text-secondary, #9aa0a4);
}

.contact-picker-list {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 4px 0 8px;
}

.contact-picker-empty {
    padding: 32px 16px;
    text-align: center;
    color: var(--wa-text-secondary, #9aa0a4);
    font-size: 14px;
}

.contact-picker-row {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 10px 16px;
    background: transparent;
    color: var(--wa-text, #e9edef);
    text-align: left;
    transition: background-color 0.12s ease;
}
.contact-picker-row:hover {
    background: var(--wa-panel-hover, rgba(255, 255, 255, 0.04));
}

.contact-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    overflow: hidden;
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--wa-panel-input, #2a2c2d);
    color: var(--wa-text-secondary, #9aa0a4);
}
.contact-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.contact-meta {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.contact-meta-name {
    font-size: 15px;
    font-weight: 500;
    color: var(--wa-text, #e9edef);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.contact-meta-phone {
    font-size: 13px;
    color: var(--wa-text-secondary, #9aa0a4);
}

.contact-confirm-body {
    padding: 24px 16px 8px;
    display: flex;
    justify-content: center;
}

.contact-confirm-card {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    background: var(--wa-bubble-out, #005c4b);
    color: #fff;
    border-radius: 10px;
    width: 100%;
    max-width: 360px;
}

.contact-confirm-avatar {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    overflow: hidden;
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.18);
}
.contact-confirm-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.contact-confirm-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.contact-confirm-name {
    font-size: 16px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.contact-confirm-phone {
    font-size: 13px;
    opacity: 0.85;
}

.contact-confirm-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 16px;
    border-top: 1px solid var(--wa-border, rgba(134, 150, 160, 0.2));
}

.contact-btn-cancel,
.contact-btn-send {
    min-width: 96px;
    height: 40px;
    padding: 0 18px;
    border-radius: 9999px;
    font-size: 14px;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.15s ease, opacity 0.15s ease;
}
.contact-btn-cancel {
    background: transparent;
    color: var(--wa-text, #e9edef);
}
.contact-btn-cancel:hover:not(:disabled) {
    background: var(--wa-panel-hover, rgba(255, 255, 255, 0.04));
}
.contact-btn-send {
    background: #00a884;
    color: #fff;
}
.contact-btn-send:hover:not(:disabled) {
    background: #06cf9c;
}
.contact-btn-send:disabled,
.contact-btn-cancel:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* ===== Poll composer ===== */
.poll-sheet {
    max-width: 480px;
}

.poll-body {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 16px 18px 8px;
    display: flex;
    flex-direction: column;
}

.poll-label {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: var(--wa-text-secondary, #9aa0a4);
    margin-bottom: 8px;
}
.poll-label-spaced {
    margin-top: 16px;
}

.poll-input {
    width: 100%;
    height: 40px;
    padding: 0 14px;
    border: 0;
    border-radius: 8px;
    background: var(--wa-panel-input, #2a2c2d);
    color: var(--wa-text, #e9edef);
    font-size: 14px;
    line-height: 20px;
}
.poll-input:focus {
    outline: none;
    box-shadow: 0 0 0 1px #00a884 inset;
}
.poll-input::placeholder {
    color: var(--wa-text-secondary, #9aa0a4);
}
.poll-input:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.poll-options {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.poll-option-row {
    display: flex;
    align-items: center;
    gap: 8px;
}

.poll-option-remove {
    width: 32px;
    height: 32px;
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    color: var(--wa-text-secondary, #9aa0a4);
    background: transparent;
    transition: background-color 0.15s ease, color 0.15s ease;
}
.poll-option-remove:hover:not(:disabled) {
    background: var(--wa-panel-hover, rgba(255, 255, 255, 0.06));
    color: var(--wa-text, #e9edef);
}
.poll-option-remove:disabled {
    opacity: 0.35;
    cursor: not-allowed;
}

.poll-add-option {
    align-self: flex-start;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 4px;
    padding: 8px 12px;
    border-radius: 9999px;
    color: #00a884;
    background: rgba(0, 168, 132, 0.1);
    font-size: 13px;
    font-weight: 500;
    transition: background-color 0.15s ease;
}
.poll-add-option:hover:not(:disabled) {
    background: rgba(0, 168, 132, 0.18);
}
.poll-add-option:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.poll-check {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    margin-top: 18px;
    font-size: 14px;
    color: var(--wa-text, #e9edef);
    cursor: pointer;
    user-select: none;
}
.poll-check input[type='checkbox'] {
    width: 16px;
    height: 16px;
    accent-color: #00a884;
    cursor: pointer;
}
.poll-check:has(input:disabled) {
    opacity: 0.6;
    cursor: not-allowed;
}
</style>
