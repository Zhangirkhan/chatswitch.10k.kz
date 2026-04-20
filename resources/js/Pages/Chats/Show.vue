<script setup lang="ts">
import ChatLayout from '@/Layouts/ChatLayout.vue';
import ChatHeader from './Partials/ChatHeader.vue';
import ChatMessage from './Partials/ChatMessage.vue';
import ChatInput from './Partials/ChatInput.vue';
import ContactInfoPanel from './Partials/ContactInfoPanel.vue';
import { Head } from '@inertiajs/vue3';
import { ref, onMounted, nextTick, watch, onUnmounted, computed } from 'vue';
import axios from 'axios';
import type { Chat, Message, MessageReaction, Paginated } from '@/types';

const props = defineProps<{
    chat: Chat;
    messages: Paginated<Message>;
    chats: Paginated<Chat>;
}>();

const localMessages = ref<Message[]>([...props.messages.data].reverse());
const messagesContainer = ref<HTMLDivElement | null>(null);
const typingUsers = ref<Map<number, string>>(new Map());
const isLoadingMore = ref(false);
const hasMoreMessages = ref(props.messages.current_page < props.messages.last_page);

const searchOpen = ref(false);
const searchQuery = ref('');
const contactInfoOpen = ref(false);
const replyTo = ref<Message | null>(null);

function setReply(msg: Message) {
    replyTo.value = msg;
}

function clearReply() {
    replyTo.value = null;
}

function onMessageDeleted(id: number) {
    localMessages.value = localMessages.value.filter((m) => m.id !== id);
}

function onReactionsUpdated(payload: { id: number; reactions: MessageReaction[] }) {
    const msg = localMessages.value.find((m) => m.id === payload.id);
    if (msg) msg.reactions = payload.reactions;
}

function toggleSearch() {
    searchOpen.value = !searchOpen.value;
    if (!searchOpen.value) searchQuery.value = '';
}

function toggleContactInfo() {
    contactInfoOpen.value = !contactInfoOpen.value;
}

const displayedMessages = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return localMessages.value;
    return localMessages.value.filter((m) =>
        (m.body || '').toLowerCase().includes(q),
    );
});

watch(() => props.messages, (newVal) => {
    localMessages.value = [...newVal.data].reverse();
    hasMoreMessages.value = newVal.current_page < newVal.last_page;
    nextTick(scrollToBottom);
});

onMounted(() => {
    scrollToBottom();
    markAsRead();
    setupEcho();
});

onUnmounted(() => {
    cleanupEcho();
});

function scrollToBottom() {
    nextTick(() => {
        if (messagesContainer.value) {
            messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
        }
    });
}

function markAsRead() {
    if (props.chat.unread_count > 0) {
        axios.post(route('chats.mark-read', props.chat.id)).catch(() => {});
    }
}

function onMessageSent(message: Message) {
    localMessages.value.push(message);
    scrollToBottom();
}

async function loadMoreMessages() {
    if (isLoadingMore.value || !hasMoreMessages.value) return;
    isLoadingMore.value = true;

    const oldestMsg = localMessages.value[0];
    const ts = oldestMsg?.message_timestamp || oldestMsg?.created_at;

    try {
        const { data } = await axios.get(route('api.chats.timeline', props.chat.id), {
            params: { before_timestamp: ts, limit: 50 },
        });
        if (data.messages?.length) {
            localMessages.value = [...data.messages, ...localMessages.value];
        }
        if (!data.messages?.length || data.messages.length < 50) {
            hasMoreMessages.value = false;
        }
    } catch (err) {
        console.error('Load more failed:', err);
    } finally {
        isLoadingMore.value = false;
    }
}

function onScroll() {
    if (!messagesContainer.value) return;
    if (messagesContainer.value.scrollTop < 200) {
        loadMoreMessages();
    }
}

let echoChannel: any = null;

function setupEcho() {
    if (!(window as any).Echo) return;

    echoChannel = (window as any).Echo.channel(`chat.${props.chat.id}`);

    echoChannel.listen('.message.received', (e: any) => {
        const msg = e.message;
        if (msg && !localMessages.value.find((m) => m.id === msg.id)) {
            localMessages.value.push(msg);
            scrollToBottom();
            markAsRead();
        }
    });

    echoChannel.listen('.user.typing', (e: any) => {
        typingUsers.value.set(e.userId, e.userName);
        setTimeout(() => typingUsers.value.delete(e.userId), 3000);
    });
}

function cleanupEcho() {
    if ((window as any).Echo && echoChannel) {
        (window as any).Echo.leave(`chat.${props.chat.id}`);
    }
}
</script>

<template>
    <Head :title="chat.chat_name || 'Чат'" />
    <ChatLayout :chats="chats" :selected-chat-id="chat.id">
        <div class="flex h-full w-full">
            <div class="flex flex-col flex-1 min-w-0">
                <ChatHeader
                    :chat="chat"
                    :typing-users="typingUsers"
                    @toggle-search="toggleSearch"
                    @show-contact-info="toggleContactInfo"
                />

                <!-- Inline message search -->
                <div
                    v-if="searchOpen"
                    class="px-4 py-2 shrink-0 border-b flex items-center gap-3"
                    :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border)' }"
                >
                    <div class="relative flex-1">
                        <svg
                            class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--wa-icon)]"
                            fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Поиск в сообщениях…"
                            class="w-full pl-10 pr-3 py-2 rounded-full text-sm border-0 focus:ring-0 focus:outline-none"
                            :style="{ background: 'var(--wa-panel)', color: 'var(--wa-text)' }"
                            autofocus
                        />
                    </div>
                    <span class="text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                        {{ displayedMessages.length }} найдено
                    </span>
                    <button
                        @click="toggleSearch"
                        class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)]"
                        title="Закрыть"
                        type="button"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Messages area -->
                <div
                    ref="messagesContainer"
                    class="flex-1 overflow-y-auto wa-scrollbar chat-bg py-3"
                    @scroll="onScroll"
                >
                    <div v-if="isLoadingMore" class="text-center py-2">
                        <span
                            class="text-xs px-3 py-1 rounded-full"
                            :style="{ background: 'var(--wa-date-bubble)', color: 'var(--wa-date-bubble-text)' }"
                        >Загрузка...</span>
                    </div>

                    <ChatMessage
                        v-for="msg in displayedMessages"
                        :key="msg.id"
                        :message="msg"
                        @reply="setReply"
                        @deleted="onMessageDeleted"
                        @reactions-updated="onReactionsUpdated"
                    />

                    <div v-if="displayedMessages.length === 0" class="flex items-center justify-center h-full">
                        <p
                            class="text-[13px] px-4 py-2 rounded-lg wa-shadow"
                            :style="{ background: 'var(--wa-date-bubble)', color: 'var(--wa-date-bubble-text)' }"
                        >
                            <template v-if="searchQuery">
                                Ничего не найдено
                            </template>
                            <template v-else>
                                Нет сообщений
                            </template>
                        </p>
                    </div>
                </div>

                <ChatInput
                    :chat-id="chat.id"
                    :reply-to="replyTo"
                    @message-sent="onMessageSent"
                    @cancel-reply="clearReply"
                />
            </div>

            <!-- Contact info panel -->
            <ContactInfoPanel
                v-if="contactInfoOpen"
                :chat="chat"
                :messages="localMessages"
                @close="toggleContactInfo"
                @open-search="() => { contactInfoOpen = false; searchOpen = true; }"
            />
        </div>
    </ChatLayout>
</template>
