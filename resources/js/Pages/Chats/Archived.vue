<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ChatSidebar from './Partials/ChatSidebar.vue';
import { Head } from '@inertiajs/vue3';
import type { Chat, Paginated } from '@/types';

defineProps<{
    chats: Paginated<Chat>;
    search?: string;
}>();
</script>

<template>
    <Head title="Архив" />
    <AuthenticatedLayout>
        <div class="flex h-full w-full bg-[var(--wa-bg)]">
            <ChatSidebar :chats="chats" :search="search" scope="archived" class="shrink-0" />

            <div class="flex-1 flex flex-col min-w-0 border-l border-[var(--wa-border)]">
                <div
                    class="flex-1 flex flex-col items-center justify-center relative border-b-[6px]"
                    :style="{ background: 'var(--wa-empty-bg)', borderBottomColor: 'var(--wa-accent)' }"
                >
                    <div class="text-center px-8">
                        <h1 class="text-[var(--wa-text)] text-[28px] font-light mb-3">Архив чатов</h1>
                        <p class="text-[var(--wa-text-secondary)] text-sm max-w-[520px] mx-auto leading-relaxed">
                            Здесь хранятся чаты, которые вы скрыли из основного списка.
                            Нажмите на чат, чтобы открыть его или разархивировать.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
