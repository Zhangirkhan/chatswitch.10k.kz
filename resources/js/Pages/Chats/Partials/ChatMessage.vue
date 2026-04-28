<script setup lang="ts">
import { computed, ref } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import EmojiPicker from './EmojiPicker.vue';
import MessageReactions from './MessageReactions.vue';
import type { Message, MessageReaction } from '@/types';

const props = defineProps<{
    message: Message;
}>();

const emit = defineEmits<{
    (e: 'reply', message: Message): void;
    (e: 'deleted', id: number): void;
    (e: 'reactions-updated', payload: { id: number; reactions: MessageReaction[] }): void;
}>();

const page = usePage<any>();
const currentUserId = computed<number | undefined>(() => page.props.auth?.user?.id);

const pickerOpen = ref(false);
const isReacting = ref(false);

const isOutbound = computed(() => props.message.direction === 'outbound');

const mediaItems = computed(() => props.message.media ?? []);

function mediaSrc(mediaId: number): string {
    return route('media.show', mediaId);
}

function isImageMime(mime: string): boolean {
    return mime.startsWith('image/');
}

function isVideoMime(mime: string): boolean {
    return mime.startsWith('video/');
}

function messageTime(): string {
    const value = props.message.message_timestamp || props.message.created_at;
    if (!value) return '';
    return new Date(value).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

async function react(emoji: string): Promise<void> {
    if (isReacting.value) return;
    isReacting.value = true;
    try {
        const { data } = await axios.post(route('messages.react', props.message.id), { emoji });
        const reactions = Array.isArray(data.reactions) ? (data.reactions as MessageReaction[]) : [];
        emit('reactions-updated', { id: props.message.id, reactions });
    } catch (e) {
        console.error('React failed', e);
    } finally {
        isReacting.value = false;
        pickerOpen.value = false;
    }
}

async function destroyMessage(): Promise<void> {
    try {
        await axios.delete(route('messages.destroy', props.message.id));
        emit('deleted', props.message.id);
    } catch (e) {
        console.error('Delete failed', e);
    }
}
</script>

<template>
    <div class="group mb-2 flex" :class="isOutbound ? 'justify-end' : 'justify-start'">
        <div
            class="relative max-w-[72%] rounded-lg px-2.5 py-1.5 text-[14.2px] leading-[19px] shadow-sm"
            :class="isOutbound ? 'rounded-tr-none' : 'rounded-tl-none'"
            :style="{
                background: isOutbound ? 'var(--wa-bubble-out)' : 'var(--wa-bubble-in)',
                color: 'var(--wa-bubble-text)',
            }"
        >
            <div class="absolute -top-2 z-30" :class="isOutbound ? 'right-0' : 'left-0'">
                <EmojiPicker v-if="pickerOpen" @select="react" @close="pickerOpen = false" />
            </div>

            <button
                type="button"
                class="absolute top-1 hidden h-7 w-7 items-center justify-center rounded-full text-base shadow-lg transition hover:scale-105 group-hover:flex"
                :class="isOutbound ? '-left-9' : '-right-9'"
                :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-icon)' }"
                title="Добавить/изменить реакцию"
                data-emoji-trigger
                @click.stop="pickerOpen = !pickerOpen"
            >
                ☺
            </button>

            <button
                type="button"
                class="absolute right-1 top-1 hidden h-6 w-6 items-center justify-center rounded-full opacity-80 shadow-sm transition hover:opacity-100 group-hover:flex"
                :style="{ background: isOutbound ? 'var(--wa-bubble-out)' : 'var(--wa-bubble-in)' }"
                title="Удалить сообщение"
                @click="destroyMessage"
            >
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>

            <p
                v-if="message.body && message.body.trim()"
                class="mb-1 whitespace-pre-wrap break-words pr-14"
                style="word-break: break-word;"
            >
                {{ message.body }}
            </p>

            <div v-if="mediaItems.length" class="mb-1 space-y-2 pr-14">
                <template v-for="m in mediaItems" :key="m.id">
                    <img
                        v-if="isImageMime(m.mime_type)"
                        :src="mediaSrc(m.id)"
                        :alt="m.filename || 'image'"
                        class="max-h-64 max-w-full rounded-md"
                        loading="lazy"
                    />
                    <video
                        v-else-if="isVideoMime(m.mime_type)"
                        :src="mediaSrc(m.id)"
                        class="max-h-64 max-w-full rounded-md"
                        controls
                        playsinline
                    />
                    <audio
                        v-else-if="
                            m.mime_type.startsWith('audio/') ||
                            message.type === 'ptt' ||
                            message.type === 'voice'
                        "
                        :src="mediaSrc(m.id)"
                        class="w-full min-w-[200px] max-w-md"
                        controls
                        preload="metadata"
                    />
                    <a
                        v-else
                        :href="mediaSrc(m.id)"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1 break-all underline"
                        :style="{ color: 'var(--wa-accent)' }"
                    >
                        {{ m.filename || 'Файл' }}
                    </a>
                </template>
            </div>

            <div class="float-right -mb-1 -mt-1 ml-2 flex items-center gap-1 text-[11px] opacity-70">
                <span>{{ messageTime() }}</span>
            </div>
            <div class="clear-both"></div>

            <MessageReactions
                :reactions="message.reactions || []"
                :current-user-id="currentUserId"
                @react="react"
            />
        </div>
    </div>
</template>

