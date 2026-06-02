<script setup lang="ts">
import ChatLayout from '@/Layouts/ChatLayout.vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from '@/composables/useI18n';
import type { Chat, Paginated } from '@/types';

defineProps<{
    chats: Paginated<Chat>;
    search?: string;
}>();

const { t } = useI18n();
</script>

<template>
    <Head :title="t('chats.archived.title')" />
    <ChatLayout :chats="chats" :search="search" scope="archived">
        <div
            class="flex-1 flex flex-col items-center justify-center relative border-b-[6px]"
            :style="{ background: 'var(--wa-empty-bg)', borderBottomColor: 'var(--wa-accent)' }"
        >
            <div class="text-center px-8">
                <h1 class="text-[var(--wa-text)] text-[28px] font-light mb-3">{{ t('chats.archived.heading') }}</h1>
                <p class="text-[var(--wa-text-secondary)] text-sm max-w-[520px] mx-auto leading-relaxed">
                    {{ t('chats.archived.description') }}
                </p>
            </div>
        </div>
    </ChatLayout>
</template>
