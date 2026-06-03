<script setup lang="ts">
import TeamChatMessage from './Partials/TeamChatMessage.vue';
import TeamChatInput from './Partials/TeamChatInput.vue';
import TeamChatHeader, { type TeamConversationHeader } from './Partials/TeamChatHeader.vue';
import ShareMessageModal from '@/Components/ShareMessageModal.vue';
import { useI18n } from '@/composables/useI18n';
import { useTeamChatThread } from './useTeamChatThread';

const props = defineProps<{
    selectedConversationId: number;
    conversationHeader?: TeamConversationHeader | null;
}>();

const { t } = useI18n();

const {
    page,
    myUserId,
    draft,
    pendingAttachments,
    sending,
    loading,
    messages,
    threadEl,
    conversationType,
    departmentId,
    participants,
    searchOpen,
    searchQuery,
    showScrollFab,
    shareOpen,
    shareModalSource,
    replyToMessage,
    typingLabel,
    canPinRoomMessage,
    roomPinnedMessage,
    roomPinSending,
    taskFromMessageSending,
    headerState,
    headerPinBusy,
    replyJumpNotice,
    teamMentionCandidates,
    threadItems,
    displayedMessages,
    toggleConversationPin,
    toggleSearch,
    onDraftInput,
    outgoingDmReceiptLabel,
    applyTeamReaction,
    onThreadScroll,
    scrollToBottomFab,
    startReplyTo,
    openCalendarFromChat,
    createTaskFromMessage,
    clearReplyTo,
    replyDraftPreview,
    scrollToQuotedParent,
    clearRoomPinned,
    setRoomPinnedForMessage,
    openForwardPicker,
    closeShareModal,
    onShareSent,
    send,
    sendVoice,
} = useTeamChatThread(props);
</script>

<template>
    <div class="flex flex-1 min-h-0 flex-col">
        <TeamChatHeader
            v-if="headerState"
            :header="headerState"
            :participants="participants"
            :typing-label="typingLabel"
            :pin-busy="headerPinBusy"
            @pin="toggleConversationPin"
            @calendar="openCalendarFromChat"
            @toggle-search="toggleSearch"
        />

        <div
            v-if="searchOpen"
            class="px-3 sm:px-4 py-2 shrink-0 border-b flex items-center gap-3"
            :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border)' }"
        >
            <div class="relative flex-1">
                <svg
                    class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--wa-icon)]"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input
                    v-model="searchQuery"
                    type="text"
                    :placeholder="t('organization.searchInThread')"
                    class="w-full pl-10 pr-3 py-2 rounded-full text-sm border-0 focus:ring-0 focus:outline-none"
                    :style="{ background: 'var(--wa-panel)', color: 'var(--wa-text)' }"
                    autofocus
                />
            </div>
            <span class="text-xs shrink-0" :style="{ color: 'var(--wa-text-secondary)' }">
                {{ t('organization.searchFound', { count: displayedMessages.length }) }}
            </span>
            <button
                type="button"
                class="w-8 h-8 shrink-0 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)]"
                :title="t('organization.closeAria')"
                :aria-label="t('organization.closeAria')"
                @click="toggleSearch"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="relative flex min-h-0 flex-1 flex-col chat-bg">
            <div
                v-if="conversationType === 'department' && roomPinnedMessage"
                class="shrink-0 border-b border-[var(--wa-border)] px-4 py-2"
                :style="{ background: 'var(--wa-panel-header)' }"
            >
                <div class="flex items-center gap-3 text-xs sm:text-sm">
                    <svg class="w-4 h-4 shrink-0 text-[var(--wa-text-secondary)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h8M9 3h6l1 4v5l2 2v2H6v-2l2-2V7l1-4z" />
                    </svg>
                    <button
                        type="button"
                        class="min-w-0 flex-1 text-left"
                        :title="t('organization.goToMessageTitle')"
                        @click="scrollToQuotedParent(roomPinnedMessage.id)"
                    >
                        <div class="font-medium truncate text-[var(--wa-text)]">{{ roomPinnedMessage.sender_name }}</div>
                        <div class="truncate text-[var(--wa-text-secondary)]">{{ roomPinnedMessage.body_preview }}</div>
                    </button>
                    <button
                        v-if="canPinRoomMessage"
                        type="button"
                        class="w-8 h-8 shrink-0 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)] disabled:opacity-40"
                        :disabled="roomPinSending"
                        :title="t('organization.dismiss')"
                        @click="clearRoomPinned()"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            <div
                ref="threadEl"
                class="team-chat-thread min-h-0 flex-1 overflow-y-auto wa-scrollbar px-3 sm:px-4 py-3"
                @scroll.passive="onThreadScroll"
            >
                <div v-if="loading && messages.length === 0" class="space-y-3 px-1 py-2">
                    <div v-for="n in 5" :key="n" class="flex gap-2 animate-pulse" :class="n % 2 === 0 ? 'justify-end' : ''">
                        <div
                            class="rounded-2xl px-4 py-3 max-w-[min(72%,420px)] space-y-2"
                            :style="{ background: 'var(--wa-panel-header)' }"
                        >
                            <div class="h-2.5 rounded w-full" :style="{ background: 'var(--wa-border)' }" />
                            <div class="h-2.5 rounded w-4/5" :style="{ background: 'var(--wa-border)' }" />
                        </div>
                    </div>
                </div>
                <div
                    v-if="replyJumpNotice"
                    class="ui-result-card mb-2 text-xs text-[var(--wa-text-secondary)]"
                    role="status"
                >
                    {{ replyJumpNotice }}
                </div>
                <template v-for="item in threadItems" :key="item.key">
                    <div v-if="item.kind === 'date'" class="team-chat-date-separator">
                        <span class="team-chat-date-separator__pill">{{ item.label }}</span>
                    </div>
                    <div v-else-if="item.kind === 'unread'" class="team-chat-unread-divider">
                        {{ item.label }}
                    </div>
                    <TeamChatMessage
                        v-else-if="item.kind === 'message' && item.message"
                        :message="item.message"
                        :is-outbound="item.message.sender_id === myUserId()"
                        :show-sender-name="conversationType === 'department' && item.message.sender_id !== myUserId()"
                        :receipt-label="outgoingDmReceiptLabel(item.message)"
                        :can-pin-room="canPinRoomMessage && conversationType === 'department'"
                        :is-room-pinned="roomPinnedMessage?.id === item.message.id"
                        :room-pin-sending="roomPinSending"
                        :can-create-task="Boolean(page.props.modules?.org_tasks) && conversationType === 'department' && !!departmentId"
                        :task-sending="taskFromMessageSending"
                        @reply="startReplyTo"
                        @forward="openForwardPicker"
                        @react="({ message, emoji }) => applyTeamReaction(message, emoji)"
                        @jump-to-reply="scrollToQuotedParent"
                        @pin-room="setRoomPinnedForMessage"
                        @unpin-room="clearRoomPinned"
                        @create-task="createTaskFromMessage"
                    />
                </template>
            </div>

            <button
                v-if="showScrollFab"
                type="button"
                class="team-chat-scroll-fab"
                :title="t('organization.scrollToBottom')"
                :aria-label="t('organization.scrollToBottom')"
                @click="scrollToBottomFab"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                </svg>
            </button>

            <div class="team-chat-composer shrink-0 px-2 sm:px-3 pb-[max(0.5rem,env(safe-area-inset-bottom))] pt-1">
                <div class="mx-auto flex w-full max-w-4xl flex-col gap-2">
                    <div
                        v-if="replyToMessage"
                        class="ui-result-card flex items-start gap-2 border-[color-mix(in_srgb,var(--wa-accent)_40%,var(--wa-border))] bg-[var(--wa-selected)]/25 text-xs text-[var(--wa-text)]"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="text-[var(--wa-text-secondary)] font-medium">{{ t('organization.replyTo', { name: replyToMessage.sender?.name ?? '…' }) }}</div>
                            <div class="truncate opacity-90 mt-0.5">{{ replyDraftPreview(replyToMessage) }}</div>
                        </div>
                        <button
                            type="button"
                            class="shrink-0 text-lg leading-none opacity-60 hover:opacity-100 px-1"
                            :aria-label="t('organization.cancelReplyAria')"
                            @click="clearReplyTo"
                        >×</button>
                    </div>
                    <TeamChatInput
                        v-model="draft"
                        v-model:attachments="pendingAttachments"
                        :disabled="sending"
                        :mention-candidates="teamMentionCandidates"
                        :placeholder="t('organization.messagePlaceholder')"
                        @typing="onDraftInput"
                        @submit="send"
                        @voice="sendVoice"
                    />
                </div>
            </div>
        </div>
    </div>

    <ShareMessageModal
        :open="shareOpen"
        :source="shareModalSource"
        @close="closeShareModal"
        @sent="onShareSent"
    />
</template>
