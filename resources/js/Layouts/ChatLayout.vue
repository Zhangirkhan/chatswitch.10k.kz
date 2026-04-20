<script setup lang="ts">
import AuthenticatedLayout from './AuthenticatedLayout.vue';
import ChatSidebar from '@/Pages/Chats/Partials/ChatSidebar.vue';
import type { Chat, Paginated } from '@/types';

defineProps<{
    chats: Paginated<Chat>;
    selectedChatId?: number;
    search?: string;
}>();
</script>

<template>
    <AuthenticatedLayout>
        <div class="flex h-full w-full bg-[var(--wa-bg)]">
            <ChatSidebar
                :chats="chats"
                :selected-chat-id="selectedChatId"
                :search="search"
                class="shrink-0"
                :class="{ 'hidden md:flex': selectedChatId }"
            />
            <div
                class="flex-1 flex flex-col min-w-0 border-l border-[var(--wa-border)]"
                :class="{ 'hidden md:flex': !selectedChatId }"
            >
                <slot />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
