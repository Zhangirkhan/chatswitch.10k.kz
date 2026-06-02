<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { provide, reactive } from 'vue';
import ChatHeaderContact from './ChatHeaderContact.vue';
import ChatHeaderToolbar from './ChatHeaderToolbar.vue';
import ChatHeaderFunnelBar from './ChatHeaderFunnelBar.vue';
import ChatHeaderDialogs from './ChatHeaderDialogs.vue';
import ScheduledMessagesModal from './ScheduledMessagesModal.vue';
import AiSimulatorModal from './AiSimulatorModal.vue';
import { useChatHeader } from './useChatHeader';
import type { ChatHeaderProps, ChatHeaderEmit } from './chatHeaderTypes';
import { CHAT_HEADER_DIALOGS_KEY } from './chatHeaderDialogsKey';
import { CHAT_HEADER_VIEW_KEY } from './chatHeaderViewKey';

const props = defineProps<ChatHeaderProps>();
const emit = defineEmits<ChatHeaderEmit>();

const state = useChatHeader(props, emit);
const { scheduledMessagesOpen, aiSimulatorOpen, chat } = state;
provide(CHAT_HEADER_VIEW_KEY, reactive(state));

const {
    departmentModalOpen,
    closeDepartmentModal,
    departmentSearchQuery,
    filteredDepartments,
    selectedDepartmentIds,
    toggleDepartment,
    savingDepartments,
    openDepartmentHistoryModal,
    departmentHistoryModalOpen,
    closeDepartmentHistoryModal,
    loadDepartmentHistory,
    departmentHistoryLoading,
    departmentHistoryError,
    departmentHistory,
    currentDepartmentsHistory,
    assignmentModalOpen,
    closeAssignmentModal,
    userSearchQuery,
    filteredAssignableUsers,
    selectedUserIds,
    toggleUser,
    savingUsers,
    openAssignmentHistoryModal,
    assignmentHistoryModalOpen,
    closeAssignmentHistoryModal,
    loadAssignmentHistory,
    assignmentHistoryLoading,
    assignmentHistoryError,
    assignmentHistory,
    currentAssignmentsHistory,
    formatAssignmentTime,
    assignableUserRoleLine,
    funnelModalOpen,
    closeFunnelModal,
    funnelCatalogList,
    funnelModalFunnelId,
    funnelModalStageId,
    funnelModalTracking,
    funnelModalLocked,
    funnelSaving,
    onFunnelSelect,
    modalStagesOrdered,
    modalStageIndex,
    modalFunnelColor,
    modalFunnelSegmentStyle,
    funnelWheelRef,
    saveFunnelModal,
    funnelHistoryLoading,
    funnelHistoryError,
    funnelHistory,
    aiRiskyEnableModalOpen,
    aiRiskyEnableModal,
    aiRiskyEnableConfirming,
    closeAiRiskyEnableModal,
    confirmAiRiskyEnable,
} = state;

provide(
    CHAT_HEADER_DIALOGS_KEY,
    reactive({
        departmentModalOpen,
        closeDepartmentModal,
        departmentSearchQuery,
        filteredDepartments,
        selectedDepartmentIds,
        toggleDepartment,
        savingDepartments,
        openDepartmentHistoryModal,
        departmentHistoryModalOpen,
        closeDepartmentHistoryModal,
        loadDepartmentHistory,
        departmentHistoryLoading,
        departmentHistoryError,
        departmentHistory,
        currentDepartmentsHistory,
        assignmentModalOpen,
        closeAssignmentModal,
        userSearchQuery,
        filteredAssignableUsers,
        selectedUserIds,
        toggleUser,
        savingUsers,
        openAssignmentHistoryModal,
        assignmentHistoryModalOpen,
        closeAssignmentHistoryModal,
        loadAssignmentHistory,
        assignmentHistoryLoading,
        assignmentHistoryError,
        assignmentHistory,
        currentAssignmentsHistory,
        formatAssignmentTime,
        assignableUserRoleLine,
        funnelModalOpen,
        closeFunnelModal,
        funnelCatalogList,
        funnelModalFunnelId,
        funnelModalStageId,
        funnelModalTracking,
        funnelModalLocked,
        funnelSaving,
        onFunnelSelect,
        modalStagesOrdered,
        modalStageIndex,
        modalFunnelColor,
        modalFunnelSegmentStyle,
        funnelWheelRef,
        saveFunnelModal,
        funnelHistoryLoading,
        funnelHistoryError,
        funnelHistory,
        aiRiskyEnableModalOpen,
        aiRiskyEnableModal,
        aiRiskyEnableConfirming,
        closeAiRiskyEnableModal,
        confirmAiRiskyEnable,
    }),
);
</script>

<template>
    <div class="min-h-[60px] py-1.5 bg-[var(--wa-panel-header)] flex items-center px-4 gap-3 shrink-0 relative overflow-hidden">
        <Link :href="route('chats.index')" class="sm:hidden text-[var(--wa-icon)]" aria-label="Назад к списку чатов">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
        </Link>

        <ChatHeaderContact />
        <ChatHeaderToolbar />
        <ChatHeaderDialogs />
        <ChatHeaderFunnelBar />

        <ScheduledMessagesModal
            :open="scheduledMessagesOpen"
            :chat-id="chat.id"
            @close="scheduledMessagesOpen = false"
        />

        <AiSimulatorModal
            :show="aiSimulatorOpen"
            :chat-id="chat.id"
            :chat-name="chat.chat_name"
            @close="aiSimulatorOpen = false"
        />
    </div>
</template>

<style scoped src="./chat-header.css"></style>
