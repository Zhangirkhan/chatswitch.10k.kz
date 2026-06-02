<script setup lang="ts">
import { inject } from 'vue';
import Avatar from '@/Components/Avatar.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { CHAT_HEADER_VIEW_KEY } from './chatHeaderViewKey';
import type { ChatHeaderViewContext } from './chatHeaderViewContext';

const s = inject(CHAT_HEADER_VIEW_KEY) as ChatHeaderViewContext;
</script>

<template>
        <div class="s.chat-header-toolbar flex flex-nowrap items-center gap-1.5 min-w-0 shrink">
            <!-- Отделы: сотрудник только видит свой; админ/руководитель — выбор -->
            <div class="header-dept-control relative">
                <div
                    v-if="!s.canEditChatDepartments"
                    class="label-pill label-pill-dept label-pill-dept-static cursor-default opacity-95"
                    :class="{ 'label-pill-dept-active': (s.page.props.auth?.user?.department_id ?? null) !== null }"
                    title="Ваш отдел. Изменить отделы чата могут только руководитель или администратор."
                >
                    <span class="truncate">{{ s.employeeOwnDepartmentLabel }}</span>
                </div>

                <template v-else>
                    <button
                        ref="s.departmentsBtnRef"
                        type="button"
                        class="label-pill label-pill-dept label-pill-icon label-pill-icon-badge"
                        :class="{ 'label-pill-dept-active': s.selectedDepartmentIds.length > 0 }"
                        :title="s.selectedDepartmentIds.length ? `Отделы: ${s.selectedDepartments.map((d) => d.name).join(', ')}` : 'Прикрепить отделы к чату'"
                        :aria-label="s.selectedDepartmentIds.length ? `Отделы: ${s.selectedDepartments.map((d) => d.name).join(', ')}` : 'Прикрепить отделы к чату'"
                        @click="s.openDepartmentModal"
                    >
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
                        </svg>
                        <span
                            v-if="s.selectedDepartmentIds.length > 0"
                            class="label-pill-count-badge ui-tab-badge ui-tab-badge--team"
                            aria-hidden="true"
                        >{{ s.selectedDepartmentIds.length }}</span>
                    </button>

                    <Teleport to="body">
                        <div
                            v-if="s.departmentsMenuOpen"
                            @click="s.closeDepartmentsMenu"
                            class="fixed inset-0 z-[900]"
                        ></div>

                        <div
                            v-if="s.departmentsMenuOpen"
                            ref="s.departmentsMenuPanelRef"
                            class="fixed w-[min(92vw,360px)] max-h-[min(90vh,440px)] flex flex-col rounded-xl shadow-2xl z-[1000] border header-menu assign-popover overflow-hidden"
                            :style="{
                                top: `${s.departmentsMenuPos.top}px`,
                                right: `${s.departmentsMenuPos.right}px`,
                                background: 'var(--wa-panel-header)',
                                borderColor: 'var(--wa-control-border)',
                            }"
                            @click.stop
                        >
                            <div
                                v-if="s.selectedDepartments.length"
                                class="assign-selected"
                            >
                                <button
                                    v-for="d in s.selectedDepartments"
                                    :key="d.id"
                                    type="button"
                                    class="assign-chip assign-chip-dept"
                                    :title="d.name"
                                    @click="s.toggleDepartment(d.id)"
                                >
                                    <span class="truncate">{{ d.name }}</span>
                                    <svg class="w-3.5 h-3.5 shrink-0 opacity-70" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                                <span
                                    v-if="s.savingDepartments"
                                    class="text-xs self-center"
                                    :style="{ color: 'var(--wa-text-secondary)' }"
                                >
                                    Сохранение...
                                </span>
                            </div>

                            <div
                                class="assign-search-wrap"
                                :style="{ borderColor: 'var(--wa-border)' }"
                            >
                                <label class="assign-searchbox">
                                    <svg class="w-5 h-5 shrink-0 opacity-55" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35m1.1-5.15a6.25 6.25 0 11-12.5 0 6.25 6.25 0 0112.5 0z" />
                                    </svg>
                                    <input
                                        v-model="s.departmentSearchQuery"
                                        type="search"
                                        autocomplete="off"
                                        placeholder="Поиск..."
                                        class="assign-search"
                                    />
                                </label>
                            </div>

                            <div class="assign-list wa-scrollbar flex-1 min-h-0 overflow-y-auto">
                                <button
                                    v-for="d in s.filteredDepartments"
                                    :key="d.id"
                                    type="button"
                                    class="assign-row"
                                    :class="{ 'assign-row-dept-active': s.selectedDepartmentIds.includes(d.id) }"
                                    @click="s.toggleDepartment(d.id)"
                                >
                                    <Avatar :name="d.name" :size="36" variant="group" fallback-initials class="shrink-0" />
                                    <span class="flex-1 truncate text-left assign-name">{{ d.name }}</span>
                                    <svg
                                        v-if="s.selectedDepartmentIds.includes(d.id)"
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
                                    v-if="s.filteredDepartments.length === 0"
                                    class="ui-empty-state ui-empty-state--dashed border-0 shadow-none rounded-none text-left"
                                >
                                    {{ s.departmentSearchQuery.trim() ? 'Ничего не найдено' : 'Нет доступных отделов. Создайте их в разделе «Настройки → Отделы».' }}
                                </div>
                            </div>
                        </div>
                    </Teleport>
                </template>
            </div>

            <div
                v-if="s.canManageAi"
                class="header-action-group header-ai-group header-ai-control"
                :class="{ 'header-ai-group-on': s.aiEnabled }"
            >
                <button
                    type="button"
                    class="header-ai-toggle"
                    :class="{ 'header-ai-toggle-on': s.aiEnabled }"
                    :title="s.aiEnabled ? 'AI сам отвечает на новые сообщения клиента. Нажмите, чтобы выключить.' : 'AI не отвечает автоматически. Нажмите, чтобы включить автоответы.'"
                    :aria-label="s.aiEnabled ? 'Выключить AI-автоответы' : 'Включить AI-автоответы'"
                    :disabled="s.aiSaving"
                    @click="s.toggleAi"
                >
                    <span class="ai-state-dot" :class="{ 'ai-state-dot-on': s.aiEnabled }"></span>
                    <span class="header-ai-toggle-text">{{ s.aiModeLabel }}</span>
                </button>

                <button
                    v-if="s.aiEnabled"
                    ref="s.aiSettingsBtnRef"
                    type="button"
                    class="ai-menu-trigger ai-settings-trigger"
                    :disabled="s.aiSaving"
                    :title="`Настройки AI: ${s.aiSettingsSummary}`"
                    aria-haspopup="dialog"
                    :aria-expanded="s.aiSettingsMenuOpen"
                    @click="s.toggleAiSettingsMenu"
                >
                    <svg class="w-4 h-4 shrink-0 opacity-80" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 010 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 010-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <span class="ai-menu-trigger-label hidden lg:inline truncate max-w-[7.5rem]">{{ s.aiSettingsSummary }}</span>
                </button>
            </div>

            <div class="header-ai-s.chat-control">
                <button
                    type="button"
                    class="header-ai-assistant-btn ui-status-badge"
                    :class="`ui-status-badge--${s.aiHeaderBadge.tone}`"
                    :title="s.aiAssistantButtonTitle"
                    :aria-label="s.aiAssistantAriaLabel"
                    @click="s.closeAiModeMenu(); s.closeAiResponderMenu(); s.closeAiSettingsMenu(); s.emit('open-ai')"
                >
                    <svg class="header-ai-assistant-btn__icon shrink-0" fill="none" stroke="currentColor" stroke-width="1.85" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3.5 3.5V16z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17.5 3.5l.75 1.5 1.5.75-1.5.75-.75 1.5-.75-1.5-1.5-.75 1.5-.75.75-1.5z" />
                    </svg>
                    <span class="header-ai-assistant-btn__label hidden xl:inline">AI-чат</span>
                    <span
                        v-if="s.aiAssistantNeedsAttention"
                        class="header-ai-assistant-btn__status-dot"
                        :class="`header-ai-assistant-btn__status-dot--${s.aiHeaderBadge.tone}`"
                        :title="s.aiHeaderBadge.label"
                        aria-hidden="true"
                    ></span>
                </button>
            </div>

            <div
                v-if="s.orchestratorStatusLabel && !s.funnelCompactLine"
                class="label-pill label-pill-orchestrator"
                :class="{
                    'label-pill-orchestrator-wait': s.chat.ai_orchestrator_status === 'needs_manager',
                    'label-pill-orchestrator-error': s.chat.ai_orchestrator_status === 'failed',
                }"
                :title="s.orchestratorStatusTitle"
            >
                <span class="ai-state-dot ai-state-dot-on"></span>
                <span class="truncate">{{ s.orchestratorStatusLabel }}</span>
            </div>

            <Teleport to="body">
                <template v-if="s.canManageAi && s.aiEnabled">
                    <div
                        v-if="s.aiSettingsMenuOpen"
                        class="fixed inset-0 z-[900]"
                        @click="s.closeAiSettingsMenu"
                    ></div>
                    <div
                        v-if="s.aiSettingsMenuOpen"
                        ref="s.aiSettingsMenuPanelRef"
                        class="fixed z-[1000] flex max-h-[min(88vh,480px)] w-[min(92vw,360px)] flex-col overflow-hidden rounded-xl border shadow-2xl header-menu assign-popover"
                        :style="{
                            top: `${s.aiSettingsMenuPos.top}px`,
                            right: `${s.aiSettingsMenuPos.right}px`,
                            background: 'var(--wa-panel-header)',
                            borderColor: 'var(--wa-control-border)',
                        }"
                        aria-label="Настройки AI"
                        @click.stop
                    >
                        <div
                            class="border-b px-3 py-2 text-xs font-semibold"
                            :style="{ borderColor: 'var(--wa-border)', color: 'var(--wa-text-secondary)' }"
                        >
                            Режим ответа
                        </div>
                        <div class="wa-scrollbar py-1">
                            <button
                                type="button"
                                class="assign-row"
                                :class="{ 'assign-row-staff-active': s.aiMode === 'auto' }"
                                role="option"
                                :aria-selected="s.aiMode === 'auto'"
                                @click="s.pickAiMode('auto')"
                            >
                                <span class="flex-1 min-w-0 text-left">
                                    <span class="block truncate assign-name">Автоответ</span>
                                    <span class="block truncate text-[11px] assign-role" :style="{ color: 'var(--wa-text-secondary)' }">
                                        Отправлять ответ клиенту автоматически
                                    </span>
                                </span>
                                <svg
                                    v-if="s.aiMode === 'auto'"
                                    class="assign-check assign-check-staff shrink-0"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2.8"
                                    viewBox="0 0 24 24"
                                    aria-hidden="true"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            </button>
                            <button
                                type="button"
                                class="assign-row"
                                :class="{ 'assign-row-staff-active': s.aiMode === 'draft' }"
                                role="option"
                                :aria-selected="s.aiMode === 'draft'"
                                @click="s.pickAiMode('draft')"
                            >
                                <span class="flex-1 min-w-0 text-left">
                                    <span class="block truncate assign-name">Черновик</span>
                                    <span class="block truncate text-[11px] assign-role" :style="{ color: 'var(--wa-text-secondary)' }">
                                        Подставлять текст в поле ввода без отправки
                                    </span>
                                </span>
                                <svg
                                    v-if="s.aiMode === 'draft'"
                                    class="assign-check assign-check-staff shrink-0"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2.8"
                                    viewBox="0 0 24 24"
                                    aria-hidden="true"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            </button>
                        </div>
                        <template v-if="s.showAiResponderSelect">
                        <div
                            class="border-t border-b px-3 py-2 text-xs font-semibold"
                            :style="{ borderColor: 'var(--wa-border)', color: 'var(--wa-text-secondary)' }"
                        >
                            От чьего имени AI
                        </div>
                        <div
                            class="assign-search-wrap"
                            :style="{ borderColor: 'var(--wa-border)' }"
                        >
                            <label class="assign-searchbox">
                                <svg class="w-5 h-5 shrink-0 opacity-55" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35m1.1-5.15a6.25 6.25 0 11-12.5 0 6.25 6.25 0 0112.5 0z" />
                                </svg>
                                <input
                                    v-model="s.aiResponderSearchQuery"
                                    type="search"
                                    autocomplete="off"
                                    placeholder="Поиск..."
                                    class="assign-search"
                                />
                            </label>
                        </div>
                        <div class="assign-list wa-scrollbar flex-1 min-h-0 overflow-y-auto">
                            <button
                                type="button"
                                class="assign-row"
                                :class="{ 'assign-row-staff-active': s.chat.ai_responder_user_id == null }"
                                role="option"
                                :aria-selected="s.chat.ai_responder_user_id == null"
                                @click="s.pickAiResponder(null)"
                            >
                                <span class="assign-avatar assign-avatar-staff" aria-hidden="true">AI</span>
                                <span class="flex-1 min-w-0 text-left">
                                    <span class="block truncate assign-name">Автовыбор</span>
                                    <span class="block truncate text-[11px] assign-role" :style="{ color: 'var(--wa-text-secondary)' }">
                                        Система выберет ответчика из назначенных на чат
                                    </span>
                                </span>
                                <svg
                                    v-if="s.chat.ai_responder_user_id == null"
                                    class="assign-check assign-check-staff shrink-0"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2.8"
                                    viewBox="0 0 24 24"
                                    aria-hidden="true"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            </button>
                            <button
                                v-for="u in s.filteredAiResponderPicker"
                                :key="u.id"
                                type="button"
                                class="assign-row"
                                :class="{ 'assign-row-staff-active': s.chat.ai_responder_user_id === u.id }"
                                role="option"
                                :aria-selected="s.chat.ai_responder_user_id === u.id"
                                @click="s.pickAiResponder(u.id)"
                            >
                                <UserAvatar :name="u.name" :size="36" class="shrink-0" />
                                <span class="flex-1 min-w-0 text-left">
                                    <span class="block truncate assign-name">{{ u.name }}</span>
                                    <span
                                        v-if="s.assignableUserRoleLine(u)"
                                        class="block truncate text-[11px] assign-role"
                                        :style="{ color: 'var(--wa-text-secondary)' }"
                                    >
                                        {{ s.assignableUserRoleLine(u) }}
                                    </span>
                                </span>
                                <svg
                                    v-if="s.chat.ai_responder_user_id === u.id"
                                    class="assign-check assign-check-staff shrink-0"
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
                                v-if="s.aiResponderSearchQuery.trim() && s.filteredAiResponderPicker.length === 0"
                                class="px-5 py-4 text-sm"
                                :style="{ color: 'var(--wa-text-secondary)' }"
                            >
                                Ничего не найдено
                            </div>
                        </div>
                        </template>
                    </div>
                </template>
            </Teleport>

            <!-- Сотрудники: одна кнопка с аватарками; зелёная иерархия -->
            <div v-if="s.showAssignUsersBlock" class="header-staff-control relative">
                <button
                    ref="s.usersBtnRef"
                    type="button"
                    class="label-pill label-pill-staff label-pill-staff-avatars"
                    :class="{
                        'label-pill-staff-active': s.selectedUserIds.length > 0,
                        'opacity-50 cursor-not-allowed': s.assignUsersDisabled,
                    }"
                    :disabled="s.assignUsersDisabled"
                    :title="s.assignUsersButtonTitle"
                    @click="s.onAssignUsersButtonClick"
                >
                    <div
                        v-if="s.selectedUsers.length"
                        class="header-staff-avatar-stack shrink-0"
                        aria-hidden="true"
                    >
                        <UserAvatar
                            v-for="u in s.selectedUsers.slice(0, 3)"
                            :key="u.id"
                            :name="u.name"
                            :size="22"
                            class="header-staff-avatar"
                            :title="u.name"
                        />
                        <div
                            v-if="s.selectedUserIds.length > 3"
                            class="staff-pill-avatar header-staff-avatar header-staff-more"
                        >
                            +{{ s.selectedUserIds.length - 3 }}
                        </div>
                    </div>
                    <span
                        v-else
                        class="staff-pill-icon"
                        aria-hidden="true"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a4.125 4.125 0 11-8.25 0 4.125 4.125 0 018.25 0zM2.25 19.125a8.25 8.25 0 0114.59-5.252" />
                        </svg>
                    </span>
                </button>

                <Teleport to="body">
                    <div
                        v-if="s.usersMenuOpen"
                        @click="s.closeUsersMenu"
                        class="fixed inset-0 z-[900]"
                    ></div>

                    <div
                        v-if="s.usersMenuOpen"
                        ref="s.usersMenuPanelRef"
                            class="fixed w-[min(92vw,360px)] max-h-[min(90vh,440px)] flex flex-col rounded-xl shadow-2xl z-[1000] border header-menu assign-popover overflow-hidden"
                        :style="{
                            top: `${s.usersMenuPos.top}px`,
                            right: `${s.usersMenuPos.right}px`,
                            background: 'var(--wa-panel-header)',
                            borderColor: 'var(--wa-control-border)',
                        }"
                        @click.stop
                    >
                        <div
                            v-if="s.selectedUsers.length"
                            class="assign-selected"
                        >
                            <button
                                v-for="u in s.selectedUsers"
                                :key="u.id"
                                type="button"
                                class="assign-chip assign-chip-staff"
                                :title="u.name"
                                @click="s.toggleUser(u.id)"
                            >
                                <span class="truncate">{{ u.name }}</span>
                                <svg class="w-4 h-4 shrink-0 opacity-70" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                            <span
                                v-if="s.savingUsers"
                                class="text-xs self-center"
                                :style="{ color: 'var(--wa-text-secondary)' }"
                            >
                                Сохранение...
                            </span>
                        </div>

                        <div
                            class="assign-search-wrap"
                            :style="{ borderColor: 'var(--wa-border)' }"
                        >
                            <label class="assign-searchbox">
                                <svg class="w-5 h-5 shrink-0 opacity-55" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35m1.1-5.15a6.25 6.25 0 11-12.5 0 6.25 6.25 0 0112.5 0z" />
                                </svg>
                                <input
                                    v-model="s.userSearchQuery"
                                    type="search"
                                    autocomplete="off"
                                    placeholder="Поиск..."
                                    class="assign-search"
                                />
                            </label>
                        </div>

                        <div class="assign-list wa-scrollbar flex-1 min-h-0 overflow-y-auto">
                            <button
                                v-for="u in s.filteredAssignableUsers"
                                :key="u.id"
                                type="button"
                                class="assign-row"
                                :class="{ 'assign-row-staff-active': s.selectedUserIds.includes(u.id) }"
                                @click="s.toggleUser(u.id)"
                            >
                                <UserAvatar :name="u.name" :size="36" class="shrink-0" />
                                <span class="flex-1 min-w-0 text-left">
                                    <span class="block truncate assign-name">{{ u.name }}</span>
                                    <div
                                        v-if="s.assignableUserRoleLine(u)"
                                        class="truncate assign-role"
                                        :style="{ color: 'var(--wa-text-secondary)' }"
                                    >
                                        {{ s.assignableUserRoleLine(u) }}
                                    </div>
                                </span>
                                <svg
                                    v-if="s.selectedUserIds.includes(u.id)"
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
                                v-if="s.filteredAssignableUsers.length === 0"
                                class="ui-empty-state ui-empty-state--dashed border-0 shadow-none rounded-none text-left"
                            >
                                {{ s.userSearchQuery.trim() ? 'Ничего не найдено' : 'Нет пользователей для списка' }}
                            </div>
                        </div>
                    </div>
                </Teleport>
            </div>

            <div
                v-else-if="s.chat.assignments?.length"
                class="header-staff-control label-pill label-pill-staff label-pill-staff-static label-pill-staff-avatars"
                title="Ответственные за этот чат"
            >
                <div class="header-staff-avatar-stack shrink-0" aria-hidden="true">
                    <UserAvatar
                        v-for="a in s.chat.assignments.slice(0, 3)"
                        :key="a.id"
                        :name="a.user?.name"
                        :size="22"
                        class="header-staff-avatar"
                    />
                    <div
                        v-if="s.chat.assignments.length > 3"
                        class="staff-pill-avatar header-staff-avatar header-staff-more"
                    >
                        +{{ s.chat.assignments.length - 3 }}
                    </div>
                </div>
            </div>

            <div class="header-menu-control flex items-center gap-1 shrink-0">
                <button type="button" class="wa-header-btn shrink-0 hidden xl:flex" title="Поиск" @click="s.openSearch">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </button>

                <div class="relative shrink-0">
                    <button
                        ref="s.menuBtnRef"
                        class="wa-header-btn shrink-0"
                        title="Меню"
                        @click="s.toggleMenu"
                        type="button"
                    >
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                            <circle cx="12" cy="5" r="2"/>
                            <circle cx="12" cy="12" r="2"/>
                            <circle cx="12" cy="19" r="2"/>
                        </svg>
                    </button>

                <Teleport to="body">
                    <div
                        v-if="s.menuOpen"
                        @click="s.closeMenu"
                        class="fixed inset-0 z-[900]"
                    ></div>

                    <div
                        v-if="s.menuOpen"
                        ref="s.overflowMenuPanelRef"
                        class="fixed min-w-[240px] rounded-lg shadow-xl py-2 z-[1000] border header-menu"
                        :style="{
                            top: `${s.menuPos.top}px`,
                            right: `${s.menuPos.right}px`,
                            background: 'var(--wa-panel-header)',
                            borderColor: 'var(--wa-control-border)',
                        }"
                    >
                    <button class="menu-item" @click="s.openContactInfo" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Данные контакта
                    </button>
                    <button class="menu-item" @click="s.openSearch" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        Поиск
                    </button>
                    <button class="menu-item" @click="s.scheduledMessagesOpen = true; s.closeMenu()" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l2.5 1.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Отложенные сообщения
                    </button>
                    <button
                        v-if="s.funnelModuleVisible"
                        class="menu-item"
                        type="button"
                        @click="s.openFunnelModal(); s.closeMenu()"
                    >
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5" />
                        </svg>
                        Этап воронки
                    </button>
                    <button
                        v-if="s.page.props.modules?.org_tasks"
                        class="menu-item"
                        type="button"
                        :disabled="s.quickTaskLoading"
                        @click="s.closeMenu(); s.createQuickTask()"
                    >
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        {{ s.quickTaskLoading ? 'Создание задачи…' : 'Задача по чату' }}
                    </button>
                    <button
                        v-if="s.canManageAi"
                        class="menu-item"
                        type="button"
                        @click="s.aiSimulatorOpen = true; s.closeMenu()"
                    >
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714a2.25 2.25 0 00.659 1.591L19 14.5M14.25 3.104c.251.023.501.05.75.082M19 14.5l-2.47 2.47a2.25 2.25 0 01-1.59.659H9.06a2.25 2.25 0 01-1.591-.659L5 14.5m14 0V17.25a2.25 2.25 0 01-2.25 2.25H7.25A2.25 2.25 0 015 17.25V14.5" />
                        </svg>
                        Симулятор AI
                    </button>
                    <button
                        class="menu-item menu-item-danger"
                        :disabled="s.archivingChat"
                        @click="s.archiveAndCloseChat"
                        type="button"
                    >
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        Закрыть чат и в архив
                    </button>
                    <button class="menu-item" @click="s.closeChatWindow" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        Закрыть окно чата
                    </button>
                    </div>
                </Teleport>
                </div>
            </div>
        </div>
</template>
