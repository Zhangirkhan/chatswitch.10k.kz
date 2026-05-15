<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import EmojiPicker from '@/Pages/Chats/Partials/EmojiPicker.vue';
import MessageReactions from '@/Pages/Chats/Partials/MessageReactions.vue';
import LinkPreview from '@/Components/LinkPreview.vue';
import type { MessageReaction, PageProps } from '@/types';

export type TeamForward = {
    from_message_id: number;
    source_title: string;
    quote_sender_name: string;
    quote_body: string;
};

export type TeamReplyTo = {
    id: number;
    sender_name: string;
    body_preview: string;
};

export type TeamAttachment = {
    id: number;
    original_name: string;
    url: string;
    mime_type: string;
    size: number;
    is_image: boolean;
};

export type TeamLinkPreview = {
    url: string;
    title?: string | null;
    description?: string | null;
    image?: string | null;
    site_name?: string | null;
};

export type TeamChatMessageModel = {
    id: number;
    team_conversation_id: number;
    parent_team_message_id?: number | null;
    sender_id: number;
    body: string;
    client_message_id?: string | null;
    mentioned_user_ids?: number[];
    mentioned_users?: { id: number; name: string }[];
    forward?: TeamForward | null;
    reply_to?: TeamReplyTo | null;
    attachments?: TeamAttachment[];
    link_preview?: TeamLinkPreview | null;
    reactions?: MessageReaction[];
    created_at: string | null;
    sender: { id: number; name: string } | null;
};

const props = defineProps<{
    message: TeamChatMessageModel;
    isOutbound: boolean;
    showSenderName: boolean;
    receiptLabel: 'read' | 'delivered' | 'sent' | null;
    canPinRoom: boolean;
    isRoomPinned: boolean;
    roomPinSending: boolean;
    canCreateTask: boolean;
    taskSending: boolean;
}>();

const emit = defineEmits<{
    (e: 'reply', message: TeamChatMessageModel): void;
    (e: 'forward', message: TeamChatMessageModel): void;
    (e: 'react', payload: { message: TeamChatMessageModel; emoji: string }): void;
    (e: 'jump-to-reply', id: number): void;
    (e: 'pin-room', messageId: number): void;
    (e: 'unpin-room'): void;
    (e: 'create-task', message: TeamChatMessageModel): void;
}>();

const page = usePage<PageProps>();
const currentUserId = computed(() => page.props.auth?.user?.id ?? 0);

const pickerOpen = ref(false);
const fullPickerOpen = ref(false);
const pickerX = ref(0);
const pickerY = ref(0);
const isReacting = ref(false);
const menuOpen = ref(false);
const menuX = ref(0);
const menuY = ref(0);
const hovered = ref(false);

const defaultQuickReactionEmojis = ['👍', '❤️', '😂', '😮', '😢'];
const quickReactionEmojis = computed<string[]>(() => {
    const configured = page.props.quickReactions;
    if (!Array.isArray(configured)) {
        return defaultQuickReactionEmojis;
    }
    const emojis = configured
        .filter((value): value is string => typeof value === 'string' && value.trim() !== '')
        .map((value) => value.trim())
        .slice(0, 5);
    return emojis.length === 5 ? emojis : defaultQuickReactionEmojis;
});

const MSG_MENU_WIDTH = 208;
const MSG_MENU_HEIGHT_EST = 300;
const QUICK_REACTION_BAR_W = computed(() => (quickReactionEmojis.value.length + 1) * 24 + quickReactionEmojis.value.length * 2 + 16);
const QUICK_REACTION_BAR_H = 32;
const MENU_REACTION_GAP = 6;

const canReply = computed(() => !props.message.reply_to);
const hasBody = computed(() => (props.message.body ?? '').trim() !== '');

type TeamBodySeg = { text: string; mentionUserId?: number };

function teamMessageBodySegments(body: string, mentioned: { id: number; name: string }[] | undefined): TeamBodySeg[] {
    const users = (mentioned ?? []).filter((u) => u.name.trim() !== '');
    if (!body || users.length === 0) {
        return [{ text: body }];
    }
    const sorted = [...users].sort((a, b) => b.name.length - a.name.length);
    const out: TeamBodySeg[] = [];
    let buf = '';
    const flushBuf = (): void => {
        if (buf !== '') {
            out.push({ text: buf });
            buf = '';
        }
    };
    let i = 0;
    while (i < body.length) {
        if (body[i] === '@') {
            let matched: { id: number; name: string } | null = null;
            for (const u of sorted) {
                const name = u.name;
                if (name === '') continue;
                if (body.startsWith(name, i + 1)) {
                    const after = i + 1 + name.length;
                    const ch = body[after];
                    if (ch !== undefined && /[\p{L}\p{N}_]/u.test(ch)) {
                        continue;
                    }
                    matched = u;
                    break;
                }
            }
            if (matched) {
                flushBuf();
                out.push({ text: `@${matched.name}`, mentionUserId: matched.id });
                i += 1 + matched.name.length;
                continue;
            }
        }
        buf += body[i];
        i++;
    }
    flushBuf();
    return out;
}

function mentionAppearsInBody(body: string, name: string): boolean {
    const needle = `@${name}`;
    if (name.trim() === '') {
        return false;
    }
    let idx = body.indexOf(needle);
    while (idx !== -1) {
        const after = idx + needle.length;
        const ch = body[after];
        if (ch === undefined || !/[\p{L}\p{N}_]/u.test(ch)) {
            return true;
        }
        idx = body.indexOf(needle, idx + 1);
    }
    return false;
}

const mentionedUsersNotInBody = computed(() => {
    const users = props.message.mentioned_users ?? [];
    if (users.length === 0) {
        return [];
    }
    const body = props.message.body ?? '';
    if (body === '') {
        return users;
    }
    return users.filter((u) => !mentionAppearsInBody(body, u.name));
});

const bodySegments = computed(() =>
    teamMessageBodySegments(props.message.body ?? '', props.message.mentioned_users),
);

function messageTime(): string {
    const value = props.message.created_at;
    if (!value) return '';
    return new Date(value).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

function receiptPrefix(): string {
    if (props.receiptLabel === 'read') return 'Прочитано';
    if (props.receiptLabel === 'delivered') return 'Доставлено';
    if (props.receiptLabel === 'sent') return 'Отправлено';
    return '';
}

async function react(emoji: string): Promise<void> {
    if (isReacting.value) return;
    isReacting.value = true;
    try {
        emit('react', { message: props.message, emoji });
    } finally {
        isReacting.value = false;
        closeMenu();
    }
}

async function copyMessage(): Promise<void> {
    if (!props.message.body) return;
    try {
        await navigator.clipboard.writeText(props.message.body);
    } catch {
        /* ignore */
    }
}

function reactionPanelBounds(): DOMRect {
    return document.querySelector('.team-chat-thread')?.getBoundingClientRect() ?? document.body.getBoundingClientRect();
}

function placeReactionPanel(x: number, y: number, width: number, height: number, bounds?: DOMRect): void {
    const b = bounds ?? document.body.getBoundingClientRect();
    const minX = b.left + 8;
    const maxX = Math.max(minX, b.right - width - 8);
    const minY = 8;
    const maxY = Math.max(minY, window.innerHeight - height - 8);
    pickerX.value = Math.min(Math.max(minX, x), maxX);
    pickerY.value = Math.min(Math.max(minY, y), maxY);
}

function openQuickReactionsAt(x: number, y: number, bounds?: DOMRect): void {
    placeReactionPanel(x, y, QUICK_REACTION_BAR_W.value, QUICK_REACTION_BAR_H, bounds);
    pickerOpen.value = true;
    fullPickerOpen.value = false;
}

function openFullPickerAt(x: number, y: number, bounds?: DOMRect): void {
    placeReactionPanel(x, y, 360, 360, bounds);
    pickerOpen.value = false;
    fullPickerOpen.value = true;
}

function openMenuAt(x: number, y: number): void {
    const vw = window.innerWidth;
    const vh = window.innerHeight;

    let menuLeft = x;
    let menuTop = y;
    if (menuLeft + MSG_MENU_WIDTH + 8 > vw) {
        menuLeft = vw - MSG_MENU_WIDTH - 8;
    }
    if (menuLeft < 8) {
        menuLeft = 8;
    }
    if (menuTop + MSG_MENU_HEIGHT_EST + 8 > vh) {
        menuTop = Math.max(8, vh - MSG_MENU_HEIGHT_EST - 8);
    }

    let barTop = menuTop - MENU_REACTION_GAP - QUICK_REACTION_BAR_H;
    if (barTop < 8) {
        const shift = 8 - barTop;
        menuTop += shift;
        if (menuTop + MSG_MENU_HEIGHT_EST + 8 > vh) {
            menuTop = Math.max(8, vh - MSG_MENU_HEIGHT_EST - 8);
        }
        barTop = menuTop - MENU_REACTION_GAP - QUICK_REACTION_BAR_H;
    }

    menuX.value = menuLeft;
    menuY.value = menuTop;

    const barLeft = Math.min(
        Math.max(8, menuLeft + MSG_MENU_WIDTH / 2 - QUICK_REACTION_BAR_W.value / 2),
        vw - QUICK_REACTION_BAR_W.value - 8,
    );
    pickerX.value = barLeft;
    pickerY.value = Math.max(8, barTop);

    menuOpen.value = true;
    pickerOpen.value = true;
    fullPickerOpen.value = false;
    void nextTick();
}

function openMenuFromButton(e: MouseEvent): void {
    e.preventDefault();
    e.stopPropagation();
    const target = e.currentTarget as HTMLElement | null;
    const rect = target?.getBoundingClientRect();
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    let x = rect ? rect.right - MSG_MENU_WIDTH : e.clientX;
    let y = rect ? rect.bottom + 6 : e.clientY;
    if (x + MSG_MENU_WIDTH + 8 > vw) x = vw - MSG_MENU_WIDTH - 8;
    if (x < 8) x = 8;
    if (y + MSG_MENU_HEIGHT_EST + 8 > vh) y = Math.max(8, vh - MSG_MENU_HEIGHT_EST - 8);
    openMenuAt(x, y);
}

function onContextMenu(e: MouseEvent): void {
    e.preventDefault();
    e.stopPropagation();
    openMenuAt(e.clientX, e.clientY);
}

function closeMenu(): void {
    menuOpen.value = false;
    pickerOpen.value = false;
    fullPickerOpen.value = false;
}

function togglePickerFromTrigger(e: PointerEvent): void {
    e.preventDefault();
    e.stopPropagation();
    if (pickerOpen.value && !menuOpen.value) {
        pickerOpen.value = false;
        return;
    }
    if (menuOpen.value) {
        closeMenu();
    }
    fullPickerOpen.value = false;
    const trigger = e.currentTarget as HTMLElement | null;
    const bounds = reactionPanelBounds();
    const rect = trigger?.getBoundingClientRect();
    if (!rect) {
        openQuickReactionsAt(e.clientX, e.clientY, bounds);
        return;
    }
    const triggerCenterX = rect.left + rect.width / 2;
    const x = triggerCenterX - QUICK_REACTION_BAR_W.value / 2;
    let y = rect.top - QUICK_REACTION_BAR_H - 8;
    if (y < 8) {
        y = rect.bottom + 8;
    }
    openQuickReactionsAt(x, y, bounds);
}

function openFullPickerFromMenu(): void {
    const bounds = reactionPanelBounds();
    const pickerW = 360;
    const pickerH = 360;
    const centerX = menuX.value + MSG_MENU_WIDTH / 2 - pickerW / 2;
    const idealY = menuY.value - MENU_REACTION_GAP - pickerH;
    placeReactionPanel(centerX, idealY, pickerW, pickerH, bounds);
    pickerOpen.value = false;
    fullPickerOpen.value = true;
    menuOpen.value = false;
}

function openFullPickerFromQuickBar(): void {
    openFullPickerAt(pickerX.value, pickerY.value, reactionPanelBounds());
    menuOpen.value = false;
}

async function reactFromQuickBar(emoji: string): Promise<void> {
    await react(emoji);
}

function onEscape(e: KeyboardEvent): void {
    if (e.key === 'Escape') {
        closeMenu();
    }
}

window.addEventListener('keydown', onEscape);
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onEscape);
});
</script>

<template>
    <div class="group mb-2 flex" :class="isOutbound ? 'justify-end' : 'justify-start'">
        <div
            class="wa-msg-bubble relative w-fit min-w-0 max-w-[min(72%,28rem)] text-[14.2px] leading-[19px] px-2 py-1"
            :data-team-msg-id="message.id"
            :class="isOutbound ? 'wa-msg-bubble-out' : 'wa-msg-bubble-in'"
            :style="{
                background: isOutbound ? 'var(--wa-bubble-out)' : 'var(--wa-bubble-in)',
                color: 'var(--wa-bubble-text)',
            }"
            @mouseenter="hovered = true"
            @mouseleave="hovered = false"
            @contextmenu="onContextMenu"
        >
            <button
                v-if="!menuOpen"
                type="button"
                class="wa-msg-emoji-trigger absolute top-1 z-[60] flex h-7 w-7 items-center justify-center rounded-full text-base shadow-lg transition hover:scale-105"
                :class="isOutbound ? '-left-9' : '-right-9'"
                :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-icon)' }"
                title="Добавить/изменить реакцию"
                @click.stop.prevent
                @pointerdown.stop.prevent="togglePickerFromTrigger"
            >
                <span class="select-none leading-none" aria-hidden="true">☺</span>
            </button>

            <button
                v-show="hovered"
                type="button"
                class="msg-hover-menu-btn"
                :class="isOutbound ? 'msg-hover-menu-btn-out' : 'msg-hover-menu-btn-in'"
                title="Меню"
                @click="openMenuFromButton"
            >
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 15.5a1 1 0 01-.71-.29l-5-5a1 1 0 011.42-1.42L12 13.09l4.29-4.3a1 1 0 011.42 1.42l-5 5a1 1 0 01-.71.29z" />
                </svg>
            </button>

            <div
                v-if="showSenderName && !isOutbound"
                class="mb-0.5 text-[12px] font-medium pr-8"
                :style="{ color: 'var(--wa-accent)' }"
            >
                {{ message.sender?.name ?? '…' }}
            </div>

            <div
                v-if="message.forward"
                class="mb-1 rounded border-l-2 pl-2 py-0.5 pr-1 text-[12px] leading-snug"
                :style="{ borderColor: 'var(--wa-accent)', color: 'var(--wa-text-secondary)' }"
            >
                <div class="font-medium">Переслано из «{{ message.forward.source_title }}»</div>
                <div v-if="message.forward.quote_body" class="mt-0.5 opacity-90">
                    <span class="font-semibold" :style="{ color: 'var(--wa-accent)' }">{{ message.forward.quote_sender_name }}:</span>
                    {{ message.forward.quote_body }}
                </div>
            </div>

            <button
                v-if="message.reply_to"
                type="button"
                class="mb-1 w-full rounded border-l-2 pl-2 py-0.5 pr-1 text-left text-[12px] leading-snug cursor-pointer transition-colors hover:opacity-90"
                :style="{ borderColor: 'var(--wa-border)', color: 'var(--wa-text-secondary)' }"
                title="Перейти к сообщению"
                @click.stop="emit('jump-to-reply', message.reply_to!.id)"
            >
                <div>
                    Ответ на
                    <span class="font-medium" :style="{ color: 'var(--wa-accent)' }">{{ message.reply_to.sender_name }}</span>
                </div>
                <div v-if="message.reply_to.body_preview" class="mt-0.5 truncate opacity-90">{{ message.reply_to.body_preview }}</div>
            </button>

            <div v-if="hasBody" class="wa-msg-text whitespace-pre-wrap break-words" style="word-break: break-word">
                <template v-for="(seg, si) in bodySegments" :key="`${message.id}-b-${si}`">
                    <span
                        v-if="seg.mentionUserId"
                        class="font-medium rounded px-0.5"
                        :style="{ color: 'var(--wa-accent)', background: 'color-mix(in srgb, var(--wa-accent) 12%, transparent)' }"
                    >{{ seg.text }}</span>
                    <span v-else>{{ seg.text }}</span>
                </template>
            </div>

            <div v-if="message.link_preview?.url" class="mt-1 max-w-full" @click.stop>
                <LinkPreview :url="message.link_preview.url" :cached="message.link_preview" />
            </div>

            <div v-if="(message.attachments ?? []).length" class="mt-1 space-y-1">
                <template v-for="a in (message.attachments ?? [])" :key="a.id">
                    <a
                        v-if="a.is_image"
                        :href="a.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="block overflow-hidden rounded-md"
                        @click.stop
                    >
                        <img
                            :src="a.url"
                            :alt="a.original_name"
                            class="max-h-48 max-w-full object-contain"
                            loading="lazy"
                            decoding="async"
                        />
                    </a>
                    <a
                        v-else
                        :href="a.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="flex items-center gap-1.5 break-all text-[12px] underline opacity-90"
                        :style="{ color: 'var(--wa-accent)' }"
                        @click.stop
                    >
                        <span aria-hidden="true">📎</span>
                        <span class="min-w-0">{{ a.original_name }}</span>
                    </a>
                </template>
            </div>

            <div
                v-if="mentionedUsersNotInBody.length"
                class="text-[11px] mt-0.5 opacity-80"
                :style="{ color: 'var(--wa-accent)' }"
            >
                Упомянуты: {{ mentionedUsersNotInBody.map((u) => u.name).join(', ') }}
            </div>

            <div class="float-right -mb-1 -mt-1 ml-2 flex items-center gap-1 text-[11px] opacity-80">
                <span v-if="receiptPrefix()" class="text-[var(--wa-text-secondary)]">{{ receiptPrefix() }}</span>
                <span class="tabular-nums">{{ messageTime() }}</span>
            </div>
            <div class="clear-both" />

            <MessageReactions
                :reactions="message.reactions ?? []"
                :current-user-id="currentUserId"
                @react="react"
            />
        </div>
    </div>

    <teleport to="body">
        <div v-if="menuOpen || pickerOpen || fullPickerOpen">
            <div
                class="fixed inset-0 z-[1040]"
                @pointerdown="closeMenu"
                @click="closeMenu"
                @contextmenu.prevent="closeMenu"
            />
            <div
                v-if="menuOpen"
                class="msg-menu-panel fixed z-[1050] rounded-[12px] py-1"
                :style="{ left: menuX + 'px', top: menuY + 'px', width: MSG_MENU_WIDTH + 'px' }"
            >
                <button
                    v-if="canReply"
                    class="msg-menu-item"
                    type="button"
                    @click="closeMenu(); emit('reply', message)"
                >
                    <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a6 6 0 016 6v1M3 10l6 6M3 10l6-6" />
                    </svg>
                    Ответить
                </button>

                <button
                    class="msg-menu-item"
                    type="button"
                    :disabled="!hasBody"
                    @click="closeMenu(); copyMessage()"
                >
                    <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                    Копировать
                </button>

                <button class="msg-menu-item" type="button" @click="closeMenu(); openFullPickerFromMenu()">
                    <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Отреагировать
                </button>

                <button class="msg-menu-item" type="button" @click="closeMenu(); emit('forward', message)">
                    <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 8l5 4-5 4M4 12h16" />
                    </svg>
                    Переслать
                </button>

                <template v-if="canPinRoom">
                    <div class="msg-menu-divider" />
                    <button
                        class="msg-menu-item"
                        type="button"
                        :disabled="roomPinSending"
                        @click="closeMenu(); isRoomPinned ? emit('unpin-room') : emit('pin-room', message.id)"
                    >
                        <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 5h14v14H5V5zm4 0V3h6v2" />
                        </svg>
                        {{ isRoomPinned ? 'Снять закреп в чате отдела' : 'Закрепить в чате отдела' }}
                    </button>
                </template>

                <template v-if="canCreateTask">
                    <div v-if="!canPinRoom" class="msg-menu-divider" />
                    <button
                        class="msg-menu-item"
                        type="button"
                        :disabled="taskSending"
                        @click="closeMenu(); emit('create-task', message)"
                    >
                        <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 11l3 3L22 4M2 12a10 10 0 1010-10" />
                        </svg>
                        Создать задачу
                    </button>
                </template>
            </div>
        </div>
    </teleport>

    <teleport to="body">
        <Transition name="wa-quick-reactions">
            <div
                v-if="pickerOpen"
                class="wa-quick-reactions fixed z-[1060]"
                :style="{ left: pickerX + 'px', top: pickerY + 'px' }"
                @pointerdown.stop
            >
                <button
                    v-for="(emoji, i) in quickReactionEmojis"
                    :key="emoji"
                    type="button"
                    class="wa-quick-reaction-btn"
                    :style="{ '--reaction-delay': `${i * 22}ms` }"
                    @click="reactFromQuickBar(emoji)"
                >
                    {{ emoji }}
                </button>
                <button
                    type="button"
                    class="wa-quick-reaction-btn wa-quick-reaction-btn--plus"
                    title="Больше эмодзи"
                    @click="openFullPickerFromQuickBar"
                >
                    +
                </button>
            </div>
        </Transition>

        <Transition name="wa-full-picker">
            <div
                v-if="fullPickerOpen"
                class="fixed z-[1060]"
                :style="{ left: pickerX + 'px', top: pickerY + 'px' }"
                @pointerdown.stop
            >
                <EmojiPicker @select="react" @close="closeMenu" />
            </div>
        </Transition>
    </teleport>
</template>

<style scoped>
.wa-msg-bubble {
    border-radius: 7.5px;
    box-shadow: 0 1px 0.5px var(--wa-bubble-tail-shadow);
    overflow: visible;
    width: fit-content;
}

.wa-msg-text {
    display: block;
    min-width: 0;
    max-width: 100%;
}

.wa-msg-text::after {
    content: "";
    display: inline-block;
    width: 3.7rem;
}

.wa-msg-bubble::before {
    content: "";
    position: absolute;
    top: 0;
    width: 9px;
    height: 13px;
    background: inherit;
    pointer-events: none;
}

.wa-msg-bubble-in {
    border-top-left-radius: 0;
}

.wa-msg-bubble-in::before {
    left: -8px;
    clip-path: polygon(100% 0, 0 0, 100% 100%);
}

.wa-msg-bubble-out {
    border-top-right-radius: 0;
}

.wa-msg-bubble-out::before {
    right: -8px;
    clip-path: polygon(0 0, 100% 0, 0 100%);
}

.msg-hover-menu-btn {
    position: absolute;
    top: 6px;
    right: 6px;
    width: 28px;
    height: 28px;
    border-radius: 9999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: var(--wa-text-secondary);
    background: transparent;
    opacity: 0;
    transform: translateY(-2px);
    transition: opacity 0.12s ease, transform 0.12s ease, background-color 0.12s ease, color 0.12s ease;
    z-index: 5;
}

.group:hover .msg-hover-menu-btn {
    opacity: 1;
    transform: translateY(0);
}

.msg-hover-menu-btn:hover {
    background: var(--wa-panel-hover);
    color: var(--wa-text);
}

.msg-hover-menu-btn-out {
    right: 6px;
}

.msg-hover-menu-btn-in {
    right: 6px;
}

.msg-menu-panel {
    background: var(--msg-menu-bg);
    border: 1px solid var(--msg-menu-border);
    box-shadow: var(--msg-menu-shadow);
}

.msg-menu-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    width: 100%;
    min-width: 0;
    padding: 0.4375rem 0.625rem;
    font-size: 0.8125rem;
    line-height: 1.3;
    color: var(--wa-text);
    text-align: left;
    transition: background-color 0.1s ease;
}

.msg-menu-item:not(:disabled):hover {
    background-color: var(--msg-menu-item-hover);
}

.msg-menu-item:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.msg-menu-icon {
    width: 0.9375rem;
    height: 0.9375rem;
    color: var(--wa-text-secondary);
    flex-shrink: 0;
}

.msg-menu-divider {
    height: 1px;
    margin: 0.1875rem 0.375rem;
    background: var(--msg-menu-divider);
}

.wa-quick-reactions {
    display: flex;
    align-items: center;
    gap: 2px;
    height: 32px;
    padding: 2px 6px;
    border: 1px solid var(--msg-reaction-bar-border);
    border-radius: 9999px;
    background: var(--msg-reaction-bar-bg);
    box-shadow: var(--msg-reaction-bar-shadow);
    transform-origin: 50% 100%;
    will-change: transform, opacity;
}

.wa-quick-reaction-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border: 0;
    border-radius: 9999px;
    background: transparent;
    color: var(--msg-reaction-emoji-color);
    font-size: 16px;
    line-height: 1;
    opacity: 0;
    animation: wa-reaction-pop 180ms cubic-bezier(0.2, 0.85, 0.2, 1.2) forwards;
    animation-delay: var(--reaction-delay, 0ms);
    transition: background-color 0.12s ease, transform 0.12s ease;
}

.wa-quick-reaction-btn:hover {
    background: var(--msg-reaction-btn-hover);
    transform: translateY(-1px) scale(1.06);
}

.wa-quick-reaction-btn--plus {
    font-size: 17px;
    font-weight: 300;
    animation-delay: 132ms;
}

.wa-quick-reactions-enter-active {
    transition: opacity 140ms ease-out, transform 180ms cubic-bezier(0.2, 0.85, 0.2, 1.08);
}

.wa-quick-reactions-leave-active {
    transition: opacity 90ms ease-in, transform 90ms ease-in;
}

.wa-quick-reactions-enter-from,
.wa-quick-reactions-leave-to {
    opacity: 0;
    transform: translateY(8px) scale(0.88);
}

.wa-quick-reactions-enter-to,
.wa-quick-reactions-leave-from {
    opacity: 1;
    transform: translateY(0) scale(1);
}

.wa-full-picker-enter-active {
    transition: opacity 140ms ease-out, transform 160ms ease-out;
}

.wa-full-picker-leave-active {
    transition: opacity 90ms ease-in, transform 90ms ease-in;
}

.wa-full-picker-enter-from,
.wa-full-picker-leave-to {
    opacity: 0;
    transform: translateY(6px) scale(0.98);
}

@keyframes wa-reaction-pop {
    from {
        opacity: 0;
        transform: translateY(5px) scale(0.82);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}
</style>
