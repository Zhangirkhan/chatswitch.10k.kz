<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from '@/composables/useI18n';
import { computed, ref } from 'vue';
import Avatar from '@/Components/Avatar.vue';
import '@/Pages/Chats/Partials/chat-header.css';

const { t } = useI18n();

export type TeamConversationHeader = {
    id: number;
    type: 'direct' | 'department';
    title: string;
    subtitle: string | null;
    is_pinned?: boolean;
};

export type TeamHeaderParticipant = {
    id: number;
    name: string;
};

const props = defineProps<{
    header: TeamConversationHeader;
    participants?: TeamHeaderParticipant[];
    typingLabel?: string;
    pinBusy?: boolean;
}>();

const emit = defineEmits<{
    pin: [];
    calendar: [];
    'toggle-search': [];
}>();

const participantsOpen = ref(false);

const isDepartment = computed(() => props.header.type === 'department');

const statusLine = computed((): string => {
    if (props.typingLabel && props.typingLabel.trim() !== '') {
        return props.typingLabel;
    }
    if (isDepartment.value) {
        const n = (props.participants ?? []).length;
        if (n > 0) {
            const word = n === 1
                ? t('organization.participantOne')
                : n < 5
                    ? t('organization.participantFew')
                    : t('organization.participantMany');

            return `${n} ${word}`;
        }
    }
    return props.header.subtitle ?? '';
});

const statusIsTyping = computed(() => !!(props.typingLabel && props.typingLabel.trim() !== ''));

const participantsSorted = computed(() =>
    [...(props.participants ?? [])].sort((a, b) => a.name.localeCompare(b.name, 'ru')),
);
</script>

<template>
    <header
        class="team-chat-header min-h-[60px] py-1.5 bg-[var(--wa-panel-header)] flex items-center px-3 sm:px-4 gap-2 sm:gap-3 shrink-0 border-b border-[var(--wa-border)] relative z-10"
    >
        <Link
            :href="route('organization.team-chat.index')"
            class="md:hidden shrink-0 text-[var(--wa-icon)] p-1 -ml-1"
            :aria-label="t('organization.backToList')"
        >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
        </Link>

        <button
            type="button"
            class="shrink-0 rounded-full border-0 bg-transparent p-0 cursor-pointer"
            :title="isDepartment ? t('organization.participants') : header.title"
            :aria-label="isDepartment ? t('organization.participants') : header.title"
            @click="isDepartment ? (participantsOpen = true) : undefined"
        >
            <Avatar
                :name="header.title"
                fallback-initials
                :size="40"
                :class="isDepartment ? 'team-chat-header-avatar--group' : 'team-chat-header-avatar--direct'"
            />
        </button>

        <button
            type="button"
            class="flex-1 min-w-0 text-left border-0 bg-transparent p-0 cursor-pointer"
            :disabled="!isDepartment"
            @click="isDepartment ? (participantsOpen = true) : undefined"
        >
            <h2 class="text-base text-[var(--wa-text)] truncate font-normal m-0">
                {{ header.title }}
            </h2>
            <p
                v-if="statusLine"
                class="text-xs truncate m-0 mt-0.5"
                :class="statusIsTyping ? 'text-[var(--wa-accent)]' : 'text-[var(--wa-text-secondary)]'"
            >
                {{ statusLine }}
            </p>
        </button>

        <div class="chat-header-toolbar flex items-center gap-0.5 shrink-0">
            <button
                type="button"
                class="wa-header-btn"
                :title="t('organization.searchInThread')"
                :aria-label="t('organization.searchInThread')"
                @click="emit('toggle-search')"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
            </button>
            <button
                v-if="(participants ?? []).length > 0"
                type="button"
                class="wa-header-btn"
                :title="t('organization.calendarMeeting')"
                :aria-label="t('organization.calendarMeeting')"
                @click="emit('calendar')"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
            </button>
            <button
                v-if="isDepartment"
                type="button"
                class="wa-header-btn"
                :title="t('organization.participants')"
                :aria-label="t('organization.participants')"
                @click="participantsOpen = true"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
            </button>
            <button
                type="button"
                class="wa-header-btn"
                :class="{ 'text-[var(--wa-accent)]': header.is_pinned }"
                :title="header.is_pinned ? t('organization.unpinConversation') : t('organization.pinConversation')"
                :aria-label="header.is_pinned ? t('organization.unpinConversation') : t('organization.pinConversation')"
                :disabled="pinBusy"
                @click="emit('pin')"
            >
                <svg
                    class="w-5 h-5"
                    :fill="header.is_pinned ? 'currentColor' : 'none'"
                    stroke="currentColor"
                    stroke-width="2"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2l-2-2z" />
                </svg>
            </button>
        </div>

        <Teleport to="body">
            <div
                v-if="participantsOpen"
                class="fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-black/40 p-3"
                role="dialog"
                aria-modal="true"
                aria-labelledby="team-participants-title"
                @click.self="participantsOpen = false"
            >
                <div
                    class="w-full max-w-sm max-h-[70vh] overflow-hidden flex flex-col rounded-xl border border-[var(--wa-border)] bg-[var(--wa-panel)] text-[var(--wa-text)] shadow-xl"
                    @click.stop
                >
                    <div class="px-4 py-3 border-b border-[var(--wa-border)] flex items-center justify-between gap-2">
                        <h3 id="team-participants-title" class="text-sm font-semibold m-0">{{ t('organization.participants') }}</h3>
                        <button
                            type="button"
                            class="text-lg leading-none opacity-60 hover:opacity-100 px-1"
                            :aria-label="t('organization.closeAria')"
                            @click="participantsOpen = false"
                        >×</button>
                    </div>
                    <ul class="overflow-y-auto wa-scrollbar py-1 m-0 list-none">
                        <li
                            v-for="p in participantsSorted"
                            :key="p.id"
                            class="px-4 py-2.5 text-sm border-b border-[var(--wa-border)] last:border-b-0"
                        >
                            {{ p.name }}
                        </li>
                    </ul>
                </div>
            </div>
        </Teleport>
    </header>
</template>

<style scoped>
:deep(.team-chat-header-avatar--group .avatar__initials) {
    color: var(--org-task-dept-fg);
    background:
        radial-gradient(circle at 30% 20%, color-mix(in srgb, var(--org-task-dept-fg) 28%, transparent), transparent 48%),
        var(--org-task-dept-bg);
}
</style>
