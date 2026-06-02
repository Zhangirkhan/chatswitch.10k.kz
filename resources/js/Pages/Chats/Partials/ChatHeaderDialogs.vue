<script setup lang="ts">
import { inject } from 'vue';
import { Link } from '@inertiajs/vue3';
import Avatar from '@/Components/Avatar.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import UiModal from '@/Components/Ui/UiModal.vue';
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import FunnelStageWheelPicker from '@/Components/FunnelStageWheelPicker.vue';
import FunnelStageIcon from '@/Components/Funnel/FunnelStageIcon.vue';
import { useI18n } from '@/composables/useI18n';
import { CHAT_HEADER_DIALOGS_KEY } from './chatHeaderDialogsKey';
import type { ChatHeaderDialogsContext } from './chatHeaderDialogsContext';

const { t } = useI18n();
const ctx = inject(CHAT_HEADER_DIALOGS_KEY) as ChatHeaderDialogsContext;

function setFunnelWheelRef(el: unknown): void {
    ctx.funnelWheelRef = el as ChatHeaderDialogsContext['funnelWheelRef'];
}
</script>

<template>
        <UiModal
            :open="ctx.departmentModalOpen"
            :title="t('chats.headerDialogs.departmentsTitle')"
            :subtitle="t('chats.headerDialogs.departmentsSubtitle')"
            max-width="lg"
            :z-index="1200"
            :aria-label="t('chats.headerDialogs.departmentsTitle')"
            body-class="px-5 py-4 space-y-5"
            @close="ctx.closeDepartmentModal"
        >
                        <section>
                            <div class="flex items-center justify-between gap-3 mb-3">
                                <div>
                                    <h4 class="text-sm font-semibold text-[var(--wa-text)]">{{ t('chats.headerDialogs.attachDepartments') }}</h4>
                                    <p class="text-xs text-[var(--wa-text-secondary)]">{{ t('chats.headerDialogs.autoSaveHint') }}</p>
                                </div>
                                <span v-if="ctx.savingDepartments" class="text-xs text-[var(--wa-text-secondary)]">{{ t('chats.saving') }}</span>
                            </div>

                            <label class="assign-searchbox mb-3">
                                <svg class="w-5 h-5 shrink-0 opacity-55" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35m1.1-5.15a6.25 6.25 0 11-12.5 0 6.25 6.25 0 0112.5 0z" />
                                </svg>
                                <input
                                    v-model="ctx.departmentSearchQuery"
                                    type="search"
                                    autocomplete="off"
                                    :placeholder="t('chats.headerDialogs.searchDepartment')"
                                    class="assign-search"
                                />
                            </label>

                            <div class="rounded-xl border overflow-hidden" :style="{ borderColor: 'var(--wa-border)' }">
                                <button
                                    v-for="dept in ctx.filteredDepartments"
                                    :key="dept.id"
                                    type="button"
                                    class="assign-row"
                                    :class="{ 'assign-row-dept-active': ctx.selectedDepartmentIds.includes(dept.id) }"
                                    @click="ctx.toggleDepartment(dept.id)"
                                >
                                    <Avatar :name="dept.name" :size="36" variant="group" fallback-initials class="shrink-0" />
                                    <span class="flex-1 truncate text-left assign-name">{{ dept.name }}</span>
                                    <svg
                                        v-if="ctx.selectedDepartmentIds.includes(dept.id)"
                                        class="assign-check assign-check-dept"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2.8"
                                        viewBox="0 0 24 24"
                                        aria-hidden="true"
                                    >
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                    </svg>
                                </button>
                                <div
                                    v-if="ctx.filteredDepartments.length === 0"
                                    class="ui-empty-state ui-empty-state--dashed border-0 shadow-none rounded-xl text-left mx-3 my-3"
                                >
                                    {{ ctx.departmentSearchQuery.trim() ? t('chats.nothingFound') : t('chats.header.noDepartments') }}
                                </div>
                            </div>
                        </section>

                        <section class="border-t pt-4" :style="{ borderColor: 'var(--wa-border)' }">
                            <button
                                type="button"
                                class="w-full flex items-center justify-between gap-3 px-4 py-3 rounded-xl border text-left transition hover:bg-[var(--wa-panel-hover)]"
                                :style="{ borderColor: 'var(--wa-border)' }"
                                @click="ctx.openDepartmentHistoryModal"
                            >
                                <div class="flex items-center gap-3">
                                    <span class="w-8 h-8 rounded-full flex items-center justify-center shrink-0" :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-icon)' }">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                    </span>
                                    <div>
                                        <div class="text-sm font-medium text-[var(--wa-text)]">{{ t('chats.headerDialogs.departmentHistory') }}</div>
                                        <div class="text-xs text-[var(--wa-text-secondary)]">{{ t('chats.headerDialogs.departmentHistoryHint') }}</div>
                                    </div>
                                </div>
                                <svg class="w-4 h-4 shrink-0 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                </svg>
                            </button>
                        </section>
        </UiModal>

        <!-- Department History Modal -->
        <UiModal
            :open="ctx.departmentHistoryModalOpen"
            max-width="md"
            :z-index="1300"
            :aria-label="t('chats.headerDialogs.departmentHistory')"
            body-class="px-5 py-4"
            :show-close="false"
            @close="ctx.closeDepartmentHistoryModal"
        >
            <template #header>
                <div class="flex items-center gap-3 w-full min-w-0">
                    <button
                        type="button"
                        class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)] text-[var(--wa-text-secondary)] shrink-0"
                        :aria-label="t('chats.back')"
                        @click="ctx.closeDepartmentHistoryModal"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                        </svg>
                    </button>
                    <div class="flex-1 min-w-0">
                        <h3 class="text-base font-semibold text-[var(--wa-text)] m-0">{{ t('chats.headerDialogs.departmentHistory') }}</h3>
                        <p class="text-xs text-[var(--wa-text-secondary)] mb-0">{{ t('chats.headerDialogs.departmentHistoryHint') }}</p>
                    </div>
                    <button
                        type="button"
                        class="text-xs px-2.5 py-1.5 rounded-lg border hover:bg-[var(--wa-panel-hover)] text-[var(--wa-text-secondary)] shrink-0"
                        :style="{ borderColor: 'var(--wa-border)' }"
                        :disabled="ctx.departmentHistoryLoading"
                        @click="ctx.loadDepartmentHistory"
                    >
                        {{ t('chats.refresh') }}
                    </button>
                </div>
            </template>

                        <div v-if="ctx.departmentHistoryLoading" class="py-8 text-sm text-center text-[var(--wa-text-secondary)]">
                            {{ t('chats.loadingHistory') }}
                        </div>
                        <div v-else-if="ctx.departmentHistoryError" class="py-4 text-sm text-[var(--wa-danger)]">
                            {{ ctx.departmentHistoryError }}
                        </div>
                        <div v-else-if="ctx.departmentHistory.length === 0" class="space-y-3">
                            <div class="rounded-xl border px-4 py-4 text-sm text-[var(--wa-text-secondary)]" :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel-header)' }">
                                {{ t('chats.headerDialogs.departmentHistoryEmpty') }}
                            </div>
                            <div v-if="ctx.currentDepartmentsHistory.length" class="rounded-xl border px-4 py-3" :style="{ borderColor: 'var(--wa-border)' }">
                                <div class="text-xs font-semibold mb-2 text-[var(--wa-text-secondary)]">{{ t('chats.headerDialogs.currentDepartments') }}</div>
                                <div class="flex flex-wrap gap-2">
                                    <span
                                        v-for="row in ctx.currentDepartmentsHistory"
                                        :key="row.id"
                                        class="assign-chip assign-chip-dept"
                                    >
                                        {{ row.name }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <ol v-else class="space-y-3">
                            <li
                                v-for="item in ctx.departmentHistory"
                                :key="item.id"
                                class="rounded-xl border px-4 py-3"
                                :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel-header)' }"
                            >
                                <div class="text-sm text-[var(--wa-text)] leading-relaxed">{{ item.body }}</div>
                                <div class="mt-1 text-xs text-[var(--wa-text-secondary)]">{{ ctx.formatAssignmentTime(item.at) }}</div>
                            </li>
                        </ol>
        </UiModal>

        <UiModal
            :open="ctx.assignmentModalOpen"
            :title="t('chats.headerDialogs.assigneesTitle')"
            :subtitle="t('chats.headerDialogs.assigneesSubtitle')"
            max-width="lg"
            :z-index="1200"
            :aria-label="t('chats.header.assigneesTitle')"
            body-class="px-5 py-4 space-y-5"
            @close="ctx.closeAssignmentModal"
        >
                        <section>
                            <div class="flex items-center justify-between gap-3 mb-3">
                                <div>
                                    <h4 class="text-sm font-semibold text-[var(--wa-text)]">{{ t('chats.headerDialogs.assignEmployees') }}</h4>
                                    <p class="text-xs text-[var(--wa-text-secondary)]">{{ t('chats.headerDialogs.autoSaveHint') }}</p>
                                </div>
                                <span v-if="ctx.savingUsers" class="text-xs text-[var(--wa-text-secondary)]">{{ t('chats.saving') }}</span>
                            </div>

                            <label class="assign-searchbox mb-3">
                                <svg class="w-5 h-5 shrink-0 opacity-55" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35m1.1-5.15a6.25 6.25 0 11-12.5 0 6.25 6.25 0 0112.5 0z" />
                                </svg>
                                <input
                                    v-model="ctx.userSearchQuery"
                                    type="search"
                                    autocomplete="off"
                                    :placeholder="t('chats.headerDialogs.searchEmployee')"
                                    class="assign-search"
                                />
                            </label>

                            <div class="rounded-xl border overflow-hidden" :style="{ borderColor: 'var(--wa-border)' }">
                                <button
                                    v-for="u in ctx.filteredAssignableUsers"
                                    :key="u.id"
                                    type="button"
                                    class="assign-row"
                                    :class="{ 'assign-row-staff-active': ctx.selectedUserIds.includes(u.id) }"
                                    @click="ctx.toggleUser(u.id)"
                                >
                                    <UserAvatar :name="u.name" :size="36" class="shrink-0" />
                                    <span class="flex-1 min-w-0 text-left">
                                        <span class="block truncate assign-name">{{ u.name }}</span>
                                        <span
                                            v-if="ctx.assignableUserRoleLine(u)"
                                            class="block truncate assign-role"
                                            :style="{ color: 'var(--wa-text-secondary)' }"
                                        >
                                            {{ ctx.assignableUserRoleLine(u) }}
                                        </span>
                                    </span>
                                    <svg
                                        v-if="ctx.selectedUserIds.includes(u.id)"
                                        class="assign-check assign-check-staff"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2.8"
                                        viewBox="0 0 24 24"
                                        aria-hidden="true"
                                    >
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                    </svg>
                                </button>
                                <div
                                    v-if="ctx.filteredAssignableUsers.length === 0"
                                    class="ui-empty-state ui-empty-state--dashed border-0 shadow-none rounded-xl text-left mx-3 my-3"
                                >
                                    {{ ctx.userSearchQuery.trim() ? t('chats.nothingFound') : t('chats.headerDialogs.noUsersToAssign') }}
                                </div>
                            </div>
                        </section>

                        <section class="border-t pt-4" :style="{ borderColor: 'var(--wa-border)' }">
                            <button
                                type="button"
                                class="w-full flex items-center justify-between gap-3 px-4 py-3 rounded-xl border text-left transition hover:bg-[var(--wa-panel-hover)]"
                                :style="{ borderColor: 'var(--wa-border)' }"
                                @click="ctx.openAssignmentHistoryModal"
                            >
                                <div class="flex items-center gap-3">
                                    <span class="w-8 h-8 rounded-full flex items-center justify-center shrink-0" :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-icon)' }">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                    </span>
                                    <div>
                                        <div class="text-sm font-medium text-[var(--wa-text)]">{{ t('chats.headerDialogs.assignmentHistory') }}</div>
                                        <div class="text-xs text-[var(--wa-text-secondary)]">{{ t('chats.headerDialogs.assignmentChangesHint') }}</div>
                                    </div>
                                </div>
                                <svg class="w-4 h-4 shrink-0 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                </svg>
                            </button>
                        </section>
        </UiModal>

        <!-- Assignment History Modal -->
        <UiModal
            :open="ctx.assignmentHistoryModalOpen"
            max-width="md"
            :z-index="1300"
            :aria-label="t('chats.headerDialogs.assignmentHistory')"
            body-class="px-5 py-4"
            :show-close="false"
            @close="ctx.closeAssignmentHistoryModal"
        >
            <template #header>
                <div class="flex items-center gap-3 w-full min-w-0">
                    <button
                        type="button"
                        class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)] text-[var(--wa-text-secondary)] shrink-0"
                        :aria-label="t('chats.back')"
                        @click="ctx.closeAssignmentHistoryModal"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                        </svg>
                    </button>
                    <div class="flex-1 min-w-0">
                        <h3 class="text-base font-semibold text-[var(--wa-text)] m-0">{{ t('chats.headerDialogs.assignmentHistory') }}</h3>
                        <p class="text-xs text-[var(--wa-text-secondary)] mb-0">{{ t('chats.headerDialogs.assignmentHistoryHint') }}</p>
                    </div>
                    <button
                        type="button"
                        class="text-xs px-2.5 py-1.5 rounded-lg border hover:bg-[var(--wa-panel-hover)] text-[var(--wa-text-secondary)] shrink-0"
                        :style="{ borderColor: 'var(--wa-border)' }"
                        :disabled="ctx.assignmentHistoryLoading"
                        @click="ctx.loadAssignmentHistory"
                    >
                        {{ t('chats.refresh') }}
                    </button>
                </div>
            </template>

                        <div v-if="ctx.assignmentHistoryLoading" class="py-8 text-sm text-center text-[var(--wa-text-secondary)]">
                            {{ t('chats.loadingHistory') }}
                        </div>
                        <div v-else-if="ctx.assignmentHistoryError" class="py-4 text-sm text-[var(--wa-danger)]">
                            {{ ctx.assignmentHistoryError }}
                        </div>
                        <div v-else-if="ctx.assignmentHistory.length === 0" class="space-y-3">
                            <div class="rounded-xl border px-4 py-4 text-sm text-[var(--wa-text-secondary)]" :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel-header)' }">
                                {{ t('chats.headerDialogs.assignmentHistoryEmpty') }}
                            </div>
                            <div v-if="ctx.currentAssignmentsHistory.length" class="rounded-xl border px-4 py-3" :style="{ borderColor: 'var(--wa-border)' }">
                                <div class="text-xs font-semibold mb-2 text-[var(--wa-text-secondary)]">{{ t('chats.headerDialogs.currentAssignees') }}</div>
                                <div v-for="row in ctx.currentAssignmentsHistory" :key="row.id" class="text-sm py-1 text-[var(--wa-text)]">
                                    {{ row.user_name || ('#' + row.user_id) }}
                                    <span class="text-xs text-[var(--wa-text-secondary)]">
                                        {{ t('chats.headerDialogs.assignedBy', { name: row.assigned_by_name || '—', time: ctx.formatAssignmentTime(row.assigned_at) }) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <ol v-else class="space-y-3">
                            <li
                                v-for="item in ctx.assignmentHistory"
                                :key="item.id"
                                class="rounded-xl border px-4 py-3"
                                :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel-header)' }"
                            >
                                <div class="text-sm text-[var(--wa-text)] leading-relaxed">{{ item.body }}</div>
                                <div class="mt-1 text-xs text-[var(--wa-text-secondary)]">{{ ctx.formatAssignmentTime(item.at) }}</div>
                            </li>
                        </ol>
        </UiModal>

        <UiModal
            :open="ctx.funnelModalOpen"
            :title="t('chats.headerDialogs.funnelTitle')"
            :subtitle="t('chats.headerDialogs.funnelSubtitle')"
            max-width="lg"
            :z-index="1250"
            :aria-label="t('chats.headerDialogs.funnelTitle')"
            body-class="px-5 py-4 space-y-5"
            @close="ctx.closeFunnelModal"
        >
                        <div v-if="ctx.funnelCatalogList.length === 0" class="text-sm text-[var(--wa-text-secondary)] rounded-xl border px-4 py-3" :style="{ borderColor: 'var(--wa-border)' }">
                            {{ t('chats.headerDialogs.noFunnels') }}
                        </div>

                        <template v-else>
                            <div>
                                <label class="block text-xs font-semibold text-[var(--wa-text-secondary)] mb-2">{{ t('chats.headerDialogs.funnelLabel') }}</label>
                                <select
                                    :value="ctx.funnelModalFunnelId === null ? '' : String(ctx.funnelModalFunnelId)"
                                    class="w-full rounded-xl border px-3 py-2 text-sm bg-[var(--wa-panel-header)] text-[var(--wa-text)]"
                                    :style="{ borderColor: 'var(--wa-border)' }"
                                    @change="ctx.onFunnelSelect"
                                >
                                    <option value="">{{ t('chats.headerDialogs.funnelReset') }}</option>
                                    <option v-for="f in ctx.funnelCatalogList" :key="f.id" :value="String(f.id)">{{ f.name }}</option>
                                </select>
                            </div>

                            <div v-if="ctx.funnelModalFunnelId != null && ctx.modalStagesOrdered.length">
                                <div class="flex items-center justify-between gap-2 mb-2">
                                    <div class="text-xs font-semibold text-[var(--wa-text-secondary)]">{{ t('chats.headerDialogs.funnelStageLabel') }}</div>
                                    <span
                                        v-if="ctx.modalStageIndex >= 0"
                                        class="text-xs font-medium tabular-nums text-[var(--wa-text-secondary)]"
                                    >
                                        {{ ctx.modalStageIndex + 1 }} / {{ ctx.modalStagesOrdered.length }}
                                    </span>
                                </div>

                                <div
                                    v-if="ctx.modalStageIndex >= 0"
                                    class="mb-3 rounded-xl border px-3 py-2 text-sm font-medium text-[var(--wa-text)]"
                                    :style="{
                                        borderColor: 'var(--wa-border)',
                                        background: 'color-mix(in srgb, var(--wa-accent) 8%, var(--wa-panel-header))',
                                    }"
                                >
                                    <span class="inline-flex items-center gap-2">
                                        <FunnelStageIcon
                                            :type="ctx.modalStagesOrdered[ctx.modalStageIndex]?.stage_type"
                                            :size="16"
                                        />
                                        {{ ctx.modalStagesOrdered[ctx.modalStageIndex]?.name }}
                                    </span>
                                </div>

                                <div
                                    class="mb-4 flex h-2 gap-px overflow-hidden rounded-md px-px"
                                    role="presentation"
                                    aria-hidden="true"
                                >
                                    <span
                                        v-for="(s, i) in ctx.modalStagesOrdered"
                                        :key="`seg-${s.id}`"
                                        class="min-w-0 flex-1 rounded-[2px] transition-colors duration-300"
                                        :class="{
                                            'ring-1 ring-inset ring-white/40 dark:ring-black/30':
                                                i === ctx.modalStageIndex,
                                        }"
                                        :style="ctx.modalFunnelSegmentStyle(i, s.color || ctx.modalFunnelColor)"
                                        :title="s.name"
                                    />
                                </div>

                                <FunnelStageWheelPicker
                                    :ref="setFunnelWheelRef"
                                    v-model="ctx.funnelModalStageId"
                                    :stages="ctx.modalStagesOrdered"
                                    :accent-color="ctx.modalFunnelColor"
                                />

                                <p class="mt-2 text-[11px] text-[var(--wa-text-secondary)]">
                                    {{ t('chats.headerDialogs.funnelDrumHint') }}
                                </p>
                            </div>
                            <div
                                v-else-if="ctx.funnelModalFunnelId != null"
                                class="text-sm text-[var(--wa-text-secondary)] rounded-xl border px-4 py-3"
                                :style="{ borderColor: 'var(--wa-border)' }"
                            >
                                {{ t('chats.headerDialogs.funnelNoStages') }}
                            </div>
                            <label class="flex items-center gap-2 text-sm text-[var(--wa-text)] cursor-pointer">
                                <UiCheckbox v-model="ctx.funnelModalTracking" size="sm" />
                                {{ t('chats.headerDialogs.funnelAutoEval') }}
                            </label>
                            <label class="flex items-center gap-2 text-sm text-[var(--wa-text)] cursor-pointer">
                                <UiCheckbox v-model="ctx.funnelModalLocked" size="sm" />
                                {{ t('chats.headerDialogs.funnelPinStage') }}
                            </label>

                            <div class="flex gap-2">
                                <button
                                    type="button"
                                    class="flex-1 py-2.5 rounded-xl text-sm font-medium border"
                                    :style="{ borderColor: 'var(--wa-border)', color: 'var(--wa-text-secondary)' }"
                                    :disabled="ctx.funnelSaving"
                                    @click="
                                        ctx.funnelModalFunnelId = null;
                                        ctx.funnelModalStageId = null;
                                    "
                                >
                                    {{ t('chats.headerDialogs.funnelResetAction') }}
                                </button>
                                <button
                                    type="button"
                                    class="flex-1 py-2.5 rounded-xl text-sm font-medium text-white bg-[var(--wa-accent)] hover:opacity-95 disabled:opacity-50"
                                    :disabled="ctx.funnelSaving"
                                    @click="ctx.saveFunnelModal"
                                >
                                    {{ ctx.funnelSaving ? t('chats.saving') : t('common.save') }}
                                </button>
                            </div>

                            <div class="border-t pt-4" :style="{ borderColor: 'var(--wa-border)' }">
                                <div class="text-xs font-semibold text-[var(--wa-text-secondary)] mb-2">{{ t('chats.headerDialogs.funnelHistory') }}</div>
                                <div v-if="ctx.funnelHistoryLoading" class="text-sm text-[var(--wa-text-secondary)]">{{ t('chats.loading') }}</div>
                                <div v-else-if="ctx.funnelHistoryError" class="text-sm text-[var(--wa-danger)]">{{ ctx.funnelHistoryError }}</div>
                                <ul v-else-if="ctx.funnelHistory.length" class="space-y-2 max-h-48 overflow-y-auto wa-scrollbar text-xs text-[var(--wa-text-secondary)]">
                                    <li v-for="h in ctx.funnelHistory" :key="h.id" class="border rounded-lg px-3 py-2" :style="{ borderColor: 'var(--wa-border)' }">
                                        <span class="text-[var(--wa-text)]">{{ h.source }}</span>
                                        <span v-if="h.reason"> — {{ h.reason }}</span>
                                        <div class="mt-0.5 opacity-80">{{ h.created_at }}</div>
                                    </li>
                                </ul>
                                <div v-else class="text-xs text-[var(--wa-text-secondary)]">{{ t('chats.headerDialogs.funnelHistoryEmpty') }}</div>
                            </div>
                        </template>
        </UiModal>

        <UiModal
            :open="ctx.aiRiskyEnableModalOpen && !!ctx.aiRiskyEnableModal"
            :title="t('chats.headerDialogs.aiRiskyEnableTitle')"
            :subtitle="ctx.aiRiskyEnableModal?.readinessScore != null ? t('chats.headerDialogs.aiReadinessScore', { score: ctx.aiRiskyEnableModal.readinessScore }) : ''"
            max-width="md"
            :z-index="1210"
            :closeable="!ctx.aiRiskyEnableConfirming"
            body-class="px-5 py-4 space-y-4 text-sm text-[var(--wa-text)]"
            @close="ctx.closeAiRiskyEnableModal"
        >
            <template v-if="ctx.aiRiskyEnableModal">
                <p class="leading-relaxed m-0">{{ ctx.aiRiskyEnableModal.message }}</p>
                <div v-if="ctx.aiRiskyEnableModal.warnings.length > 0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-[var(--wa-text-secondary)] mb-2">{{ t('chats.headerDialogs.warnings') }}</p>
                    <ul class="list-disc pl-5 space-y-1.5 text-[var(--wa-text)]">
                        <li v-for="(w, i) in ctx.aiRiskyEnableModal.warnings" :key="i">{{ w }}</li>
                    </ul>
                </div>
                <Link
                    :href="ctx.aiRiskyEnableModal.settingsUrl"
                    class="inline-flex text-sm font-medium text-[var(--wa-accent)] hover:underline"
                    @click="ctx.closeAiRiskyEnableModal"
                >
                    {{ t('chats.headerDialogs.openAiReadiness') }}
                </Link>
            </template>

            <template #footer>
                <button
                    type="button"
                    class="py-2.5 px-4 rounded-xl text-sm font-medium border border-[var(--wa-border)] text-[var(--wa-text)] hover:bg-[var(--wa-panel-hover)] disabled:opacity-50"
                    :disabled="ctx.aiRiskyEnableConfirming"
                    @click="ctx.closeAiRiskyEnableModal"
                >
                    {{ t('common.cancel') }}
                </button>
                <button
                    type="button"
                    class="py-2.5 px-4 rounded-xl text-sm font-medium text-white bg-[var(--wa-accent)] hover:opacity-95 disabled:opacity-50"
                    :disabled="ctx.aiRiskyEnableConfirming"
                    @click="ctx.confirmAiRiskyEnable"
                >
                    {{ ctx.aiRiskyEnableConfirming ? t('chats.headerDialogs.enabling') : t('chats.headerDialogs.enableAiAnyway') }}
                </button>
            </template>
        </UiModal>

</template>

<style scoped src="./chat-header-assign.css"></style>
