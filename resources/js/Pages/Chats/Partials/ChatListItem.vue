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
import { useI18n } from '@/composables/useI18n';
import type { Chat, PageProps } from '@/types';
import { formatPhone } from '@/utils/phone';
import { appendChatListOwnership } from '@/utils/chatListOwnershipUrl';
import { whatsappSessionRingColor } from '@/utils/whatsappSessionColor';
import {
    CHAT_SHOW_PARTIAL_PROPS,
    isAiPanelOpenForChat,
    prefetchClientSummary,
} from '@/composables/useAiPanelDataCache';
import type { WhatsappSession } from '@/types';

const { show: showToast } = useToastStore();
const { t } = useI18n();

const props = defineProps<{
    chat: Chat;
    isSelected: boolean;
}>();

const page = usePage<PageProps>();

const hasMultipleWhatsappSessions = computed(
    () => ((page.props.whatsappSessions as WhatsappSession[] | undefined)?.length ?? 0) > 1,
);

const sessionRingColor = computed(() => {
    if (!hasMultipleWhatsappSessions.value) {
        return null;
    }

    return whatsappSessionRingColor(props.chat.whatsapp_session);
});

const chatShowHref = computed(() =>
    appendChatListOwnership(route('chats.show', props.chat.id), page.props.listOwnership as string | undefined),
);

const partialChatSwitch = computed(
    () => Boolean(route().current('chats.show')) && isAiPanelOpenForChat(props.chat.id),
);

function prefetchChat(): void {
    router.prefetch(chatShowHref.value);
    prefetchClientSummary(props.chat.contact_id, props.chat.id);
}

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
    assigneeNames.value.length > 0
        ? t('chats.listItem.assigneesTitle', { names: assigneeNames.value.join(', ') })
        : '',
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
            return {
                background: 'color-mix(in srgb, var(--wa-danger) 18%, var(--wa-panel))',
                color: 'var(--wa-danger)',
            };
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
            message: wasPinned ? t('chats.listItem.toastUnpinned') : t('chats.listItem.toastPinned'),
            action: {
                label: t('chats.undo'),
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
            message: wasArchived ? t('chats.listItem.toastUnarchived') : t('chats.listItem.toastArchived'),
            action: {
                label: t('chats.undo'),
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
                    ? t('chats.listItem.toastGroupNotificationsOn')
                    : t('chats.listItem.toastNotificationsOn'),
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
                ? t('chats.listItem.toastGroupNotificationsOff')
                : t('chats.listItem.toastNotificationsOff'),
            action: {
                label: t('chats.undo'),
                handler: async () => {
                    await muteApi({ unmute: true });
                    showToast({
                        message: isGroupChat.value
                            ? t('chats.listItem.toastGroupNotificationsOn')
                            : t('chats.listItem.toastNotificationsOn'),
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
            message: wasFavorite ? t('chats.listItem.toastRemovedFavorite') : t('chats.listItem.toastAddedFavorite'),
            action: {
                label: t('chats.undo'),
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
            message: wasUnread ? t('chats.listItem.toastMarkedRead') : t('chats.listItem.toastMarkedUnread'),
            action: {
                label: t('chats.undo'),
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
        showToast({ message: t('chats.listItem.toastCleared') });
    } finally {
        working.value = false;
        closeMenu();
    }
}

function notImplemented(name: string) {
    closeMenu();
    showToast({
        message: t('chats.featureComingSoon', { name }),
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
    if (d.toDateString() === yesterday.toDateString()) return t('chats.yesterday');
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
    if (!props.chat.muted_until) return t('chats.always');

    const until = new Date(props.chat.muted_until);
    const now = new Date();

    const time = until.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });

    const isToday = until.toDateString() === now.toDateString();
    if (isToday) return t('chats.listItem.muteUntilToday', { time });

    const tomorrow = new Date(now);
    tomorrow.setDate(tomorrow.getDate() + 1);
    if (until.toDateString() === tomorrow.toDateString()) {
        return t('chats.listItem.muteUntilTomorrow', { time });
    }

    const date = until.toLocaleDateString('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        year: '2-digit',
    });
    return t('chats.listItem.muteUntilDate', { time, date });
});

function sessionTooltip(chat: Chat): string {
    const s = chat.whatsapp_session;
    if (!s) return '';
    const num = formatPhone(s.phone_number) || (s.phone_number || '');
    const label = s.display_name?.trim() || '';
    return [label, num].filter(Boolean).join(' · ');
}

const avatarTitle = computed(
    () => sessionTooltip(props.chat) || displayName.value || undefined,
);
</script>

<template>
    <Link
        :href="chatShowHref"
        class="flex items-center px-3 py-[10px] gap-3 cursor-pointer transition group chat-list-item"
        :class="isSelected ? 'is-selected' : ''"
        prefetch
        preserve-state
        :only="partialChatSwitch ? [...CHAT_SHOW_PARTIAL_PROPS] : undefined"
        @mouseenter="prefetchChat"
        @focus="prefetchChat"
        @contextmenu="openMenu"
    >
        <div class="chat-list-avatar-stack shrink-0">
            <Avatar
                :avatar-url="chat.contact?.profile_picture_url"
                :name="displayName"
                :is-group="chat.is_group"
                :size="49"
                :ring-color="sessionRingColor"
                :title="avatarTitle"
            />
            <span
                v-if="showAiPill"
                class="chat-list-ai-mark"
                :title="t('chats.listItem.aiEnabledTitle')"
            >
                AI
            </span>
        </div>

        <div class="flex-1 min-w-0 border-b border-[var(--wa-divider)] group-hover:border-transparent pb-3 -mb-3 pt-0.5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-1.5 min-w-0">
                    <span class="text-[var(--wa-text)] text-base truncate">
                        {{
                            chat.chat_name
                            || chat.contact?.name
                            || (chat.contact?.push_name ? `~ ${chat.contact.push_name}` : null)
                            || formatPhone(chat.contact?.phone_number)
                            || t('chats.noName')
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
                        v-if="showAssigneePill || showAttentionPill"
                        class="flex max-w-full min-w-0 flex-col items-start gap-1"
                    >
                        <span
                            v-if="showAssigneePill"
                            class="min-w-0 max-w-full text-[10px] px-1.5 py-0.5 rounded font-medium assignee-pill assignee-pill-expanded leading-snug"
                            :title="assigneePillTitle"
                        >
                            {{ assigneePillLabel }}
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
                        :title="t('chats.listItem.pinnedTitle')"
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
                        :title="t('chats.listItem.menu')"
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
                    <span>{{ chat.is_archived ? t('chats.listItem.unarchive') : t('chats.listItem.archive') }}</span>
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
                        <span>{{ chat.is_muted ? t('chats.listItem.unmute') : t('chats.listItem.mute') }}</span>
                        <span v-if="chat.is_muted && muteSubtitle" class="menu-item-subtitle">
                            {{ muteSubtitle }}
                        </span>
                    </div>
                </button>

                <button class="menu-item" @click.prevent="togglePin" type="button">
                    <svg class="menu-icon" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2l-2-2z" />
                    </svg>
                    <span>{{ chat.is_pinned ? t('chats.listItem.unpin') : t('chats.listItem.pin') }}</span>
                </button>

                <button class="menu-item" @click.prevent="toggleUnread" type="button">
                    <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l9 6 9-6M3 8v10a2 2 0 002 2h14a2 2 0 002-2V8M3 8l9-5 9 5" />
                    </svg>
                    <span>
                        {{ chat.unread_count > 0 ? t('chats.listItem.markRead') : t('chats.listItem.markUnread') }}
                    </span>
                </button>

                <button class="menu-item" @click.prevent="toggleFavorite" type="button">
                    <svg class="menu-icon" :fill="chat.is_favorite ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 016.364 0L12 7.636l1.318-1.318a4.5 4.5 0 116.364 6.364L12 20.364l-7.682-7.682a4.5 4.5 0 010-6.364z" />
                    </svg>
                    <span>{{ chat.is_favorite ? t('chats.listItem.removeFavorite') : t('chats.listItem.addFavorite') }}</span>
                </button>

                <button class="menu-item" @click.prevent="notImplemented(t('chats.listItem.addToList'))" type="button">
                    <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h10M4 18h10M19 15v6m-3-3h6" />
                    </svg>
                    <span>{{ t('chats.listItem.addToList') }}</span>
                </button>

                <div class="menu-divider"></div>

                <button class="menu-item" @click.prevent="openClearChatDialog" type="button">
                    <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728L5.636 5.636m12.728 12.728A9 9 0 015.636 5.636" />
                    </svg>
                    <span>{{ t('chats.listItem.clearChat') }}</span>
                </button>
            </div>
        </div>
    </teleport>

    <DangerConfirmModal
        :open="clearChatDialogOpen"
        :title="t('chats.listItem.clearChatTitle')"
        :description="t('chats.listItem.clearChatDescription')"
        :confirm-label="t('chats.listItem.clearChatConfirm')"
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
.chat-list-avatar-stack {
    position: relative;
    width: 49px;
    height: 49px;
    flex-shrink: 0;
}

.chat-list-ai-mark {
    position: absolute;
    right: -2px;
    bottom: -2px;
    z-index: 2;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.125rem;
    height: 1.125rem;
    padding: 0 0.25rem;
    border-radius: 999px;
    font-size: 0.5rem;
    font-weight: 700;
    line-height: 1;
    letter-spacing: 0.04em;
    color: var(--wa-chroma-violet-fg);
    background: var(--icon-cutout, var(--wa-panel));
    border: 1px solid var(--wa-chroma-violet-border-38);
    pointer-events: none;
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
