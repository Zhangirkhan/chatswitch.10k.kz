<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ref, onBeforeUnmount, computed, nextTick } from 'vue';
import axios from 'axios';
import Avatar from '@/Components/Avatar.vue';
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import MuteChatDialog from '@/Pages/Chats/Partials/MuteChatDialog.vue';
import BellOffIcon from '@/Components/icons/BellOffIcon.vue';
import LastMessagePreview from '@/Components/LastMessagePreview.vue';
import { useToastStore } from '@/stores/toast';
import type { Chat, PageProps } from '@/types';
import { formatPhone } from '@/utils/phone';
import { appendChatListOwnership } from '@/utils/chatListOwnershipUrl';

const { show: showToast } = useToastStore();

const props = defineProps<{
    chat: Chat;
    isSelected: boolean;
}>();

const page = usePage<PageProps>();

const chatShowHref = computed(() =>
    appendChatListOwnership(route('chats.show', props.chat.id), page.props.listOwnership as string | undefined),
);

/** Плашки «кто закреплён» — только у администратора и руководителя. */
const showAssigneeBadges = computed(() => {
    const roles = page.props.auth?.user?.roles ?? [];
    return roles.includes('administrator') || roles.includes('manager');
});

const assigneeNames = computed(() =>
    (props.chat.assignments ?? [])
        .map((a) => a.user?.name?.trim())
        .filter((n): n is string => Boolean(n && n.length > 0)),
);

/** Все ответственные в плашке списком (без «Имя +N»). */
const assigneePillLabel = computed(() => assigneeNames.value.join(', '));

const assigneePillTitle = computed(() =>
    assigneeNames.value.length > 0 ? `Ответственные: ${assigneeNames.value.join(', ')}` : '',
);

/** Плашка закреплённых только если есть хотя бы один назначенный. */
const showAssigneePill = computed(
    () => showAssigneeBadges.value && assigneeNames.value.length > 0,
);

const showAiPill = computed(() => props.chat.ai_enabled === true);

const attentionReason = computed(() => props.chat.attention_reason?.trim() || '');
const showAttentionPill = computed(() => attentionReason.value.length > 0);

const attentionPillStyle = computed<Record<string, string>>(() => {
    switch (props.chat.attention_severity) {
        case 'critical':
            return { background: 'var(--wa-chroma-critical-bg)', color: 'var(--wa-chroma-critical-fg)' };
        case 'danger':
            return { background: 'var(--wa-chroma-orange-bg)', color: 'var(--wa-chroma-orange-fg)' };
        case 'warning':
            return { background: 'var(--wa-chroma-yellow-bg)', color: 'var(--wa-chroma-yellow-fg)' };
        default:
            return { background: 'var(--wa-accent-soft)', color: 'var(--wa-chroma-accent-fg)' };
    }
});

const menuOpen = ref(false);
const menuX = ref(0);
const menuY = ref(0);
const working = ref(false);
const clearChatDialogOpen = ref(false);
const muteDialogOpen = ref(false);

const MENU_WIDTH = 240;
const MENU_HEIGHT_ESTIMATE = 420;

async function openMenu(e: MouseEvent) {
    e.preventDefault();
    e.stopPropagation();

    const vw = window.innerWidth;
    const vh = window.innerHeight;
    let x = e.clientX;
    let y = e.clientY;
    if (x + MENU_WIDTH + 8 > vw) x = vw - MENU_WIDTH - 8;
    if (y + MENU_HEIGHT_ESTIMATE + 8 > vh) y = Math.max(8, vh - MENU_HEIGHT_ESTIMATE - 8);

    menuX.value = x;
    menuY.value = y;
    menuOpen.value = true;
    await nextTick();
}

async function openMenuFromChevron(e: MouseEvent) {
    e.preventDefault();
    e.stopPropagation();

    const target = e.currentTarget as HTMLElement | null;
    const rect = target?.getBoundingClientRect();
    const vw = window.innerWidth;
    const vh = window.innerHeight;

    let x = rect ? rect.right - MENU_WIDTH : e.clientX;
    let y = rect ? rect.bottom + 4 : e.clientY;
    if (x + MENU_WIDTH + 8 > vw) x = vw - MENU_WIDTH - 8;
    if (x < 8) x = 8;
    if (y + MENU_HEIGHT_ESTIMATE + 8 > vh) y = Math.max(8, vh - MENU_HEIGHT_ESTIMATE - 8);

    menuX.value = x;
    menuY.value = y;
    menuOpen.value = true;
    await nextTick();
}

function closeMenu() {
    menuOpen.value = false;
}

const CHATS_PARTIAL_RELOAD = ['chats', 'archivedCount', 'unreadChatsCount', 'unreadChatsCountMine', 'listOwnership', 'mineChatsTotal'] as const;

async function run(action: () => Promise<unknown>, reloadKeys: string[] = [...CHATS_PARTIAL_RELOAD]) {
    if (working.value) return;
    working.value = true;
    try {
        await action();
    } finally {
        working.value = false;
        closeMenu();
        router.reload({ only: reloadKeys });
    }
}

function togglePin() {
    const wasPinned = props.chat.is_pinned;
    run(() => axios.post(route('chats.toggle-pin', props.chat.id))).then(() => {
        showToast({
            message: wasPinned ? 'Чат откреплён' : 'Чат закреплён',
            action: {
                label: 'Отменить',
                handler: async () => {
                    await axios.post(route('chats.toggle-pin', props.chat.id));
                    router.reload({ only: [...CHATS_PARTIAL_RELOAD] });
                },
            },
        });
    });
}

function toggleArchive() {
    const wasArchived = props.chat.is_archived;
    run(() => axios.post(route('chats.archive', props.chat.id))).then(() => {
        showToast({
            message: wasArchived ? 'Чат разархивирован' : 'Чат архивирован',
            action: {
                label: 'Отменить',
                handler: async () => {
                    await axios.post(route('chats.archive', props.chat.id));
                    router.reload({ only: [...CHATS_PARTIAL_RELOAD] });
                },
            },
        });
    });
}

const isGroupChat = computed<boolean>(() => props.chat.is_group);

async function muteApi(payload: { duration?: '8h' | '1w' | 'always'; unmute?: boolean }): Promise<void> {
    await axios.post(route('chats.toggle-mute', props.chat.id), payload);
    router.reload({ only: ['chats', 'chat', 'unreadChatsCount', 'unreadChatsCountMine', 'listOwnership', 'mineChatsTotal'] });
}

function toggleMute() {
    if (props.chat.is_muted) {
        closeMenu();
        muteApi({ unmute: true }).then(() => {
            showToast({
                message: isGroupChat.value
                    ? 'Уведомления в группе включены'
                    : 'Уведомления включены',
            });
        });
        return;
    }
    closeMenu();
    muteDialogOpen.value = true;
}

function onMuteConfirm(duration: '8h' | '1w' | 'always') {
    muteDialogOpen.value = false;
    muteApi({ duration }).then(() => {
        showToast({
            message: isGroupChat.value
                ? 'Уведомления в группе отключены'
                : 'Уведомления отключены',
            action: {
                label: 'Отменить',
                handler: async () => {
                    await muteApi({ unmute: true });
                    showToast({
                        message: isGroupChat.value
                            ? 'Уведомления в группе включены'
                            : 'Уведомления включены',
                    });
                },
            },
        });
    });
}

function toggleFavorite() {
    const wasFavorite = props.chat.is_favorite;
    run(() => axios.post(route('chats.toggle-favorite', props.chat.id))).then(() => {
        showToast({
            message: wasFavorite ? 'Удалено из избранного' : 'Добавлено в избранное',
            action: {
                label: 'Отменить',
                handler: async () => {
                    await axios.post(route('chats.toggle-favorite', props.chat.id));
                    router.reload({ only: [...CHATS_PARTIAL_RELOAD] });
                },
            },
        });
    });
}

function toggleUnread() {
    const wasUnread = props.chat.unread_count > 0;
    run(() => axios.post(route('chats.toggle-unread', props.chat.id))).then(() => {
        showToast({
            message: wasUnread ? 'Чат помечен как прочитанный' : 'Чат помечен как непрочитанный',
            action: {
                label: 'Отменить',
                handler: async () => {
                    await axios.post(route('chats.toggle-unread', props.chat.id));
                    router.reload({ only: [...CHATS_PARTIAL_RELOAD] });
                },
            },
        });
    });
}

function openClearChatDialog(): void {
    closeMenu();
    clearChatDialogOpen.value = true;
}

async function confirmClearChat(): Promise<void> {
    if (working.value) return;
    clearChatDialogOpen.value = false;
    working.value = true;
    try {
        await axios.post(route('chats.clear', props.chat.id));
        router.reload({ only: ['chats', 'messages', 'chat'] });
        showToast({ message: 'Чат очищен' });
    } finally {
        working.value = false;
        closeMenu();
    }
}

function notImplemented(name: string) {
    closeMenu();
    showToast({
        message: `«${name}» — функция скоро будет доступна`,
    });
}

function onEscape(e: KeyboardEvent) {
    if (e.key === 'Escape') {
        if (clearChatDialogOpen.value) {
            clearChatDialogOpen.value = false;
            return;
        }
        closeMenu();
    }
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

const displayName = computed(
    () =>
        props.chat.chat_name ||
        props.chat.contact?.name ||
        (props.chat.contact?.push_name ? `~ ${props.chat.contact.push_name}` : '') ||
        formatPhone(props.chat.contact?.phone_number) ||
        '',
);

const muteSubtitle = computed<string>(() => {
    if (!props.chat.is_muted) return '';
    if (!props.chat.muted_until) return 'Всегда';

    const until = new Date(props.chat.muted_until);
    const now = new Date();

    const time = until.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });

    const isToday = until.toDateString() === now.toDateString();
    if (isToday) return `Уведомления выключены до ${time} сегодня`;

    const tomorrow = new Date(now);
    tomorrow.setDate(tomorrow.getDate() + 1);
    if (until.toDateString() === tomorrow.toDateString()) {
        return `Уведомления выключены до ${time} завтра`;
    }

    const date = until.toLocaleDateString('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        year: '2-digit',
    });
    return `Уведомления выключены до ${time} ${date}`;
});

function getSessionLabel(chat: Chat): string {
    if (!chat.whatsapp_session) return '';
    return chat.whatsapp_session.display_name?.trim()
        || formatPhone(chat.whatsapp_session.phone_number)
        || '';
}

function sessionTooltip(chat: Chat): string {
    const s = chat.whatsapp_session;
    if (!s) return '';
    const num = formatPhone(s.phone_number) || (s.phone_number || '');
    const label = s.display_name?.trim() || '';
    return [label, num].filter(Boolean).join(' · ');
}

function sessionBadgeStyle(chat: Chat): Record<string, string> {
    const c = chat.whatsapp_session?.display_color || '';
    const color = c.trim();
    if (!color) {
        return { background: 'var(--wa-accent-soft)', color: 'var(--wa-accent)' };
    }
    // Use rgba background for widest browser support (avoid 8-digit hex).
    const m = /^#?([0-9a-f]{6})$/i.exec(color);
    if (m) {
        const hex = m[1]!;
        const r = parseInt(hex.slice(0, 2), 16);
        const g = parseInt(hex.slice(2, 4), 16);
        const b = parseInt(hex.slice(4, 6), 16);
        return { background: `rgba(${r}, ${g}, ${b}, 0.13)`, color: `#${hex}` };
    }
    return { background: 'rgba(37, 211, 102, 0.16)', color };
}
</script>

<template>
    <Link
        :href="chatShowHref"
        class="flex items-center px-3 py-[10px] gap-3 cursor-pointer transition group chat-list-item"
        :class="isSelected ? 'is-selected' : ''"
        preserve-state
        @contextmenu="openMenu"
    >
        <Avatar
            :avatar-url="chat.contact?.profile_picture_url"
            :name="displayName"
            :is-group="chat.is_group"
            :size="49"
        />

        <div class="flex-1 min-w-0 border-b border-[var(--wa-divider)] group-hover:border-transparent pb-3 -mb-3 pt-0.5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-1.5 min-w-0">
                    <span class="text-[var(--wa-text)] text-base truncate">
                        {{
                            chat.chat_name
                            || chat.contact?.name
                            || (chat.contact?.push_name ? `~ ${chat.contact.push_name}` : null)
                            || formatPhone(chat.contact?.phone_number)
                            || 'Без имени'
                        }}
                    </span>
                </div>
                <span
                    class="text-xs shrink-0 ml-1"
                    :style="{ color: chat.unread_count > 0 ? 'var(--wa-accent)' : 'var(--wa-text-secondary)' }"
                >
                    {{ formatTime(chat.last_message_at) }}
                </span>
            </div>
            <div class="flex items-start justify-between gap-1 mt-1">
                <div class="flex flex-col gap-1 min-w-0 flex-1">
                    <LastMessagePreview
                        :chat="chat"
                        class="text-sm text-[var(--wa-text-secondary)] truncate min-w-0"
                    />
                    <div
                        v-if="getSessionLabel(chat) || showAssigneePill || showAiPill || showAttentionPill"
                        class="flex max-w-full min-w-0 flex-col items-start gap-1"
                    >
                        <span
                            v-if="getSessionLabel(chat)"
                            class="max-w-full truncate text-[10px] px-1.5 py-0 rounded font-medium"
                            :style="sessionBadgeStyle(chat)"
                            :title="sessionTooltip(chat)"
                        >
                            {{ getSessionLabel(chat) }}
                        </span>
                        <span
                            v-if="showAssigneePill"
                            class="min-w-0 max-w-full text-[10px] px-1.5 py-0.5 rounded font-medium assignee-pill assignee-pill-expanded leading-snug"
                            :title="assigneePillTitle"
                        >
                            {{ assigneePillLabel }}
                        </span>
                        <span
                            v-if="showAiPill"
                            class="text-[10px] px-1.5 py-0.5 rounded font-semibold ai-pill leading-none"
                            title="AI-ассистент включён в этом чате"
                        >
                            AI
                        </span>
                        <span
                            v-if="showAttentionPill"
                            class="max-w-full truncate text-[10px] px-1.5 py-0.5 rounded font-medium leading-snug"
                            :style="attentionPillStyle"
                            :title="attentionReason"
                        >
                            {{ attentionReason }}
                        </span>
                    </div>
                </div>
                <div class="ml-1 shrink-0 flex items-center gap-1 bottom-meta self-center">
                    <BellOffIcon
                        v-if="chat.is_muted"
                        :size="18"
                        class="text-[var(--wa-text-secondary)] shrink-0"
                    />
                    <svg
                        v-if="chat.is_pinned"
                        class="w-4 h-4 text-[var(--wa-text-secondary)] shrink-0"
                        fill="currentColor"
                        viewBox="0 0 24 24"
                        title="Чат закреплён"
                        aria-hidden="true"
                    >
                        <path d="M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2l-2-2z"/>
                    </svg>
                    <span
                        v-if="chat.unread_count > 0"
                        class="unread-badge min-w-[20px] h-[20px] rounded-full text-[11px] font-semibold flex items-center justify-center px-1.5 shrink-0"
                        :style="{ background: 'var(--wa-unread)', color: 'var(--wa-unread-text)' }"
                    >
                        {{ chat.unread_count > 99 ? '99+' : chat.unread_count }}
                    </span>
                    <button
                        type="button"
                        class="chevron-btn"
                        :class="{ 'is-open': menuOpen }"
                        title="Меню"
                        @click.prevent.stop="openMenuFromChevron"
                    >
                        <svg class="w-[18px] h-[18px]" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M12 15.5a1 1 0 01-.71-.29l-5-5a1 1 0 011.42-1.42L12 13.09l4.29-4.3a1 1 0 011.42 1.42l-5 5a1 1 0 01-.71.29z" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </Link>

    <!-- Context menu -->
    <teleport to="body">
        <div v-if="menuOpen">
            <div class="fixed inset-0 z-40" @click="closeMenu" @contextmenu.prevent="closeMenu"></div>
            <div
                class="fixed z-50 min-w-[240px] rounded-lg shadow-xl py-2 border context-menu"
                :style="{
                    left: menuX + 'px',
                    top: menuY + 'px',
                    background: 'var(--wa-panel-header)',
                    borderColor: 'var(--wa-control-rim)', boxShadow: 'var(--wa-control-rim-shadow)',
                }"
            >
                <button class="menu-item" @click.prevent="toggleArchive" type="button">
                    <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v1a2 2 0 01-2 2M5 8v11a2 2 0 002 2h10a2 2 0 002-2V8M10 12h4" />
                    </svg>
                    <span>{{ chat.is_archived ? 'Разархивировать чат' : 'Архивировать чат' }}</span>
                </button>

                <button class="menu-item" @click.prevent="toggleMute" type="button">
                    <svg
                        v-if="!chat.is_muted"
                        class="menu-icon"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                        viewBox="0 0 24 24"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M8.7 3A6 6 0 0 1 18 8c0 2.2.2 3.8.5 5" />
                        <path d="M17 17H3s3-2 3-9a5 5 0 0 1 .3-1.7" />
                        <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                    </svg>
                    <BellOffIcon
                        v-else
                        :size="18"
                        class="menu-icon"
                    />
                    <div class="flex flex-col items-start text-left">
                        <span>{{ chat.is_muted ? 'Включить звук' : 'Без звука' }}</span>
                        <span v-if="chat.is_muted && muteSubtitle" class="menu-item-subtitle">
                            {{ muteSubtitle }}
                        </span>
                    </div>
                </button>

                <button class="menu-item" @click.prevent="togglePin" type="button">
                    <svg class="menu-icon" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2l-2-2z" />
                    </svg>
                    <span>{{ chat.is_pinned ? 'Открепить чат' : 'Закрепить чат' }}</span>
                </button>

                <button class="menu-item" @click.prevent="toggleUnread" type="button">
                    <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l9 6 9-6M3 8v10a2 2 0 002 2h14a2 2 0 002-2V8M3 8l9-5 9 5" />
                    </svg>
                    <span>
                        {{ chat.unread_count > 0 ? 'Пометить как прочитанное' : 'Пометить как непрочитанное' }}
                    </span>
                </button>

                <button class="menu-item" @click.prevent="toggleFavorite" type="button">
                    <svg class="menu-icon" :fill="chat.is_favorite ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 016.364 0L12 7.636l1.318-1.318a4.5 4.5 0 116.364 6.364L12 20.364l-7.682-7.682a4.5 4.5 0 010-6.364z" />
                    </svg>
                    <span>{{ chat.is_favorite ? 'Убрать из избранного' : 'Добавить в избранное' }}</span>
                </button>

                <button class="menu-item" @click.prevent="notImplemented('Добавить в список')" type="button">
                    <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h10M4 18h10M19 15v6m-3-3h6" />
                    </svg>
                    <span>Добавить в список</span>
                </button>

                <div class="menu-divider"></div>

                <button class="menu-item" @click.prevent="openClearChatDialog" type="button">
                    <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728L5.636 5.636m12.728 12.728A9 9 0 015.636 5.636" />
                    </svg>
                    <span>Очистить чат</span>
                </button>
            </div>
        </div>
    </teleport>

    <DangerConfirmModal
        :open="clearChatDialogOpen"
        title="Очистить историю чата?"
        description="Все сообщения в этом чате будут удалены. Действие необратимо."
        confirm-label="Очистить"
        :busy="working"
        confirm-variant="danger"
        @close="clearChatDialogOpen = false"
        @confirm="confirmClearChat"
    />

    <MuteChatDialog
        :show="muteDialogOpen"
        @close="muteDialogOpen = false"
        @confirm="onMuteConfirm"
    />
</template>

<style scoped>
.chat-list-item {
    --icon-cutout: var(--wa-panel);
    transition: background-color 0.15s ease;
}
.chat-list-item:hover {
    --icon-cutout: var(--wa-panel-hover);
    background-color: var(--wa-panel-hover);
}
.chat-list-item.is-selected {
    --icon-cutout: var(--wa-selected);
    background-color: var(--wa-selected);
}

/* Плашка закреплённых (отличается от зелёной плашки WhatsApp-сессии). */
.assignee-pill {
    background: var(--wa-chroma-accent-bg-18);
    color: var(--wa-assignee-pill-fg);
    border: 1px solid var(--wa-chroma-accent-border-45);
}
.assignee-pill-expanded {
    white-space: normal;
    overflow-wrap: anywhere;
    word-break: break-word;
}
.ai-pill {
    background: var(--wa-chroma-violet-bg-18);
    color: var(--wa-chroma-violet-fg);
    border: 1px solid var(--wa-chroma-violet-border-38);
    letter-spacing: 0.04em;
}

.bottom-meta {
    min-height: 20px;
    justify-content: flex-end;
}
.chevron-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    padding: 0;
    border: 0;
    background: transparent;
    color: var(--wa-text-secondary);
    cursor: pointer;
    opacity: 0;
    width: 0;
    overflow: hidden;
    pointer-events: none;
    transition: opacity 0.12s ease, width 0.12s ease, color 0.12s ease;
}
.chevron-btn:hover {
    color: var(--wa-text);
}
.chat-list-item:hover .chevron-btn,
.chevron-btn.is-open {
    opacity: 1;
    width: 22px;
    pointer-events: auto;
}

.context-menu {
    animation: context-menu-pop 0.12s ease-out;
}
@keyframes context-menu-pop {
    from { opacity: 0; transform: translateY(-4px) scale(0.98); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

.menu-item {
    --icon-cutout: var(--wa-panel-header);
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
    --icon-cutout: var(--wa-panel-hover);
    background-color: var(--wa-panel-hover);
}
.menu-icon {
    width: 1.125rem;
    height: 1.125rem;
    color: var(--wa-text-secondary);
    flex-shrink: 0;
}
.menu-item-subtitle {
    font-size: 0.75rem;
    color: var(--wa-text-secondary);
    line-height: 1.2;
    margin-top: 2px;
    white-space: normal;
    max-width: 180px;
}
.menu-divider {
    height: 1px;
    margin: 0.25rem 0;
    background: var(--wa-border);
}
.menu-item-danger {
    color: var(--wa-danger);
}
.menu-item-danger .menu-icon {
    color: var(--wa-danger);
}
.menu-item-danger:hover {
    background-color: var(--wa-chroma-danger-bg-10);
}
</style>
