<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, onBeforeUnmount } from 'vue';
import axios from 'axios';
import type { Chat } from '@/types';

const props = defineProps<{
    chat: Chat;
    typingUsers: Map<number, string>;
}>();

const emit = defineEmits<{
    (e: 'toggle-search'): void;
    (e: 'show-contact-info'): void;
}>();

const menuOpen = ref(false);
const muted = ref(false);
const working = ref(false);

function closeMenu() {
    menuOpen.value = false;
}

function toggleMenu() {
    menuOpen.value = !menuOpen.value;
}

function onEscape(e: KeyboardEvent) {
    if (e.key === 'Escape') closeMenu();
}
window.addEventListener('keydown', onEscape);
onBeforeUnmount(() => window.removeEventListener('keydown', onEscape));

async function togglePin() {
    closeMenu();
    if (working.value) return;
    working.value = true;
    try {
        await axios.post(route('chats.toggle-pin', props.chat.id));
        router.reload({ only: ['chat', 'chats'] });
    } finally {
        working.value = false;
    }
}

async function toggleArchive() {
    closeMenu();
    if (working.value) return;
    working.value = true;
    try {
        await axios.post(route('chats.archive', props.chat.id));
    } finally {
        working.value = false;
        router.visit(route('chats.index'));
    }
}

function openSearch() {
    closeMenu();
    emit('toggle-search');
}

function openContactInfo() {
    closeMenu();
    emit('show-contact-info');
}

function toggleMute() {
    closeMenu();
    muted.value = !muted.value;
}

function closeChatWindow() {
    closeMenu();
    router.visit(route('chats.index'));
}

async function clearChat() {
    closeMenu();
    if (!confirm('Очистить всю историю этого чата? Это действие необратимо.')) return;
    working.value = true;
    try {
        await axios.post(route('chats.clear', props.chat.id));
        router.reload({ only: ['messages', 'chat'] });
    } finally {
        working.value = false;
    }
}

async function deleteChat() {
    closeMenu();
    if (!confirm('Удалить этот чат? Все сообщения будут удалены.')) return;
    router.delete(route('chats.destroy', props.chat.id));
}

function notImplemented(name: string) {
    closeMenu();
    alert(`«${name}» — функция скоро будет доступна.`);
}

function getInitial(): string {
    const name = props.chat.chat_name || props.chat.contact?.push_name || '?';
    return name.charAt(0).toUpperCase();
}

function getTypingText(): string {
    const names = [...props.typingUsers.values()];
    if (names.length === 0) return '';
    if (names.length === 1) return `${names[0]} печатает...`;
    return `${names.join(', ')} печатают...`;
}
</script>

<template>
    <div class="h-[60px] bg-[var(--wa-panel-header)] flex items-center px-4 gap-3 shrink-0 relative">
        <Link :href="route('chats.index')" class="md:hidden text-[var(--wa-icon)]">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
        </Link>

        <div
            @click="openContactInfo"
            class="w-10 h-10 rounded-full flex items-center justify-center text-white font-medium shrink-0 cursor-pointer"
            :class="chat.is_group ? 'bg-[var(--wa-accent)]' : 'bg-[#6b7c85]'"
        >
            {{ getInitial() }}
        </div>

        <div
            @click="openContactInfo"
            class="flex-1 min-w-0 cursor-pointer"
        >
            <h2 class="text-base text-[var(--wa-text)] truncate font-normal">
                {{ chat.chat_name || chat.contact?.push_name || chat.contact?.phone_number || 'Без имени' }}
            </h2>
            <p class="text-xs text-[var(--wa-text-secondary)] truncate">
                <template v-if="typingUsers.size > 0">
                    <span class="text-[var(--wa-accent)]">{{ getTypingText() }}</span>
                </template>
                <template v-else-if="chat.whatsapp_session?.phone_number">
                    Номер: {{ chat.whatsapp_session.phone_number }}
                    <span v-if="chat.contact?.phone_number"> • {{ chat.contact.phone_number }}</span>
                </template>
                <template v-else-if="chat.contact?.phone_number">
                    {{ chat.contact.phone_number }}
                </template>
                <template v-else>
                    в сети
                </template>
            </p>
        </div>

        <div v-if="chat.assignments?.length" class="flex -space-x-1.5 shrink-0 mr-2">
            <div
                v-for="a in chat.assignments.slice(0, 3)"
                :key="a.id"
                class="w-7 h-7 rounded-full border-2 flex items-center justify-center text-[10px] font-bold"
                :style="{
                    background: 'var(--wa-accent-soft)',
                    color: 'var(--wa-accent)',
                    borderColor: 'var(--wa-panel-header)'
                }"
                :title="a.user?.name"
            >
                {{ a.user?.name?.charAt(0)?.toUpperCase() }}
            </div>
            <div
                v-if="chat.assignments.length > 3"
                class="w-7 h-7 rounded-full border-2 flex items-center justify-center text-[10px] font-medium"
                :style="{
                    background: 'var(--wa-selected)',
                    color: 'var(--wa-text-secondary)',
                    borderColor: 'var(--wa-panel-header)'
                }"
            >
                +{{ chat.assignments.length - 3 }}
            </div>
        </div>

        <div class="flex items-center gap-1 shrink-0">
            <button class="wa-header-btn" title="Видеозвонок" @click="notImplemented('Видеозвонок')">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
            </button>
            <button class="wa-header-btn" title="Звонок" @click="notImplemented('Звонок')">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M19.05 17.34l-2.39-2.39a1.49 1.49 0 00-2.11 0l-.75.75a9.02 9.02 0 01-4.5-4.5l.75-.75a1.49 1.49 0 000-2.11L7.66 5.95a1.49 1.49 0 00-2.11 0l-1.3 1.3a2 2 0 00-.46 2.12A18 18 0 0015.63 21a2 2 0 002.12-.46l1.3-1.3a1.49 1.49 0 000-1.9z"/>
                </svg>
            </button>
            <button class="wa-header-btn" title="Поиск" @click="openSearch">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
            </button>

            <div class="relative">
                <button class="wa-header-btn" title="Меню" @click="toggleMenu" type="button">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                        <circle cx="12" cy="5" r="2"/>
                        <circle cx="12" cy="12" r="2"/>
                        <circle cx="12" cy="19" r="2"/>
                    </svg>
                </button>

                <div
                    v-if="menuOpen"
                    @click="closeMenu"
                    class="fixed inset-0 z-40"
                ></div>

                <div
                    v-if="menuOpen"
                    class="absolute right-0 top-full mt-1 min-w-[240px] rounded-lg shadow-xl py-2 z-50 border header-menu"
                    :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border-strong)' }"
                >
                    <button class="menu-item" @click="openContactInfo" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Данные контакта
                    </button>
                    <button class="menu-item" @click="openSearch" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        Поиск
                    </button>
                    <button class="menu-item" @click="notImplemented('Выбрать сообщения')" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Выбрать сообщения
                    </button>
                    <button class="menu-item" @click="toggleMute" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path v-if="!muted" stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                            <path v-else stroke-linecap="round" stroke-linejoin="round" d="M5.586 15L4 17h5a3 3 0 006 0h5l-1.405-1.405M3 3l18 18M9 5.341V5a2 2 0 114 0v.341" />
                        </svg>
                        {{ muted ? 'Включить звук' : 'Без звука' }}
                    </button>
                    <button class="menu-item" @click="notImplemented('Исчезающие сообщения')" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Исчезающие сообщения
                    </button>
                    <button class="menu-item" @click="togglePin" type="button">
                        <svg class="menu-icon" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2l-2-2z" />
                        </svg>
                        {{ chat.is_pinned ? 'Убрать из избранного' : 'Добавить в избранное' }}
                    </button>
                    <button class="menu-item" @click="notImplemented('Добавить в список')" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h10M4 18h10M19 15v6m-3-3h6" />
                        </svg>
                        Добавить в список
                    </button>
                    <button class="menu-item" @click="toggleArchive" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v1a2 2 0 01-2 2M5 8v11a2 2 0 002 2h10a2 2 0 002-2V8M10 12h4" />
                        </svg>
                        {{ chat.is_archived ? 'Разархивировать' : 'Архивировать' }}
                    </button>
                    <button class="menu-item" @click="closeChatWindow" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        Закрыть окно чата
                    </button>

                    <div class="my-1 h-px" :style="{ background: 'var(--wa-border)' }"></div>

                    <button class="menu-item" @click="notImplemented('Пожаловаться')" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 2H21l-3 6 3 6h-8.5l-1-2H5a2 2 0 00-2 2z" />
                        </svg>
                        Пожаловаться
                    </button>
                    <button class="menu-item" @click="notImplemented('Заблокировать')" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                        </svg>
                        Заблокировать
                    </button>
                    <button class="menu-item menu-item-danger" @click="clearChat" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4l16 16M4 20L20 4" />
                        </svg>
                        Очистить чат
                    </button>
                    <button class="menu-item menu-item-danger" @click="deleteChat" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M8 7V4a1 1 0 011-1h6a1 1 0 011 1v3" />
                        </svg>
                        Удалить чат
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.wa-header-btn {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 9999px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--wa-icon);
    transition: background-color 0.15s ease;
}
.wa-header-btn:hover {
    background-color: var(--wa-rail-btn-hover);
}
.header-menu {
    animation: header-menu-pop 0.12s ease-out;
}
@keyframes header-menu-pop {
    from { opacity: 0; transform: translateY(-4px) scale(0.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
.menu-item {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    width: 100%;
    padding: 0.625rem 1rem;
    font-size: 0.875rem;
    color: var(--wa-text);
    text-align: left;
    transition: background-color 0.12s ease;
}
.menu-item:hover {
    background-color: var(--wa-panel-hover);
}
.menu-icon {
    width: 1.125rem;
    height: 1.125rem;
    color: var(--wa-text-secondary);
    flex-shrink: 0;
}
.menu-item-danger {
    color: #ef4444;
}
.menu-item-danger .menu-icon {
    color: #ef4444;
}
.menu-item-danger:hover {
    background-color: rgba(239, 68, 68, 0.08);
}
</style>
