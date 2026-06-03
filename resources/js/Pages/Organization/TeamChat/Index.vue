<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import OrganizationLayout from '@/Layouts/OrganizationLayout.vue';
import TeamChatShow from './Show.vue';
import type { TeamConversationHeader } from './Partials/TeamChatHeader.vue';
import type { OrgDepartment } from '../Partials/OrganizationSidebar.vue';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

defineProps<{
    departments: OrgDepartment[];
    selectedConversationId: number | null;
    conversationHeader?: TeamConversationHeader | null;
}>();
</script>

<template>
    <Head :title="t('organization.teamChatTitle')" />
    <OrganizationLayout :departments="departments" :selected-department-id="null">
        <div class="team-chat-main flex h-full min-h-0 flex-col bg-[var(--wa-page-bg)]">
            <div
                v-if="!selectedConversationId"
                class="flex flex-1 flex-col items-center justify-center px-4 sm:px-6"
            >
                <div class="ui-empty-state ui-empty-state--org max-w-md">
                    <div class="ui-empty-state__icon">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                        </svg>
                    </div>
                    <p class="text-lg text-[var(--wa-text)] m-0 mb-2">{{ t('organization.internalChat') }}</p>
                    <p class="text-sm text-[var(--wa-text-secondary)] m-0">
                        {{ t('organization.selectConversation') }}
                        {{ t('organization.deptChatHint') }}
                    </p>
                </div>
            </div>
            <TeamChatShow
                v-else
                :selected-conversation-id="selectedConversationId"
                :conversation-header="conversationHeader"
            />
        </div>
    </OrganizationLayout>
</template>

<style scoped>
.team-chat-main {
    /* iOS: дать скроллу и инпуту предсказуемую высоту во flex-колонке */
    min-height: 0;
}
</style>
