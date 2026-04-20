<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, onBeforeUnmount } from 'vue';
import axios from 'axios';
import type { Chat } from '@/types';

const props = defineProps<{
    chat: Chat;
    isSelected: boolean;
}>();

const menuOpen = ref(false);
const menuX = ref(0);
const menuY = ref(0);

function openMenu(e: MouseEvent) {
    e.preventDefault();
    e.stopPropagation();
    menuX.value = e.clientX;
    menuY.value = e.clientY;
    menuOpen.value = true;
}

function closeMenu() {
    menuOpen.value = false;
}

async function togglePin() {
    closeMenu();
    try {
        await axios.post(route('chats.toggle-pin', props.chat.id));
    } finally {
        router.reload({ only: ['chats'] });
    }
}

async function toggleArchive() {
    closeMenu();
    try {
        await axios.post(route('chats.archive', props.chat.id));
    } finally {
        router.reload({ only: ['chats', 'archivedCount'] });
    }
}

function onEscape(e: KeyboardEvent) {
    if (e.key === 'Escape') closeMenu();
}
window.addEventListener('keydown', onEscape);
onBeforeUnmount(() => window.removeEventListener('keydown', onEscape));

function formatTime(dateStr: string | null): string {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    const now = new Date();
    const isToday = d.toDateString() === now.toDateString();
    if (isToday) {
        return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    }
    const yesterday = new Date(now);
    yesterday.setDate(yesterday.getDate() - 1);
    if (d.toDateString() === yesterday.toDateString()) return 'Вчера';
    return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' });
}

function getInitial(chat: Chat): string {
    const name = chat.chat_name || chat.contact?.push_name || chat.contact?.name || '?';
    return name.charAt(0).toUpperCase();
}

function getSessionLabel(chat: Chat): string {
    if (!chat.whatsapp_session) return '';
    return chat.whatsapp_session.phone_number || chat.whatsapp_session.display_name || '';
}
</script>

<template>
    <Link
        :href="route('chats.show', chat.id)"
        class="flex items-center px-3 py-[10px] gap-3 cursor-pointer transition group chat-list-item"
        :class="isSelected ? 'is-selected' : ''"
        preserve-state
        @contextmenu="openMenu"
    >
        <!-- Avatar -->
        <div
            class="w-[49px] h-[49px] rounded-full flex items-center justify-center shrink-0 text-white text-lg font-medium"
            :class="chat.is_group ? 'bg-[var(--wa-accent)]' : 'bg-[#6b7c85]'"
        >
            {{ getInitial(chat) }}
        </div>

        <div class="flex-1 min-w-0 border-b border-[var(--wa-divider)] group-hover:border-transparent pb-3 -mb-3 pt-0.5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-1.5 min-w-0">
                    <svg v-if="chat.is_pinned" class="w-3 h-3 text-[var(--wa-text-secondary)] shrink-0" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2l-2-2z"/>
                    </svg>
                    <span class="text-[var(--wa-text)] text-base truncate">
                        {{ chat.chat_name || chat.contact?.push_name || chat.contact?.phone_number || 'Без имени' }}
                    </span>
                </div>
                <span
                    class="text-xs shrink-0 ml-1"
                    :style="{ color: chat.unread_count > 0 ? 'var(--wa-accent)' : 'var(--wa-text-secondary)' }"
                >
                    {{ formatTime(chat.last_message_at) }}
                </span>
            </div>
            <div class="flex items-center justify-between mt-1">
                <div class="flex items-center gap-1 min-w-0 flex-1">
                    <span
                        v-if="getSessionLabel(chat)"
                        class="shrink-0 text-[10px] px-1.5 py-0 rounded font-medium"
                        :style="{ background: 'var(--wa-accent-soft)', color: 'var(--wa-accent)' }"
                    >
                        {{ getSessionLabel(chat) }}
                    </span>
                    <span class="text-sm text-[var(--wa-text-secondary)] truncate">
                        {{ chat.last_message_text || 'Нет сообщений' }}
                    </span>
                </div>
                <span
                    v-if="chat.unread_count > 0"
                    class="ml-1 shrink-0 min-w-[20px] h-[20px] rounded-full text-[11px] font-semibold flex items-center justify-center px-1.5"
                    :style="{ background: 'var(--wa-unread)', color: 'var(--wa-unread-text)' }"
                >
                    {{ chat.unread_count > 99 ? '99+' : chat.unread_count }}
                </span>
            </div>
        </div>
    </Link>

    <!-- Context menu -->
    <teleport to="body">
        <div v-if="menuOpen">
            <div class="fixed inset-0 z-40" @click="closeMenu" @contextmenu.prevent="closeMenu"></div>
            <div
                class="fixed z-50 min-w-[200px] rounded-lg shadow-xl py-2 border"
                :style="{
                    left: menuX + 'px',
                    top: menuY + 'px',
                    background: 'var(--wa-panel-header)',
                    borderColor: 'var(--wa-border-strong)',
                }"
            >
                <button
                    @click="togglePin"
                    class="block w-full text-left px-4 py-2 text-sm hover:bg-[var(--wa-panel-hover)]"
                    :style="{ color: 'var(--wa-text)' }"
                >
                    {{ chat.is_pinned ? 'Открепить' : 'Закрепить' }}
                </button>
                <button
                    @click="toggleArchive"
                    class="block w-full text-left px-4 py-2 text-sm hover:bg-[var(--wa-panel-hover)]"
                    :style="{ color: 'var(--wa-text)' }"
                >
                    {{ chat.is_archived ? 'Разархивировать' : 'Архивировать' }}
                </button>
            </div>
        </div>
    </teleport>
</template>

<style scoped>
.chat-list-item {
    transition: background-color 0.15s ease;
}
.chat-list-item:hover {
    background-color: var(--wa-panel-hover);
}
.chat-list-item.is-selected {
    background-color: var(--wa-selected);
}
</style>
