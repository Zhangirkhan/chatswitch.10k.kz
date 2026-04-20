<script setup lang="ts">
import { ref, computed, onBeforeUnmount } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import type { Message, MessageReaction } from '@/types';

const props = defineProps<{
    message: Message;
}>();

const emit = defineEmits<{
    (e: 'reply', msg: Message): void;
    (e: 'deleted', id: number): void;
    (e: 'reactions-updated', payload: { id: number; reactions: MessageReaction[] }): void;
}>();

const page = usePage<any>();
const currentUserId = computed<number | null>(() => page.props.auth?.user?.id ?? null);

const isOutbound = props.message.direction === 'outbound';

const hovered = ref(false);
const menuOpen = ref(false);
const quickBarVisible = computed(() => hovered.value || menuOpen.value);

const QUICK_EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

const reactionList = computed<MessageReaction[]>(() => props.message.reactions ?? []);

function normalizeEmoji(e: string): string {
    return e.replace(/\uFE0F/g, '').replace(/\u200D/g, '');
}

const groupedReactions = computed(() => {
    const map = new Map<string, { emoji: string; count: number; byMe: boolean; users: string[] }>();
    for (const r of reactionList.value) {
        const key = normalizeEmoji(r.emoji);
        const entry = map.get(key) || { emoji: r.emoji, count: 0, byMe: false, users: [] };
        entry.count++;
        if (r.user_id === currentUserId.value) {
            entry.byMe = true;
            entry.emoji = r.emoji;
        }
        if (r.user?.name) entry.users.push(r.user.name);
        map.set(key, entry);
    }
    return Array.from(map.values());
});

async function react(emoji: string) {
    menuOpen.value = false;
    try {
        const { data } = await axios.post(route('messages.react', props.message.id), { emoji });
        emit('reactions-updated', { id: props.message.id, reactions: data.reactions });
    } catch (e) {
        console.error(e);
    }
}

function toggleMenu(e: MouseEvent) {
    e.stopPropagation();
    menuOpen.value = !menuOpen.value;
}

function closeMenu() {
    menuOpen.value = false;
}

function onEscape(e: KeyboardEvent) {
    if (e.key === 'Escape') closeMenu();
}
window.addEventListener('keydown', onEscape);
onBeforeUnmount(() => window.removeEventListener('keydown', onEscape));

async function copyMessage() {
    closeMenu();
    if (!props.message.body) return;
    try {
        await navigator.clipboard.writeText(props.message.body);
    } catch (e) {
        console.error('Copy failed', e);
    }
}

function replyToMessage() {
    closeMenu();
    emit('reply', props.message);
}

async function deleteMessage() {
    closeMenu();
    if (!confirm('Удалить это сообщение?')) return;
    try {
        await axios.delete(route('messages.destroy', props.message.id));
        emit('deleted', props.message.id);
    } catch (e) {
        console.error(e);
    }
}

function notImplemented(name: string) {
    closeMenu();
    alert(`«${name}» — скоро будет доступно.`);
}

function formatTime(dateStr: string | null): string {
    if (!dateStr) return '';
    return new Date(dateStr).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

function getMediaUrl(mediaId: number): string {
    return route('media.show', mediaId);
}

// === Media type detection ===
const primaryMedia = computed(() => props.message.media?.[0] ?? null);
const primaryMime = computed(() => primaryMedia.value?.mime_type ?? '');

const isSticker = computed(() => {
    return props.message.type === 'sticker'
        || (primaryMime.value === 'image/webp' && !props.message.body);
});
const isGif = computed(() => {
    return props.message.type === 'gif' || primaryMime.value === 'image/gif';
});
const isVoiceNote = computed(() => {
    return props.message.type === 'voice'
        || (primaryMime.value.startsWith('audio/') && !props.message.body);
});
const isImage = computed(() => {
    if (isSticker.value || isGif.value) return false;
    return primaryMime.value.startsWith('image/');
});
const isVideo = computed(() => primaryMime.value.startsWith('video/'));
const isAudio = computed(() => {
    if (isVoiceNote.value) return false;
    return primaryMime.value.startsWith('audio/');
});
const isDocument = computed(() => {
    if (!primaryMedia.value) return false;
    return !primaryMime.value.startsWith('image/')
        && !primaryMime.value.startsWith('video/')
        && !primaryMime.value.startsWith('audio/');
});

const isBubbleless = computed(() => isSticker.value);

// === Voice note player ===
const voiceAudio = ref<HTMLAudioElement | null>(null);
const voicePlaying = ref(false);
const voiceDuration = ref(0);
const voiceCurrent = ref(0);

function onVoiceLoaded() {
    if (voiceAudio.value) voiceDuration.value = voiceAudio.value.duration || 0;
}
function onVoiceTime() {
    if (voiceAudio.value) voiceCurrent.value = voiceAudio.value.currentTime;
}
function onVoiceEnd() {
    voicePlaying.value = false;
    voiceCurrent.value = 0;
}
function toggleVoicePlay() {
    const a = voiceAudio.value;
    if (!a) return;
    if (a.paused) { a.play(); voicePlaying.value = true; }
    else { a.pause(); voicePlaying.value = false; }
}
function formatDuration(sec: number): string {
    if (!isFinite(sec) || sec <= 0) return '0:00';
    const m = Math.floor(sec / 60);
    const s = Math.floor(sec % 60);
    return `${m}:${s.toString().padStart(2, '0')}`;
}
const voiceProgress = computed(() => {
    if (!voiceDuration.value) return 0;
    return Math.min(100, (voiceCurrent.value / voiceDuration.value) * 100);
});
// Pseudo-random waveform bars (stable per message id)
const waveformBars = computed(() => {
    const id = props.message.id;
    const bars: number[] = [];
    let seed = id * 9301 + 49297;
    for (let i = 0; i < 38; i++) {
        seed = (seed * 9301 + 49297) % 233280;
        bars.push(0.25 + (seed / 233280) * 0.75);
    }
    return bars;
});
</script>

<template>
    <div
        class="flex mb-1 px-[6%] relative group"
        :class="isOutbound ? 'justify-end' : 'justify-start'"
        @mouseenter="hovered = true"
        @mouseleave="hovered = false"
    >
        <div
            class="relative max-w-[65%] text-[14.2px]"
            :class="[
                isBubbleless ? 'bubble-less' : ['rounded-lg pl-2 pr-2 pt-[6px] pb-[8px] wa-shadow', isOutbound ? 'bubble-out rounded-tr-none' : 'bubble-in rounded-tl-none'],
            ]"
        >
            <!-- Tail -->
            <span
                v-if="!isBubbleless"
                class="absolute top-0 w-3 h-3 overflow-hidden"
                :class="isOutbound ? '-right-2' : '-left-2'"
            >
                <span
                    class="absolute w-3 h-3 transform rotate-45 top-0"
                    :class="isOutbound ? 'bubble-out -left-1.5' : 'bubble-in -right-1.5'"
                ></span>
            </span>

            <!-- Quick emoji reaction bar (hover) -->
            <transition name="fade-quick">
                <div
                    v-if="quickBarVisible"
                    class="absolute -top-10 z-20 flex items-center gap-1 px-2 py-1 rounded-full shadow-lg border reaction-bar"
                    :class="isOutbound ? 'right-0' : 'left-0'"
                    :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border-strong)' }"
                    @mouseenter="hovered = true"
                >
                    <button
                        v-for="e in QUICK_EMOJIS"
                        :key="e"
                        @click="react(e)"
                        class="w-8 h-8 flex items-center justify-center rounded-full hover:bg-[var(--wa-panel-hover)] text-xl leading-none transition"
                        type="button"
                    >
                        {{ e }}
                    </button>
                    <button
                        @click="notImplemented('Выбор эмодзи')"
                        class="w-8 h-8 flex items-center justify-center rounded-full hover:bg-[var(--wa-panel-hover)] transition"
                        :style="{ color: 'var(--wa-icon)' }"
                        type="button"
                        title="Другие эмодзи"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                        </svg>
                    </button>
                </div>
            </transition>

            <!-- Chevron action button -->
            <button
                v-show="quickBarVisible"
                @click="toggleMenu"
                class="absolute top-1 z-10 w-7 h-7 flex items-center justify-center rounded-full chevron-btn"
                :class="isOutbound ? 'right-1' : 'right-1'"
                type="button"
                title="Действия"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            <!-- Action dropdown menu -->
            <template v-if="menuOpen">
                <div class="fixed inset-0 z-40" @click="closeMenu"></div>
                <div
                    class="absolute top-8 z-50 min-w-[200px] rounded-lg shadow-xl py-1.5 border msg-menu"
                    :class="isOutbound ? 'right-0' : 'left-0'"
                    :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border-strong)' }"
                >
                    <button class="msg-menu-item" @click="notImplemented('Данные о сообщении')" type="button">
                        <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Данные о сообщении
                    </button>
                    <button class="msg-menu-item" @click="replyToMessage" type="button">
                        <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a6 6 0 016 6v1M3 10l6 6M3 10l6-6" />
                        </svg>
                        Ответить
                    </button>
                    <button class="msg-menu-item" @click="copyMessage" :disabled="!message.body" type="button">
                        <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                        </svg>
                        Копировать
                    </button>
                    <button class="msg-menu-item" @click="notImplemented('Пересылка сообщения')" type="button">
                        <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                        Переслать
                    </button>
                    <button class="msg-menu-item" @click="notImplemented('Закрепление сообщения')" type="button">
                        <svg class="msg-menu-icon" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2l-2-2z" />
                        </svg>
                        Закрепить
                    </button>
                    <button class="msg-menu-item" @click="notImplemented('Сохранить в избранное')" type="button">
                        <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.539 1.118L12 16.98l-3.976 2.888c-.784.57-1.838-.196-1.539-1.118l1.518-4.674a1 1 0 00-.363-1.118L3.664 10.1c-.783-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.673z" />
                        </svg>
                        В «Избранные»
                    </button>
                    <button class="msg-menu-item" @click="notImplemented('Выбор сообщений')" type="button">
                        <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Выбрать
                    </button>
                    <button class="msg-menu-item msg-menu-item-danger" @click="deleteMessage" type="button">
                        <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M8 7V4a1 1 0 011-1h6a1 1 0 011 1v3" />
                        </svg>
                        Удалить
                    </button>
                </div>
            </template>

            <!-- Sender info for inbound messages -->
            <div v-if="!isOutbound && (message.sender_name || message.sender_phone)" class="flex items-center gap-1.5 mb-0.5">
                <span
                    v-if="message.whatsapp_session?.phone_number"
                    class="text-[9px] px-1.5 rounded font-medium"
                    :style="{ background: 'var(--wa-accent-soft)', color: 'var(--wa-accent)' }"
                >
                    {{ message.whatsapp_session.phone_number }}
                </span>
                <span class="text-[13px] font-medium" :style="{ color: 'var(--wa-accent)' }">
                    {{ message.sender_name || message.sender_phone }}
                </span>
            </div>

            <!-- Outbound sender name (internal user who sent) -->
            <div v-if="isOutbound && message.sent_by_user" class="text-[11px] mb-0.5 opacity-80">
                {{ message.sent_by_user.name }}
            </div>

            <!-- Forwarded indicator -->
            <div v-if="message.is_forwarded" class="text-[11px] italic mb-0.5 flex items-center gap-1 opacity-70">
                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M12 8V4l8 8-8 8v-4H4V8h8z"/></svg>
                Переслано
            </div>

            <!-- Sticker (bubble-less) -->
            <template v-if="isSticker && primaryMedia">
                <img
                    :src="getMediaUrl(primaryMedia.id)"
                    class="max-w-[160px] max-h-[160px] object-contain"
                    :alt="'Стикер'"
                />
            </template>

            <!-- GIF -->
            <template v-else-if="isGif && primaryMedia">
                <div class="relative -mx-1 mb-1">
                    <img
                        :src="getMediaUrl(primaryMedia.id)"
                        class="rounded max-w-full max-h-64 object-cover cursor-pointer block"
                        :alt="'GIF'"
                    />
                    <span class="absolute bottom-2 left-2 px-1.5 py-0.5 rounded text-[11px] font-bold text-white" style="background: rgba(0,0,0,0.55);">
                        GIF
                    </span>
                </div>
            </template>

            <!-- Voice note -->
            <template v-else-if="isVoiceNote && primaryMedia">
                <div class="flex items-center gap-2 py-1 pr-2 min-w-[220px]">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center shrink-0" :style="{ background: 'var(--wa-accent-soft)' }">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24" :style="{ color: 'var(--wa-accent)' }">
                            <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5-3c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/>
                        </svg>
                    </div>
                    <button @click="toggleVoicePlay" type="button" class="w-9 h-9 rounded-full flex items-center justify-center shrink-0 transition" :style="{ background: 'var(--wa-accent)', color: 'white' }">
                        <svg v-if="!voicePlaying" class="w-4 h-4 ml-0.5" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M8 5v14l11-7z"/>
                        </svg>
                        <svg v-else class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/>
                        </svg>
                    </button>
                    <div class="flex-1 flex flex-col gap-1 min-w-0">
                        <div class="flex items-end gap-[2px] h-5">
                            <span
                                v-for="(h, i) in waveformBars"
                                :key="i"
                                class="waveform-bar"
                                :style="{
                                    height: Math.max(4, Math.round(h * 20)) + 'px',
                                    background: (i / waveformBars.length * 100) < voiceProgress ? 'var(--wa-accent)' : 'var(--wa-text-secondary)',
                                    opacity: (i / waveformBars.length * 100) < voiceProgress ? 1 : 0.5,
                                }"
                            ></span>
                        </div>
                        <div class="text-[11px]" :style="{ color: 'var(--wa-text-secondary)' }">
                            {{ formatDuration(voicePlaying || voiceCurrent > 0 ? voiceCurrent : voiceDuration) }}
                        </div>
                    </div>
                    <audio
                        ref="voiceAudio"
                        :src="getMediaUrl(primaryMedia.id)"
                        preload="metadata"
                        @loadedmetadata="onVoiceLoaded"
                        @timeupdate="onVoiceTime"
                        @ended="onVoiceEnd"
                    ></audio>
                </div>
            </template>

            <!-- Image -->
            <template v-else-if="isImage && primaryMedia">
                <div class="-mx-1 mb-1">
                    <img
                        :src="getMediaUrl(primaryMedia.id)"
                        class="rounded max-w-full max-h-80 object-cover cursor-pointer block"
                        :alt="primaryMedia.filename || 'Фото'"
                    />
                </div>
            </template>

            <!-- Video -->
            <template v-else-if="isVideo && primaryMedia">
                <div class="-mx-1 mb-1">
                    <video
                        controls
                        class="rounded max-w-full max-h-80 block"
                    >
                        <source :src="getMediaUrl(primaryMedia.id)" :type="primaryMedia.mime_type" />
                    </video>
                </div>
            </template>

            <!-- Audio (music file) -->
            <template v-else-if="isAudio && primaryMedia">
                <audio controls class="max-w-full h-10 mb-1">
                    <source :src="getMediaUrl(primaryMedia.id)" :type="primaryMedia.mime_type" />
                </audio>
            </template>

            <!-- Document -->
            <template v-else-if="isDocument && primaryMedia">
                <a
                    :href="getMediaUrl(primaryMedia.id)"
                    target="_blank"
                    class="flex items-center gap-3 p-2.5 rounded-md text-sm mb-1 transition hover:opacity-90"
                    :style="{ background: 'rgba(0,0,0,0.06)' }"
                >
                    <div class="w-10 h-10 rounded-md flex items-center justify-center shrink-0 text-white" style="background: var(--wa-accent);">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="truncate font-medium" :style="{ color: 'var(--wa-text)' }">
                            {{ primaryMedia.filename || 'Документ' }}
                        </div>
                        <div class="text-[11px] uppercase" :style="{ color: 'var(--wa-text-secondary)' }">
                            {{ primaryMedia.mime_type.split('/').pop() }}
                        </div>
                    </div>
                    <svg class="w-5 h-5 shrink-0" :style="{ color: 'var(--wa-text-secondary)' }" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </a>
            </template>

            <!-- Body -->
            <p v-if="message.body" class="whitespace-pre-wrap break-words leading-[19px] pr-14" style="word-break: break-word;">
                {{ message.body }}
            </p>

            <!-- Time + ack overlay (stickers / bubble-less) -->
            <div
                v-if="isBubbleless"
                class="absolute bottom-1 right-1 flex items-center gap-1 px-1.5 py-0.5 rounded-full text-white text-[11px]"
                style="background: rgba(0,0,0,0.4);"
            >
                <span>{{ formatTime(message.message_timestamp || message.created_at) }}</span>
                <span v-if="isOutbound">
                    <svg v-if="message.ack === 'sent'" class="w-3.5 h-3.5" viewBox="0 0 16 15" fill="currentColor">
                        <path d="M10.91 3.316l-.478-.372a.365.365 0 0 0-.51.063L4.566 9.879a.32.32 0 0 1-.484.033L1.891 7.769a.366.366 0 0 0-.515.006l-.423.433a.364.364 0 0 0 .006.514l3.258 3.185c.143.14.361.125.484-.033l6.272-8.048a.365.365 0 0 0-.063-.51z"/>
                    </svg>
                    <svg v-else-if="message.ack === 'delivered' || message.ack === 'read'" class="w-3.5 h-3.5" viewBox="0 0 16 15" fill="currentColor">
                        <path d="M15.01 3.316l-.478-.372a.365.365 0 0 0-.51.063L8.666 9.879a.32.32 0 0 1-.484.033l-.358-.325a.319.319 0 0 0-.484.032l-.378.483a.418.418 0 0 0 .036.541l1.32 1.266c.143.14.361.125.484-.033l6.272-8.048a.365.365 0 0 0-.063-.51zm-4.1 0l-.478-.372a.365.365 0 0 0-.51.063L4.566 9.879a.32.32 0 0 1-.484.033L1.891 7.769a.366.366 0 0 0-.515.006l-.423.433a.364.364 0 0 0 .006.514l3.258 3.185c.143.14.361.125.484-.033l6.272-8.048a.365.365 0 0 0-.063-.51z"/>
                    </svg>
                </span>
            </div>

            <!-- Time + ack (in bubble) -->
            <div v-else class="flex items-center justify-end gap-1 -mt-[4px] -mb-[4px] float-right ml-2">
                <span class="text-[11px] leading-none opacity-70">
                    {{ formatTime(message.message_timestamp || message.created_at) }}
                </span>
                <span
                    v-if="isOutbound"
                    class="leading-none flex items-center"
                    :style="{ color: message.ack === 'read' ? 'var(--wa-ack-read)' : undefined, opacity: message.ack === 'read' ? 1 : 0.7 }"
                >
                    <svg v-if="message.ack === 'sent'" class="w-4 h-4" viewBox="0 0 16 15" fill="currentColor">
                        <path d="M10.91 3.316l-.478-.372a.365.365 0 0 0-.51.063L4.566 9.879a.32.32 0 0 1-.484.033L1.891 7.769a.366.366 0 0 0-.515.006l-.423.433a.364.364 0 0 0 .006.514l3.258 3.185c.143.14.361.125.484-.033l6.272-8.048a.365.365 0 0 0-.063-.51z"/>
                    </svg>
                    <svg v-else-if="message.ack === 'delivered' || message.ack === 'read'" class="w-4 h-4" viewBox="0 0 16 15" fill="currentColor">
                        <path d="M15.01 3.316l-.478-.372a.365.365 0 0 0-.51.063L8.666 9.879a.32.32 0 0 1-.484.033l-.358-.325a.319.319 0 0 0-.484.032l-.378.483a.418.418 0 0 0 .036.541l1.32 1.266c.143.14.361.125.484-.033l6.272-8.048a.365.365 0 0 0-.063-.51zm-4.1 0l-.478-.372a.365.365 0 0 0-.51.063L4.566 9.879a.32.32 0 0 1-.484.033L1.891 7.769a.366.366 0 0 0-.515.006l-.423.433a.364.364 0 0 0 .006.514l3.258 3.185c.143.14.361.125.484-.033l6.272-8.048a.365.365 0 0 0-.063-.51z"/>
                    </svg>
                    <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </span>
            </div>
            <div class="clear-both"></div>

            <!-- Reactions pill -->
            <div
                v-if="groupedReactions.length"
                class="reaction-pill absolute flex items-center gap-1 px-2 py-[3px] rounded-full shadow-sm border"
                :class="isOutbound ? 'right-3' : 'left-3'"
                :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border-strong)' }"
            >
                <button
                    v-for="g in groupedReactions"
                    :key="normalizeEmoji(g.emoji)"
                    @click="react(g.emoji)"
                    class="inline-flex items-center gap-0.5 leading-none hover:opacity-80 transition-opacity"
                    :title="g.users.join(', ')"
                    type="button"
                >
                    <span class="text-[14px]">{{ g.emoji }}</span>
                    <span
                        v-if="g.count > 1"
                        class="text-[12px] font-medium"
                        :style="{ color: 'var(--wa-text-secondary)' }"
                    >{{ g.count }}</span>
                </button>
            </div>
            <!-- Spacer to prevent pill overlap -->
            <div v-if="groupedReactions.length" class="h-[14px]"></div>
        </div>
    </div>
</template>

<style scoped>
.bubble-out {
    background: var(--wa-bubble-out);
    color: var(--wa-bubble-text);
}
.bubble-in {
    background: var(--wa-bubble-in);
    color: var(--wa-bubble-text);
}
.chevron-btn {
    background: linear-gradient(to left, var(--wa-bubble-out) 30%, transparent);
    color: var(--wa-text-secondary);
    opacity: 0;
    transition: opacity 0.12s ease;
}
.group:hover .chevron-btn,
.chevron-btn:hover {
    opacity: 1;
}
.bubble-in ~ .chevron-btn,
.bubble-in .chevron-btn {
    background: linear-gradient(to left, var(--wa-bubble-in) 30%, transparent);
}
.reaction-bar {
    animation: reaction-bar-pop 0.14s ease-out;
}
@keyframes reaction-bar-pop {
    from { opacity: 0; transform: translateY(4px) scale(0.96); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
.fade-quick-enter-active,
.fade-quick-leave-active {
    transition: opacity 0.12s ease, transform 0.12s ease;
}
.fade-quick-enter-from,
.fade-quick-leave-to {
    opacity: 0;
    transform: translateY(4px);
}
.msg-menu {
    animation: reaction-bar-pop 0.12s ease-out;
}
.msg-menu-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    width: 100%;
    padding: 0.5rem 0.875rem;
    font-size: 0.875rem;
    color: var(--wa-text);
    text-align: left;
    transition: background-color 0.12s ease;
}
.msg-menu-item:not(:disabled):hover {
    background-color: var(--wa-panel-hover);
}
.msg-menu-item:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.msg-menu-icon {
    width: 1rem;
    height: 1rem;
    color: var(--wa-text-secondary);
    flex-shrink: 0;
}
.msg-menu-item-danger {
    color: #ef4444;
}
.msg-menu-item-danger .msg-menu-icon {
    color: #ef4444;
}
.msg-menu-item-danger:hover {
    background-color: rgba(239, 68, 68, 0.08);
}
.reaction-pill {
    bottom: -10px;
    z-index: 1;
}
.bubble-less {
    background: transparent;
    padding: 0;
    box-shadow: none;
}
.waveform-bar {
    display: inline-block;
    width: 3px;
    border-radius: 2px;
    transition: background 0.1s ease;
}
</style>
