<script setup lang="ts">
import { ref, computed, onBeforeUnmount, watch, nextTick } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import type { Chat, Message } from '@/types';
import { formatPhone } from '@/utils/phone';
import { stripWaMarkup } from '@/utils/waMarkup';

const props = defineProps<{
    chat: Chat;
    messages?: Message[];
    /**
     * Другие чаты этого же контакта на ЛЮБЫХ других WA-номерах.
     * Используется для UI «общались на WA #1 и WA #2» —
     * единая клиентская база при раздельных чатах.
     */
    contactChats?: Chat[];
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'open-search'): void;
}>();

const working = ref(false);
const editOpen = ref(false);
const editName = ref('');
const savingContact = ref(false);
const saveError = ref<string | null>(null);

const displayName = computed(() =>
    props.chat.chat_name
        || props.chat.contact?.name
        || props.chat.contact?.push_name
        || formatPhone(props.chat.contact?.phone_number)
        || 'Без имени',
);

// For group chats there is no "phone number" to display; showing a numeric WA group id is confusing.
const phoneLabel = computed(() => (isGroup.value ? '' : formatPhone(props.chat.contact?.phone_number)));

const firstInitial = computed(() => (displayName.value || '?').charAt(0).toUpperCase());

/** Диалоги того же контакта на других WA-сессиях (исключая текущий чат). */
const otherSessionChats = computed<Chat[]>(() =>
    (props.contactChats || []).filter((c) => c.id !== props.chat.id && !c.is_group),
);

function formatChatTime(dateStr?: string | null): string {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    const now = new Date();
    if (d.toDateString() === now.toDateString()) {
        return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    }
    return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' });
}

const mediaCount = computed(() => {
    const list = props.messages || [];
    return list.filter((m: any) => m?.media || (m as any)?.media_id || m.type === 'image' || m.type === 'video' || m.type === 'document').length;
});

type GroupParticipant = {
    id: string;
    number: string | null;
    name: string | null;
    pushname: string | null;
    saved_name?: string | null;
    isBusiness: boolean;
    isAdmin: boolean;
    isSuperAdmin: boolean;
};

const isGroup = computed(() => !!props.chat.is_group);
const participantsLoading = ref(false);
const participantsError = ref<string | null>(null);
const participants = ref<GroupParticipant[]>([]);
const participantMenuOpen = ref(false);
const participantMenu = ref<{ x: number; y: number; p: GroupParticipant } | null>(null);
const participantSaving = ref(false);
const participantSaveError = ref<string | null>(null);
const participantName = ref('');

function participantLabel(p: GroupParticipant): string {
    if (p.saved_name) return p.saved_name.toString();
    if (p.pushname && p.pushname.trim()) return `~ ${p.pushname}`.toString();
    return (p.name || p.number || p.id).toString();
}

async function loadParticipants() {
    if (!isGroup.value) return;
    participantsLoading.value = true;
    participantsError.value = null;
    try {
        const { data } = await axios.get(route('chats.group-participants', props.chat.id));
        participants.value = (data.participants || []) as GroupParticipant[];
    } catch (e: any) {
        participants.value = [];
        participantsError.value = e?.response?.data?.error || 'Не удалось загрузить участников';
    } finally {
        participantsLoading.value = false;
    }
}

function openParticipantMenu(p: GroupParticipant, e: MouseEvent) {
    e.preventDefault();
    e.stopPropagation();
    participantSaveError.value = null;
    participantName.value = (p.saved_name || p.name || p.pushname || '').toString();
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    const MENU_WIDTH = 280;
    const MENU_HEIGHT_EST = 240;
    let x = e.clientX;
    let y = e.clientY;
    if (x + MENU_WIDTH + 8 > vw) x = vw - MENU_WIDTH - 8;
    if (y + MENU_HEIGHT_EST + 8 > vh) y = Math.max(8, vh - MENU_HEIGHT_EST - 8);
    participantMenu.value = { x, y, p };
    participantMenuOpen.value = true;
    nextTick(() => {});
}

function closeParticipantMenu() {
    participantMenuOpen.value = false;
    participantMenu.value = null;
    participantSaveError.value = null;
}

async function addParticipantToContacts() {
    const p = participantMenu.value?.p;
    if (!p?.number) return;
    const name = participantName.value.trim();
    participantSaving.value = true;
    participantSaveError.value = null;
    try {
        await axios.post(route('contacts.upsert'), { phone: p.number, name: name || null });
        await loadParticipants();
        closeParticipantMenu();
    } catch (e: any) {
        participantSaveError.value = e?.response?.data?.message || e?.response?.data?.error || 'Не удалось добавить контакт';
    } finally {
        participantSaving.value = false;
    }
}

function writePrivately() {
    const p = participantMenu.value?.p;
    if (!p?.number) return;
    closeParticipantMenu();
    router.post(route('chats.start'), { phone: p.number, whatsapp_session_id: props.chat.whatsapp_session_id }, {
        onFinish: () => emit('close'),
    });
}

watch(
    () => props.chat.id,
    () => {
        participants.value = [];
        participantsError.value = null;
        if (isGroup.value) loadParticipants();
    },
    { immediate: true },
);

function onEscape(e: KeyboardEvent) {
    if (e.key === 'Escape') emit('close');
}
window.addEventListener('keydown', onEscape);
onBeforeUnmount(() => window.removeEventListener('keydown', onEscape));

async function togglePin() {
    if (working.value) return;
    working.value = true;
    try {
        await axios.post(route('chats.toggle-pin', props.chat.id));
        router.reload({ only: ['chat', 'chats'] });
    } finally {
        working.value = false;
    }
}

async function clearChat() {
    if (!confirm('Очистить всю историю этого чата? Это действие необратимо.')) return;
    working.value = true;
    try {
        await axios.post(route('chats.clear', props.chat.id));
        router.reload({ only: ['messages', 'chat'] });
    } finally {
        working.value = false;
    }
}

function notImplemented(name: string) {
    alert(`«${name}» — скоро будет доступно.`);
}

function openEdit() {
    saveError.value = null;
    editName.value = (props.chat.contact?.name || '').toString();
    editOpen.value = true;
}

function closeEdit() {
    editOpen.value = false;
    editName.value = '';
    saveError.value = null;
}

async function saveContactName() {
    if (savingContact.value) return;
    saveError.value = null;
    const name = editName.value.trim();
    if (!name) {
        saveError.value = 'Введите имя контакта';
        return;
    }
    savingContact.value = true;
    try {
        await axios.post(route('chats.save-contact', props.chat.id), { name });
        closeEdit();
        router.reload({ only: ['chat', 'chats', 'messages'] });
    } catch (e: any) {
        saveError.value = e?.response?.data?.message || e?.response?.data?.error || 'Не удалось сохранить контакт';
    } finally {
        savingContact.value = false;
    }
}
</script>

<template>
    <aside
        class="w-[400px] shrink-0 h-full flex flex-col border-l overflow-hidden"
        :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
    >
        <!-- Header -->
        <div
            class="h-[60px] px-4 flex items-center gap-5 shrink-0"
            :style="{ background: 'var(--wa-panel-header)' }"
        >
            <button
                @click="emit('close')"
                class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)]"
                title="Закрыть"
                type="button"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 class="text-base flex-1" :style="{ color: 'var(--wa-text)' }">
                Данные контакта
            </h2>
            <button
                @click="openEdit"
                class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)]"
                :title="chat.contact?.name ? 'Редактировать контакт' : 'Добавить в контакты'"
                type="button"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
            </button>
        </div>

        <!-- Edit contact modal -->
        <teleport to="body">
            <div
                v-if="editOpen"
                class="fixed inset-0 z-[200] flex items-center justify-center px-4"
                :style="{ background: 'rgba(0,0,0,.45)' }"
                @click.self="closeEdit"
            >
                <div class="w-full max-w-[420px] rounded-2xl border overflow-hidden"
                     :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
                >
                    <div class="px-5 py-4 flex items-center justify-between"
                         :style="{ background: 'var(--wa-panel-header)' }"
                    >
                        <div class="text-sm font-medium" :style="{ color: 'var(--wa-text)' }">
                            {{ chat.contact?.name ? 'Редактировать контакт' : 'Добавить контакт' }}
                        </div>
                        <button type="button" class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)]" @click="closeEdit">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div class="p-5">
                        <label class="block text-xs mb-2" :style="{ color: 'var(--wa-text-secondary)' }">Имя контакта</label>
                        <input
                            v-model="editName"
                            type="text"
                            class="w-full px-4 py-2.5 rounded-xl border-0 focus:ring-0 focus:outline-none"
                            :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-text)' }"
                            placeholder="Например: Айбек"
                            @keydown.enter.prevent="saveContactName"
                        />
                        <div v-if="saveError" class="text-xs mt-2" style="color: #ff6b6b;">
                            {{ saveError }}
                        </div>
                        <div class="mt-4 flex gap-2 justify-end">
                            <button type="button" class="px-4 py-2 rounded-xl hover:bg-[var(--wa-panel-hover)]"
                                    :style="{ color: 'var(--wa-text)' }"
                                    @click="closeEdit"
                            >
                                Отмена
                            </button>
                            <button
                                type="button"
                                class="px-4 py-2 rounded-xl"
                                :disabled="savingContact"
                                :style="{ background: 'var(--wa-accent)', color: 'white', opacity: savingContact ? 0.7 : 1 }"
                                @click="saveContactName"
                            >
                                Сохранить
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </teleport>

        <!-- Scrollable content -->
        <div class="flex-1 overflow-y-auto wa-scrollbar">
            <!-- Avatar header -->
            <div class="py-8 flex flex-col items-center px-6">
                <div
                    v-if="chat.contact?.profile_picture_url"
                    class="w-[200px] h-[200px] rounded-full overflow-hidden mb-5 bg-[#6b7c85]"
                >
                    <img
                        :src="chat.contact.profile_picture_url"
                        class="w-full h-full object-cover"
                        alt=""
                    />
                </div>
                <div
                    v-else
                    class="w-[200px] h-[200px] rounded-full flex items-center justify-center text-white text-[80px] font-medium mb-5"
                    :class="chat.is_group ? 'bg-[var(--wa-accent)]' : 'bg-[#6b7c85]'"
                >
                    {{ firstInitial }}
                </div>
                <div class="text-[26px] text-center" :style="{ color: 'var(--wa-text)' }">
                    {{ displayName }}
                </div>
                <div
                    v-if="phoneLabel"
                    class="text-sm mt-1"
                    :style="{ color: 'var(--wa-text-secondary)' }"
                >
                    {{ phoneLabel }}
                </div>
            </div>

            <!-- Куда клиент писал: список WA-номеров, через которые велась переписка.
                 Показывается только если клиент общался более чем на одной сессии. -->
            <div v-if="otherSessionChats.length" class="px-4 pb-4">
                <div class="flex items-center gap-2 mb-2">
                    <svg class="w-4 h-4" :style="{ color: 'var(--wa-text-secondary)' }" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 5h2l3.6 7.59-1.35 2.44c-.15.27-.24.58-.24.92 0 1.05.94 1.96 2 1.96h12V16H8.42c-.13 0-.24-.11-.24-.24l.03-.12.9-1.64h7.45c.75 0 1.4-.41 1.75-1.03l3.58-6.5A1 1 0 0020.01 5H5.21L4.27 3H1v2h2zM7 20a2 2 0 104 0 2 2 0 00-4 0zm10 0a2 2 0 104 0 2 2 0 00-4 0z" />
                    </svg>
                    <span class="text-sm" :style="{ color: 'var(--wa-text-secondary)' }">
                        Этот клиент общался с вами на других номерах
                    </span>
                </div>
                <div class="flex flex-col gap-1.5">
                    <Link
                        v-for="c in otherSessionChats"
                        :key="c.id"
                        :href="route('chats.show', c.id)"
                        class="sibling-row"
                    >
                        <span
                            class="w-2 h-2 rounded-full shrink-0"
                            :class="c.whatsapp_session?.status === 'connected' ? 'bg-[#25d366]' : 'bg-gray-400'"
                        ></span>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-1.5">
                                <span class="sibling-name truncate">
                                    {{ c.whatsapp_session?.display_name || c.whatsapp_session?.session_name }}
                                </span>
                                <span
                                    v-if="c.whatsapp_session?.phone_number"
                                    class="text-[11px] tabular-nums"
                                    :style="{ color: 'var(--wa-text-secondary)' }"
                                >
                                    {{ formatPhone(c.whatsapp_session.phone_number) }}
                                </span>
                            </div>
                            <div v-if="c.last_message_text" class="sibling-preview truncate">
                                {{ stripWaMarkup(c.last_message_text) }}
                            </div>
                        </div>
                        <div class="flex flex-col items-end shrink-0 gap-1">
                            <span class="text-[11px]" :style="{ color: 'var(--wa-text-secondary)' }">
                                {{ formatChatTime(c.last_message_at) }}
                            </span>
                            <span
                                v-if="c.unread_count && c.unread_count > 0"
                                class="min-w-[18px] h-[18px] rounded-full text-[10px] font-semibold flex items-center justify-center px-1"
                                :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                            >
                                {{ c.unread_count > 99 ? '99+' : c.unread_count }}
                            </span>
                        </div>
                    </Link>
                </div>
            </div>

            <!-- 3 action tiles -->
            <div class="px-4 pb-4 grid grid-cols-3 gap-2">
                <button class="action-tile" @click="emit('open-search')" type="button">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <span>Поиск</span>
                </button>
                <button class="action-tile" @click="notImplemented('Видеозвонок')" type="button">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                    <span>Видео</span>
                </button>
                <button class="action-tile" @click="notImplemented('Аудиозвонок')" type="button">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M19.05 17.34l-2.39-2.39a1.49 1.49 0 00-2.11 0l-.75.75a9.02 9.02 0 01-4.5-4.5l.75-.75a1.49 1.49 0 000-2.11L7.66 5.95a1.49 1.49 0 00-2.11 0l-1.3 1.3a2 2 0 00-.46 2.12A18 18 0 0015.63 21a2 2 0 002.12-.46l1.3-1.3a1.49 1.49 0 000-1.9z"/>
                    </svg>
                    <span>Аудио</span>
                </button>
            </div>

            <div class="h-2" :style="{ background: 'var(--wa-bg)' }"></div>

            <!-- Group participants -->
            <div v-if="isGroup" class="py-2">
                <div class="px-4 pt-3 pb-2 text-xs uppercase tracking-wide" :style="{ color: 'var(--wa-text-secondary)' }">
                    Участники
                </div>

                <div v-if="participantsLoading" class="px-4 pb-4 text-sm" :style="{ color: 'var(--wa-text-secondary)' }">
                    Загрузка…
                </div>
                <div v-else-if="participantsError" class="px-4 pb-4 text-sm" :style="{ color: 'var(--wa-danger)' }">
                    {{ participantsError }}
                </div>
                <div v-else class="pb-2">
                    <button
                        v-for="p in participants"
                        :key="p.id"
                        class="info-row w-full"
                        type="button"
                        @click="openParticipantMenu(p, $event)"
                    >
                        <svg class="info-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m6-4a4 4 0 10-8 0 4 4 0 008 0zm6 1a3 3 0 10-6 0 3 3 0 006 0z" />
                        </svg>
                        <div class="flex-1 min-w-0 text-left">
                            <div class="info-label-inline">
                                {{ participantLabel(p) }}
                                <span v-if="p.isSuperAdmin" class="ml-2 text-[11px]" :style="{ color: 'var(--wa-accent)' }">владелец</span>
                                <span v-else-if="p.isAdmin" class="ml-2 text-[11px]" :style="{ color: 'var(--wa-accent)' }">админ</span>
                            </div>
                            <div v-if="p.number" class="info-sublabel">{{ p.number }}</div>
                        </div>
                    </button>

                    <div v-if="participants.length === 0" class="px-4 pb-4 text-sm" :style="{ color: 'var(--wa-text-secondary)' }">
                        Участники не найдены
                    </div>
                </div>
            </div>

            <div class="h-2" :style="{ background: 'var(--wa-bg)' }"></div>

            <!-- Info rows -->
            <div class="py-2">
                <button class="info-row w-full" @click="notImplemented('Медиа, ссылки и документы')" type="button">
                    <svg class="info-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    <span class="info-label">Медиа, ссылки и документы</span>
                    <span class="info-meta">
                        {{ mediaCount }}
                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                        </svg>
                    </span>
                </button>

                <button class="info-row w-full" @click="notImplemented('Избранные сообщения')" type="button">
                    <svg class="info-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.539 1.118L12 16.98l-3.976 2.888c-.784.57-1.838-.196-1.539-1.118l1.518-4.674a1 1 0 00-.363-1.118L3.664 10.1c-.783-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.673z" />
                    </svg>
                    <span class="info-label">Избранные</span>
                </button>

                <button class="info-row w-full" @click="notImplemented('Настройки уведомлений')" type="button">
                    <svg class="info-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    <span class="info-label">Настройки уведомлений</span>
                </button>
            </div>

            <div class="h-2" :style="{ background: 'var(--wa-bg)' }"></div>

            <!-- Pin & list -->
            <div class="py-2">
                <button class="info-row w-full" @click="togglePin" type="button" :disabled="working">
                    <svg class="info-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                    </svg>
                    <span class="info-label">
                        {{ chat.is_pinned ? 'Убрать из избранного' : 'Добавить в избранное' }}
                    </span>
                </button>

                <button class="info-row w-full" @click="notImplemented('Добавить в список')" type="button">
                    <svg class="info-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h10M4 18h10M19 15v6m-3-3h6" />
                    </svg>
                    <span class="info-label">Добавить в список</span>
                </button>
            </div>

            <div class="h-2" :style="{ background: 'var(--wa-bg)' }"></div>

            <!-- Danger actions -->
            <div class="py-2 pb-6">
                <button class="info-row info-row-danger w-full" @click="clearChat" type="button" :disabled="working">
                    <svg class="info-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="9" />
                        <line x1="7" y1="12" x2="17" y2="12" stroke-linecap="round" />
                    </svg>
                    <span class="info-label">Очистить чат</span>
                </button>

                <button class="info-row info-row-danger w-full" @click="notImplemented('Блокировка контакта')" type="button">
                    <svg class="info-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="9" />
                        <line x1="5.5" y1="5.5" x2="18.5" y2="18.5" stroke-linecap="round" />
                    </svg>
                    <span class="info-label truncate">Заблокировать {{ displayName }}</span>
                </button>

                <button class="info-row info-row-danger w-full" @click="notImplemented('Жалоба')" type="button">
                    <svg class="info-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span class="info-label truncate">Пожаловаться на {{ displayName }}</span>
                </button>

            </div>
        </div>
    </aside>

    <!-- Participant actions menu -->
    <teleport to="body">
        <div v-if="participantMenuOpen && participantMenu">
            <div class="fixed inset-0 z-40" @click="closeParticipantMenu" @contextmenu.prevent="closeParticipantMenu"></div>
            <div
                class="fixed z-50 min-w-[280px] rounded-xl shadow-2xl border py-2"
                :style="{
                    left: participantMenu.x + 'px',
                    top: participantMenu.y + 'px',
                    background: 'var(--wa-panel-header)',
                    borderColor: 'var(--wa-border-strong)',
                }"
            >
                <div class="px-4 pt-2 pb-1 text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                    {{ participantLabel(participantMenu.p) }}
                </div>

                <div class="px-4 pb-2">
                    <label class="block text-[11px] mb-1" :style="{ color: 'var(--wa-text-secondary)' }">
                        Имя для сохранения
                    </label>
                    <input
                        v-model="participantName"
                        type="text"
                        class="w-full px-3 py-2 rounded-xl border-0 focus:ring-0 focus:outline-none"
                        :style="{ background: 'var(--wa-panel)', color: 'var(--wa-text)' }"
                        placeholder="Введите имя…"
                    />
                    <div v-if="participantSaveError" class="text-[11px] mt-2" style="color:#ff6b6b;">
                        {{ participantSaveError }}
                    </div>
                </div>

                <button
                    class="msg-menu-item"
                    type="button"
                    :disabled="participantSaving || !participantMenu.p.number"
                    @click="addParticipantToContacts"
                >
                    <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                    </svg>
                    Добавить в контакты
                </button>

                <button
                    class="msg-menu-item"
                    type="button"
                    :disabled="!participantMenu.p.number"
                    @click="writePrivately"
                >
                    <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5m7 7l-3.5-3.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Написать лично
                </button>
            </div>
        </div>
    </teleport>
</template>

<style scoped>
.action-tile {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.375rem;
    padding: 0.75rem 0.5rem;
    border-radius: 0.5rem;
    font-size: 0.8125rem;
    color: var(--wa-accent);
    background-color: var(--wa-panel-header);
    border: 1px solid var(--wa-border);
    transition: background-color 0.15s ease;
}
.action-tile:hover {
    background-color: var(--wa-panel-hover);
}
.info-row {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    padding: 0.875rem 1.25rem;
    text-align: left;
    color: var(--wa-text);
    transition: background-color 0.12s ease;
}
.info-row:not(:disabled):hover {
    background-color: var(--wa-panel-hover);
}
.info-row:disabled {
    cursor: not-allowed;
    opacity: 0.7;
}
.info-icon {
    width: 1.25rem;
    height: 1.25rem;
    color: var(--wa-text-secondary);
    flex-shrink: 0;
}
.info-label {
    flex: 1;
    font-size: 0.9375rem;
    min-width: 0;
    text-align: left;
}
.info-label-inline {
    font-size: 0.9375rem;
}
.info-sublabel {
    font-size: 0.8125rem;
    color: var(--wa-text-secondary);
    margin-top: 0.125rem;
}
.info-meta {
    display: inline-flex;
    align-items: center;
    font-size: 0.875rem;
    color: var(--wa-text-secondary);
}
.info-row-danger {
    color: #ef4444;
}
.info-row-danger .info-icon {
    color: #ef4444;
}
.info-row-danger:hover {
    background-color: rgba(239, 68, 68, 0.08);
}
.sibling-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.625rem;
    border-radius: 0.5rem;
    background-color: var(--wa-panel-header);
    border: 1px solid var(--wa-border);
    text-decoration: none;
    color: inherit;
    transition: background-color 0.12s ease, border-color 0.12s ease;
}
.sibling-row:hover {
    background-color: var(--wa-panel-hover);
    border-color: var(--wa-border-strong);
}
.sibling-name {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--wa-text);
}
.sibling-preview {
    margin-top: 2px;
    font-size: 0.75rem;
    color: var(--wa-text-secondary);
    max-width: 260px;
}
</style>
