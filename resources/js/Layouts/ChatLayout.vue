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
        <div class="flex h-full min-h-0 w-full bg-[var(--wa-bg)]">
            <ChatSidebar
                :chats="chats"
                :selected-chat-id="selectedChatId"
                :search="search"
                class="shrink-0"
                :class="{ 'hidden sm:flex': selectedChatId }"
            />
            <div
                class="flex min-h-0 min-w-0 flex-1 flex-col border-l"
                :style="{ borderColor: 'var(--wa-sidebar-divider)' }"
                :class="{ 'hidden sm:flex': !selectedChatId }"
            >
                <slot />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
