<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ChatListItem from './ChatListItem.vue';
import type { Chat, Paginated } from '@/types';

defineProps<{
    chats: Paginated<Chat>;
    selectedChatId?: number;
}>();
</script>

<template>
    <div class="w-[400px] h-full flex flex-col bg-[var(--wa-panel)]">
        <!-- Header with back arrow -->
        <div class="h-[60px] px-3 flex items-center gap-3 shrink-0">
            <Link
                :href="route('chats.index')"
                class="wa-icon-btn"
                title="Назад"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
            </Link>
            <h1 class="text-[var(--wa-text)] text-xl font-normal">В архиве</h1>
        </div>

        <!-- Explanation -->
        <div
            class="px-6 py-4 text-[13px] leading-relaxed shrink-0"
            :style="{ color: 'var(--wa-text-secondary)' }"
        >
            При получении новых сообщений данные чаты остаются в архиве.
            Чтобы изменить эту настройку, перейдите в раздел
            <strong class="text-[var(--wa-text)]">«Настройки &gt; Чаты»</strong>
            на своём телефоне.
        </div>

        <!-- Archived chat list -->
        <div class="flex-1 overflow-y-auto wa-scrollbar border-t" :style="{ borderColor: 'var(--wa-border)' }">
            <div
                v-if="chats.data.length === 0"
                class="flex items-center justify-center h-full text-sm text-[var(--wa-text-secondary)] px-6 text-center"
            >
                В архиве пока нет чатов
            </div>
            <ChatListItem
                v-for="chat in chats.data"
                :key="chat.id"
                :chat="chat"
                :is-selected="chat.id === selectedChatId"
            />
        </div>
    </div>
</template>

<style scoped>
.wa-icon-btn {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 9999px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--wa-icon);
    transition: background-color 0.15s ease;
}
.wa-icon-btn:hover {
    background-color: var(--wa-panel-hover);
}
</style>
