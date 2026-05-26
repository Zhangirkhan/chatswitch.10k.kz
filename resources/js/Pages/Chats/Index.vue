<script setup lang="ts">
import ChatLayout from '@/Layouts/ChatLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Chat, Paginated } from '@/types';

defineProps<{
    chats: Paginated<Chat>;
    search?: string;
}>();

const page = usePage<any>();
const roles = computed<string[]>(() => page.props.auth?.user?.roles || []);
const isAdmin = computed(() => roles.value.includes('administrator'));
</script>

<template>
    <Head title="Чаты" />
    <ChatLayout :chats="chats" :search="search">
        <!-- Empty state (WhatsApp Web style) -->
        <div
            class="flex-1 flex flex-col items-center justify-center relative border-b-[6px]"
            :style="{ background: 'var(--wa-empty-bg)', borderBottomColor: 'var(--wa-accent)' }"
        >
            <div class="text-center px-8">
                <h1 class="text-[var(--wa-text)] text-[32px] font-light mb-4">Accel для Веба</h1>
                <p class="text-[var(--wa-text-secondary)] text-sm max-w-[560px] mx-auto leading-relaxed">
                    Отправляйте и получайте сообщения без необходимости держать телефон в сети.
                    Используйте Accel на нескольких WhatsApp-номерах из одного окна.
                </p>

                <div v-if="isAdmin" class="mt-8 flex items-center justify-center">
                    <Link
                        :href="route('settings.connections')"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full text-sm font-medium shadow-sm transition hover:brightness-95"
                        :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                        </svg>
                        Подключить WhatsApp-номер
                    </Link>
                </div>

                <div class="mt-10 flex items-center justify-center gap-2 text-[var(--wa-text-secondary)] text-[13px]">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/>
                    </svg>
                    Ваши личные сообщения защищены сквозным шифрованием
                </div>
            </div>
        </div>
    </ChatLayout>
</template>
