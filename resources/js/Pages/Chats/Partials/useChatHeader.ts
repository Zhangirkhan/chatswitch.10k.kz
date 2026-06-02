import { router, usePage } from '@inertiajs/vue3';
import { ref, onBeforeUnmount, computed, watch, nextTick } from 'vue';
import axios from 'axios';
import FunnelStageWheelPicker from '@/Components/FunnelStageWheelPicker.vue';
import type { AssignableUser, Department } from '@/types';
import { formatPhone } from '@/utils/phone';
import { initialsFromName } from '@/utils/initials';
import { stageIdAtPreservedIndex } from '@/utils/funnelStageMapping';
import { useToastStore } from '@/stores/toast';
import type { ChatHeaderProps, ChatHeaderEmit, AiRiskyEnableModalState } from './chatHeaderTypes';
import { computeMenuPosition, type MenuPos } from '@/utils/chatHeaderMenuPosition';

export function useChatHeader(props: ChatHeaderProps, emit: ChatHeaderEmit) {
    const { show: showToast } = useToastStore();
    const page = usePage<any>();
    const chat = computed(() => props.chat);
    const typingUsers = computed(() => props.typingUsers);

    /** Сотрудник не меняет отделы чата — только админ и руководитель. */
    const canEditChatDepartments = computed(() => {
        const roles = page.props.auth?.user?.roles ?? [];
        if (roles.includes('administrator')) return true;
        if (roles.includes('manager')) return true;
        return false;
    });

    /** Подпись для сотрудника: только свой отдел из профиля. */
    const employeeOwnDepartmentLabel = computed(() => {
        const name = page.props.auth?.user?.department?.name?.trim();
        return name && name.length > 0 ? name : 'Без отдела';
    });

    const menuOpen = ref(false);
    const menuBtnRef = ref<HTMLButtonElement | null>(null);
    const menuPos = ref<MenuPos>({ top: 0, right: 0 });

    const departmentsList = computed<Department[]>(() => props.departments ?? []);
    const departmentsMenuOpen = ref(false);
    const departmentsBtnRef = ref<HTMLButtonElement | null>(null);
    const departmentsMenuPos = ref<MenuPos>({ top: 0, right: 0 });
    const selectedDepartmentIds = ref<number[]>([]);
    const savingDepartments = ref(false);
    const departmentModalOpen = ref(false);
    const departmentHistoryModalOpen = ref(false);
    const departmentHistoryLoading = ref(false);
    const departmentHistoryError = ref<string | null>(null);
    const departmentHistory = ref<Array<{ id: number; body: string; at: string | null }>>([]);
    const currentDepartmentsHistory = ref<Array<{ id: number; name: string }>>([]);
    const departmentSearchQuery = ref('');
    const scheduledMessagesOpen = ref(false);
    const archivingChat = ref(false);
    let saveDepartmentsTimer: number | null = null;
    let saveDepartmentsQueued = false;

    function syncSelectedFromChat() {
        selectedDepartmentIds.value = (props.chat.departments ?? []).map((d) => d.id);
    }
    syncSelectedFromChat();
    watch(() => props.chat.id, syncSelectedFromChat);
    watch(() => props.chat.departments, syncSelectedFromChat, { deep: true });
    watch(departmentsMenuOpen, (open) => {
        if (!open) {
            departmentSearchQuery.value = '';
        }
    });

    const selectedDepartments = computed<Department[]>(() =>
        departmentsList.value.filter((d) => selectedDepartmentIds.value.includes(d.id)),
    );

    const filteredDepartments = computed<Department[]>(() => {
        const q = departmentSearchQuery.value.trim().toLowerCase();
        if (!q) {
            return departmentsList.value;
        }
        return departmentsList.value.filter((d) => d.name.toLowerCase().includes(q));
    });

    function toggleDepartmentsMenu() {
        if (departmentsMenuOpen.value) {
            departmentsMenuOpen.value = false;
            return;
        }
        closeMenu();
        closeUsersMenu();
        closeAiModeMenu();
        closeAiResponderMenu();
        departmentsMenuPos.value = computeMenuPosition(departmentsBtnRef.value);
        departmentsMenuOpen.value = true;
    }

    async function openDepartmentModal() {
        departmentsMenuOpen.value = false;
        closeAiModeMenu();
        closeAiResponderMenu();
        departmentModalOpen.value = true;
        departmentSearchQuery.value = '';
        await loadDepartmentHistory();
    }

    async function loadDepartmentHistory() {
        departmentHistoryLoading.value = true;
        departmentHistoryError.value = null;
        try {
            const { data } = await axios.get(route('chats.departments.history', props.chat.id));
            departmentHistory.value = data.history ?? [];
            currentDepartmentsHistory.value = data.current ?? [];
        } catch (e: any) {
            departmentHistoryError.value = e?.response?.data?.message || e?.message || 'Не удалось загрузить историю отделов';
        } finally {
            departmentHistoryLoading.value = false;
        }
    }

    function closeDepartmentModal() {
        departmentModalOpen.value = false;
        departmentSearchQuery.value = '';
    }

    async function openDepartmentHistoryModal() {
        departmentHistoryModalOpen.value = true;
        if (departmentHistory.value.length === 0 && currentDepartmentsHistory.value.length === 0) {
            await loadDepartmentHistory();
        }
    }

    function closeDepartmentHistoryModal() {
        departmentHistoryModalOpen.value = false;
    }

    function closeDepartmentsMenu() {
        departmentsMenuOpen.value = false;
    }

    function toggleDepartment(id: number) {
        const idx = selectedDepartmentIds.value.indexOf(id);
        if (idx === -1) {
            selectedDepartmentIds.value = [...selectedDepartmentIds.value, id];
        } else {
            selectedDepartmentIds.value = selectedDepartmentIds.value.filter((v) => v !== id);
        }
        scheduleSaveDepartments();
    }

    function scheduleSaveDepartments() {
        if (saveDepartmentsTimer !== null) {
            window.clearTimeout(saveDepartmentsTimer);
        }
        saveDepartmentsTimer = window.setTimeout(() => {
            saveDepartmentsTimer = null;
            void saveDepartments(false);
        }, 250);
    }

    async function saveDepartments(closeAfterSave = true) {
        if (savingDepartments.value) {
            saveDepartmentsQueued = true;
            return;
        }
        saveDepartmentsQueued = false;
        savingDepartments.value = true;
        try {
            await axios.post(route('chats.departments.sync', props.chat.id), {
                department_ids: selectedDepartmentIds.value,
            });
            if (departmentModalOpen.value) {
                await loadDepartmentHistory();
            }
            router.reload({ only: ['chat', 'unreadChatsCount'] });
            if (closeAfterSave) {
                closeDepartmentsMenu();
            }
        } finally {
            savingDepartments.value = false;
            if (saveDepartmentsQueued) {
                saveDepartmentsQueued = false;
                scheduleSaveDepartments();
            }
        }
    }

    // --- Assignable users (сотрудники + руководители) ---------------------------
    const assignableUsersList = computed<AssignableUser[]>(() => props.assignableUsers ?? []);
    const isAdministrator = computed(() => (page.props.auth?.user?.roles ?? []).includes('administrator'));
    const isManager = computed(() => (page.props.auth?.user?.roles ?? []).includes('manager'));

    /** Селект ответчика AI — только админ/руководитель (источник правды: Laravel, см. auth.user.can_pick_ai_responder). */
    const showAiResponderSelect = computed(() => {
        const u = page.props.auth?.user;
        if (u && typeof u.can_pick_ai_responder === 'boolean') {
            return u.can_pick_ai_responder;
        }
        return isAdministrator.value || isManager.value;
    });

    const canManageAi = computed(() => props.chat.can_manage_ai === true);
    const aiEnabled = computed(() => props.chat.ai_enabled === true);
    const aiMode = computed<'auto' | 'draft'>(() => (props.chat.ai_mode === 'draft' ? 'draft' : 'auto'));
    const aiSaving = ref(false);

    const aiModeMenuOpen = ref(false);
    const aiModeBtnRef = ref<HTMLButtonElement | null>(null);
    const aiModeMenuPos = ref<MenuPos>({ top: 0, right: 0 });

    const aiResponderMenuOpen = ref(false);
    const aiResponderBtnRef = ref<HTMLButtonElement | null>(null);
    const aiResponderMenuPos = ref<MenuPos>({ top: 0, right: 0 });
    const aiResponderSearchQuery = ref('');

    const aiSettingsMenuOpen = ref(false);
    const aiSettingsBtnRef = ref<HTMLButtonElement | null>(null);
    const aiSettingsMenuPos = ref<MenuPos>({ top: 0, right: 0 });
    const aiSettingsMenuPanelRef = ref<HTMLElement | null>(null);

    const aiResponderName = computed(() => {
        const id = props.chat.ai_responder_user_id;
        if (id == null) {
            return 'не выбран';
        }

        return assignableUsersList.value.find((user) => user.id === id)?.name
            || props.chat.ai_responder?.name
            || `#${id}`;
    });
    const aiStatusLabel = computed(() => {
        if (!props.aiStatus) {
            return 'AI ещё не отвечал';
        }
        return props.aiStatus.label || props.aiStatus.status;
    });

    const aiModeLabel = computed(() => {
        if (!aiEnabled.value) {
            return 'AI';
        }

        return aiMode.value === 'draft' ? 'Черн.' : 'AI';
    });

    const aiSettingsSummary = computed(() => {
        if (!aiEnabled.value) {
            return '';
        }
        const mode = aiMode.value === 'draft' ? 'Черновик' : 'Авто';
        const who = aiResponderMenuButtonLabel.value;

        return `${mode} · ${who}`;
    });

    /** Единый компактный статус AI в шапке (5 состояний). */
    const aiHeaderBadge = computed(() => {
        if (!aiEnabled.value) {
            return { label: 'Выкл', tone: 'off' as const, title: 'AI выключен для этого чата' };
        }
        if (props.chat.ai_orchestrator_status === 'failed' || props.aiStatus?.status === 'failed') {
            return { label: 'Ошибка', tone: 'error' as const, title: orchestratorStatusTitle.value || aiStatusTitle.value };
        }
        if (props.chat.ai_orchestrator_status === 'needs_manager' || props.aiStatus?.status === 'blocked') {
            return { label: 'Ждёт вас', tone: 'warning' as const, title: orchestratorStatusTitle.value || aiStatusTitle.value };
        }
        if (
            props.chat.ai_orchestrator_status === 'running'
            || props.chat.ai_orchestrator_status === 'pending'
            || props.aiStatus?.status === 'generating'
            || props.aiStatus?.status === 'pending'
        ) {
            return { label: 'Думает', tone: 'busy' as const, title: aiStatusTitle.value };
        }
        if (props.aiStatus?.status === 'drafted' || aiMode.value === 'draft') {
            return { label: 'Черновик', tone: 'idle' as const, title: aiStatusTitle.value };
        }
        if (orchestratorStatusLabel.value) {
            return {
                label: orchestratorStatusLabel.value,
                tone: aiSnapshotTone.value === 'ready' ? 'idle' as const : (aiSnapshotTone.value as 'busy' | 'warning' | 'error' | 'idle'),
                title: orchestratorStatusTitle.value,
            };
        }

        return {
            label: 'Авто',
            tone: 'idle' as const,
            title: aiStatusTitle.value,
        };
    });

    const aiAssistantButtonTitle = computed(() => {
        const status = aiHeaderBadge.value.label;
        const lines = ['Открыть AI-чат с ассистентом', `Статус: ${status}`];
        const detail = aiHeaderBadge.value.title?.trim();
        if (detail) {
            lines.push(detail);
        }

        return lines.join('\n');
    });

    const aiAssistantAriaLabel = computed(() => `Открыть AI-чат, статус: ${aiHeaderBadge.value.label}`);

    const aiAssistantNeedsAttention = computed(() => {
        const tone = aiHeaderBadge.value.tone;
        return tone === 'busy' || tone === 'warning' || tone === 'error';
    });

    const aiModePickerLabel = computed(() => (aiMode.value === 'draft' ? 'Черновик' : 'Автоответ'));

    const aiResponderMenuButtonLabel = computed(() => {
        const id = props.chat.ai_responder_user_id;
        if (id == null) {
            return 'Авто';
        }

        const name = assignableUsersList.value.find((user) => user.id === id)?.name
            || props.chat.ai_responder?.name
            || `#${id}`;
        const max = 11;

        return name.length > max ? `${name.slice(0, max)}…` : name;
    });

    const aiStatusTitle = computed(() => {
        const lines = [
            `AI-ассистент: ${aiStatusLabel.value}.`,
            props.aiStatus?.message,
            props.aiStatus?.hint,
            props.aiStatus?.tone_source?.label,
            `Режим: ${aiModeLabel.value}.`,
            `Ответчик: ${aiResponderName.value}.`,
        ].filter(Boolean);

        if (isAdministrator.value && props.aiStatus?.technical_error) {
            lines.push(`Технически: ${props.aiStatus.technical_error}`);
        }

        return lines.join('\n');
    });

    const orchestratorStatusLabel = computed(() => {
        const status = props.chat.ai_orchestrator_status;
        if (status === 'running' || status === 'pending') return 'AI ведёт';
        if (status === 'needs_manager') return 'Нужен менеджер';
        if (status === 'completed') return 'AI шаг';
        if (status === 'failed') return 'AI ошибка';
        if (status === 'skipped') return 'AI пропуск';
        return '';
    });

    const orchestratorStatusTitle = computed(() => {
        const lines = [
            orchestratorStatusLabel.value ? `AI-оркестратор: ${orchestratorStatusLabel.value}` : '',
            props.chat.ai_orchestrator_last_summary,
        ].filter(Boolean);

        return lines.join('\n');
    });

    /** У руководителя — как раньше; у админа кнопка видна всегда, но без отделов у чата неактивна. */
    const showAssignUsersBlock = computed(() => {
        if (isManager.value) {
            return assignableUsersList.value.length > 0;
        }
        return isAdministrator.value;
    });

    const assignUsersDisabled = computed(() => assignableUsersList.value.length === 0);

    const assignUsersButtonTitle = computed(() => {
        if (assignableUsersList.value.length === 0) {
            return 'Нет активных пользователей в системе.';
        }
        return selectedUserIds.value.length
            ? selectedUsers.value.map((u) => u.name).join(', ')
            : 'Назначить сотрудников на чат';
    });
    const usersMenuOpen = ref(false);
    const usersBtnRef = ref<HTMLButtonElement | null>(null);
    const usersMenuPos = ref<MenuPos>({ top: 0, right: 0 });
    /** Корни выпадашек (Teleport): скролл внутри списка не должен закрывать окно */
    const departmentsMenuPanelRef = ref<HTMLElement | null>(null);
    const usersMenuPanelRef = ref<HTMLElement | null>(null);
    const overflowMenuPanelRef = ref<HTMLElement | null>(null);
    const aiModeMenuPanelRef = ref<HTMLElement | null>(null);
    const aiResponderMenuPanelRef = ref<HTMLElement | null>(null);
    const selectedUserIds = ref<number[]>([]);
    const savingUsers = ref(false);
    const assignmentModalOpen = ref(false);
    const assignmentHistoryModalOpen = ref(false);
    const assignmentHistoryLoading = ref(false);
    const assignmentHistoryError = ref<string | null>(null);
    const assignmentHistory = ref<Array<{ id: number; body: string; at: string | null }>>([]);
    const currentAssignmentsHistory = ref<Array<{ id: number; user_id: number; user_name: string | null; assigned_by_name: string | null; assigned_at: string | null }>>([]);
    let saveUsersTimer: number | null = null;
    let saveUsersQueued = false;

    function syncSelectedUsersFromChat() {
        selectedUserIds.value = (props.chat.assignments ?? []).map((a) => a.user_id);
    }
    syncSelectedUsersFromChat();
    watch(() => props.chat.id, syncSelectedUsersFromChat);
    watch(() => props.chat.assignments, syncSelectedUsersFromChat, { deep: true });

    const selectedUsers = computed<AssignableUser[]>(() =>
        assignableUsersList.value.filter((u) => selectedUserIds.value.includes(u.id)),
    );

    const aiResponderPickerSource = computed<AssignableUser[]>(() =>
        selectedUsers.value.length > 0 ? selectedUsers.value : assignableUsersList.value,
    );

    const usersLabel = computed<string>(() => {
        const count = selectedUserIds.value.length;
        if (count === 0) return 'Назначить сотрудников';
        if (count === 1) return selectedUsers.value[0]?.name ?? 'Сотрудник';
        return `Сотрудники: ${count}`;
    });

    function roleLabel(roles: string[]): string {
        if (roles.includes('administrator')) return 'Администратор';
        if (roles.includes('manager')) return 'Руководитель';
        if (roles.includes('employee')) return 'Сотрудник';
        return '';
    }

    function userInitials(name?: string | null): string {
        return initialsFromName(name, 'С');
    }

    async function patchAiSettings(payload: Record<string, unknown>): Promise<void> {
        await axios.patch(route('chats.ai.update', props.chat.id), {
            ai_enabled: aiEnabled.value,
            ai_mode: aiMode.value,
            ai_responder_user_id: selectedUserIds.value.length > 0
                ? (props.chat.ai_responder_user_id || selectedUserIds.value[0] || null)
                : null,
            company_id: props.chat.company_id || page.props.auth?.user?.company_id || null,
            ...payload,
        });
        router.reload({ only: ['chat', 'aiStatus'] });
    }

    async function toggleAi(): Promise<void> {
        if (!canManageAi.value || aiSaving.value) {
            return;
        }

        const enabling = !aiEnabled.value;
        closeAiModeMenu();
        closeAiResponderMenu();
        aiSaving.value = true;
        try {
            await patchAiSettings({ ai_enabled: enabling });
        } catch (e: any) {
            const data = e?.response?.data;
            if (enabling && data?.requires_confirmation) {
                const rawWarnings = Array.isArray(data.warnings) ? data.warnings : [];
                const warnings = rawWarnings.filter((w: unknown): w is string => typeof w === 'string' && w.trim() !== '');
                const readinessScore = typeof data.readiness?.score === 'number' ? data.readiness.score : null;
                const settingsUrl = typeof data.settings_url === 'string' ? data.settings_url : route('settings.ai-quality');
                const message =
                    typeof data.message === 'string' && data.message.trim() !== ''
                        ? data.message
                        : 'Перед включением AI проверьте готовность.';
                openAiRiskyEnableModal({
                    message,
                    warnings,
                    readinessScore,
                    settingsUrl,
                });
                return;
            }
            showToast({ message: data?.message || 'Не удалось переключить AI.', type: 'warning' });
        } finally {
            aiSaving.value = false;
        }
    }

    const quickTaskLoading = ref(false);
    const aiSimulatorOpen = ref(false);

    const aiRiskyEnableModalOpen = ref(false);
    const aiRiskyEnableModal = ref<AiRiskyEnableModalState | null>(null);
    const aiRiskyEnableConfirming = ref(false);

    function openAiRiskyEnableModal(state: AiRiskyEnableModalState): void {
        aiRiskyEnableModal.value = state;
        aiRiskyEnableModalOpen.value = true;
    }

    function closeAiRiskyEnableModal(): void {
        if (aiRiskyEnableConfirming.value) {
            return;
        }
        aiRiskyEnableModalOpen.value = false;
        aiRiskyEnableModal.value = null;
    }

    async function confirmAiRiskyEnable(): Promise<void> {
        if (!aiRiskyEnableModal.value) {
            return;
        }
        aiRiskyEnableConfirming.value = true;
        try {
            await patchAiSettings({ ai_enabled: true, confirm_risky_enable: true });
            aiRiskyEnableModalOpen.value = false;
            aiRiskyEnableModal.value = null;
        } catch (retryError: any) {
            showToast({ message: retryError?.response?.data?.message || 'Не удалось включить AI.', type: 'warning' });
        } finally {
            aiRiskyEnableConfirming.value = false;
        }
    }

    async function createQuickTask(): Promise<void> {
        if (quickTaskLoading.value) {
            return;
        }
        quickTaskLoading.value = true;
        try {
            await axios.post(route('chats.quick-task', props.chat.id), {
                title: 'Проверить следующий шаг по клиенту',
                body: 'Создано из шапки чата. Проверьте переписку, статус воронки и следующий шаг.',
            });
            router.reload({ only: ['sidebarInsights', 'chat'] });
        } catch (e: any) {
            showToast({ message: e?.response?.data?.message || 'Не удалось создать задачу.', type: 'warning' });
        } finally {
            quickTaskLoading.value = false;
        }
    }

    async function updateAiSettings(payload: Record<string, unknown>): Promise<void> {
        if (!canManageAi.value || aiSaving.value) {
            return;
        }

        aiSaving.value = true;
        try {
            await patchAiSettings(payload);
        } catch (e: any) {
            showToast({ message: e?.response?.data?.message || 'Не удалось обновить настройки AI.', type: 'warning' });
        } finally {
            aiSaving.value = false;
        }
    }

    function closeAiModeMenu(): void {
        aiModeMenuOpen.value = false;
    }

    function closeAiResponderMenu(): void {
        aiResponderMenuOpen.value = false;
        aiResponderSearchQuery.value = '';
    }

    function toggleAiModeMenu(): void {
        if (aiModeMenuOpen.value) {
            closeAiModeMenu();
            return;
        }
        closeMenu();
        closeDepartmentsMenu();
        closeUsersMenu();
        closeAiResponderMenu();
        aiModeMenuPos.value = computeMenuPosition(aiModeBtnRef.value);
        aiModeMenuOpen.value = true;
    }

    async function pickAiMode(mode: 'auto' | 'draft'): Promise<void> {
        if (aiMode.value === mode) {
            closeAiModeMenu();
            closeAiSettingsMenu();
            return;
        }
        await updateAiSettings({ ai_mode: mode });
        closeAiModeMenu();
        closeAiSettingsMenu();
    }

    function toggleAiResponderMenu(): void {
        if (aiResponderMenuOpen.value) {
            closeAiResponderMenu();
            return;
        }
        closeMenu();
        closeDepartmentsMenu();
        closeUsersMenu();
        closeAiModeMenu();
        aiResponderMenuPos.value = computeMenuPosition(aiResponderBtnRef.value);
        aiResponderMenuOpen.value = true;
    }

    async function pickAiResponder(userId: number | null): Promise<void> {
        const current = props.chat.ai_responder_user_id ?? null;
        if (current === userId) {
            closeAiResponderMenu();
            closeAiSettingsMenu();
            return;
        }
        await updateAiSettings({ ai_responder_user_id: userId });
        closeAiResponderMenu();
        closeAiSettingsMenu();
    }

    function closeAiSettingsMenu(): void {
        aiSettingsMenuOpen.value = false;
    }

    function toggleAiSettingsMenu(): void {
        if (aiSettingsMenuOpen.value) {
            closeAiSettingsMenu();
            return;
        }
        closeMenu();
        closeDepartmentsMenu();
        closeUsersMenu();
        closeAiModeMenu();
        closeAiResponderMenu();
        aiSettingsMenuPos.value = computeMenuPosition(aiSettingsBtnRef.value, 4);
        aiSettingsMenuOpen.value = true;
    }

    /** Подпись роли в списке назначения: у руководителя — отдел в скобках. */
    function assignableUserRoleLine(u: AssignableUser): string {
        const base = roleLabel(u.roles);
        if (!base) return '';
        if (u.roles.includes('manager')) {
            const dept = (u.department_name || '').trim();
            if (dept) return `${base} (${dept})`;
        }
        return base;
    }

    const userSearchQuery = ref('');

    watch(usersMenuOpen, (open) => {
        if (!open) {
            userSearchQuery.value = '';
        }
    });

    const filteredAssignableUsers = computed(() => {
        const list = assignableUsersList.value;
        const q = userSearchQuery.value.trim().toLowerCase();
        if (!q) {
            return list;
        }
        return list.filter((u) => {
            const name = (u.name || '').toLowerCase();
            const email = (u.email || '').toLowerCase();
            const role = assignableUserRoleLine(u).toLowerCase();
            const dept = (u.department_name || '').toLowerCase();
            return name.includes(q) || email.includes(q) || role.includes(q) || dept.includes(q);
        });
    });

    const filteredAiResponderPicker = computed(() => {
        const list = aiResponderPickerSource.value;
        const q = aiResponderSearchQuery.value.trim().toLowerCase();
        if (!q) {
            return list;
        }

        return list.filter((u) => {
            const name = (u.name || '').toLowerCase();
            const email = (u.email || '').toLowerCase();
            const role = assignableUserRoleLine(u).toLowerCase();
            const dept = (u.department_name || '').toLowerCase();

            return name.includes(q) || email.includes(q) || role.includes(q) || dept.includes(q);
        });
    });

    watch(aiResponderMenuOpen, (open) => {
        if (!open) {
            aiResponderSearchQuery.value = '';
        }
    });

    function toggleUsersMenu() {
        if (usersMenuOpen.value) {
            usersMenuOpen.value = false;
            return;
        }
        usersMenuPos.value = computeMenuPosition(usersBtnRef.value);
        usersMenuOpen.value = true;
    }

    async function openAssignmentModal() {
        if (assignUsersDisabled.value) {
            return;
        }
        usersMenuOpen.value = false;
        closeAiModeMenu();
        closeAiResponderMenu();
        assignmentModalOpen.value = true;
        userSearchQuery.value = '';
        await loadAssignmentHistory();
    }

    async function loadAssignmentHistory() {
        assignmentHistoryLoading.value = true;
        assignmentHistoryError.value = null;
        try {
            const { data } = await axios.get(route('chats.assign.history', props.chat.id));
            assignmentHistory.value = data.history ?? [];
            currentAssignmentsHistory.value = data.current ?? [];
        } catch (e: any) {
            assignmentHistoryError.value = e?.response?.data?.message || e?.message || 'Не удалось загрузить историю';
        } finally {
            assignmentHistoryLoading.value = false;
        }
    }

    function closeAssignmentModal() {
        assignmentModalOpen.value = false;
        userSearchQuery.value = '';
    }

    async function openAssignmentHistoryModal() {
        assignmentHistoryModalOpen.value = true;
        if (assignmentHistory.value.length === 0 && currentAssignmentsHistory.value.length === 0) {
            await loadAssignmentHistory();
        }
    }

    function closeAssignmentHistoryModal() {
        assignmentHistoryModalOpen.value = false;
    }

    function formatAssignmentTime(value: string | null): string {
        if (!value) return '—';
        const d = new Date(value);
        if (Number.isNaN(d.getTime())) return '—';
        return d.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
    }

    function onAssignUsersButtonClick() {
        void openAssignmentModal();
    }

    function closeUsersMenu() {
        usersMenuOpen.value = false;
    }

    function toggleUser(id: number) {
        const idx = selectedUserIds.value.indexOf(id);
        if (idx === -1) {
            selectedUserIds.value = [...selectedUserIds.value, id];
        } else {
            selectedUserIds.value = selectedUserIds.value.filter((v) => v !== id);
        }
        scheduleSaveUsers();
    }

    function scheduleSaveUsers() {
        if (saveUsersTimer !== null) {
            window.clearTimeout(saveUsersTimer);
        }
        saveUsersTimer = window.setTimeout(() => {
            saveUsersTimer = null;
            void saveUsers(false);
        }, 250);
    }

    async function saveUsers(closeAfterSave = true) {
        if (savingUsers.value) {
            saveUsersQueued = true;
            return;
        }
        saveUsersQueued = false;
        savingUsers.value = true;
        try {
            await axios.post(route('chats.assign.sync', props.chat.id), {
                user_ids: selectedUserIds.value,
            });
            if (assignmentModalOpen.value) {
                await loadAssignmentHistory();
            }
            router.reload({ only: ['chat', 'unreadChatsCount'] });
            if (closeAfterSave) {
                closeUsersMenu();
            }
        } finally {
            savingUsers.value = false;
            if (saveUsersQueued) {
                saveUsersQueued = false;
                scheduleSaveUsers();
            }
        }
    }

    function closeMenu() {
        menuOpen.value = false;
    }

    function toggleMenu() {
        if (menuOpen.value) {
            menuOpen.value = false;
            return;
        }
        closeDepartmentsMenu();
        closeUsersMenu();
        closeAiModeMenu();
        closeAiResponderMenu();
        closeAiSettingsMenu();
        menuPos.value = computeMenuPosition(menuBtnRef.value, 4);
        menuOpen.value = true;
    }

    function onEscape(e: KeyboardEvent) {
        if (e.key === 'Escape') {
            if (aiRiskyEnableModalOpen.value) {
                closeAiRiskyEnableModal();
                return;
            }
            if (assignmentHistoryModalOpen.value) {
                closeAssignmentHistoryModal();
                return;
            }
            if (departmentHistoryModalOpen.value) {
                closeDepartmentHistoryModal();
                return;
            }
            closeMenu();
            closeDepartmentsMenu();
            closeDepartmentModal();
            closeUsersMenu();
            closeAiModeMenu();
            closeAiResponderMenu();
            closeAiSettingsMenu();
            closeAssignmentModal();
            scheduledMessagesOpen.value = false;
        }
    }

    // Пересчитываем позицию открытых меню при ресайзе; при скролле закрываем только если
    // скролл не изнутри самой выпадашки (иначе прокрутка списка сотрудников закрывала окно).
    function onViewportChange() {
        if (departmentsMenuOpen.value) {
            departmentsMenuPos.value = computeMenuPosition(departmentsBtnRef.value);
        }
        if (usersMenuOpen.value) {
            usersMenuPos.value = computeMenuPosition(usersBtnRef.value);
        }
        if (aiModeMenuOpen.value) {
            aiModeMenuPos.value = computeMenuPosition(aiModeBtnRef.value);
        }
        if (aiResponderMenuOpen.value) {
            aiResponderMenuPos.value = computeMenuPosition(aiResponderBtnRef.value);
        }
        if (aiSettingsMenuOpen.value) {
            aiSettingsMenuPos.value = computeMenuPosition(aiSettingsBtnRef.value, 4);
        }
        if (menuOpen.value) {
            menuPos.value = computeMenuPosition(menuBtnRef.value, 4);
        }
    }
    function scrollTargetInsideOpenHeaderMenu(target: EventTarget | null): boolean {
        if (!(target instanceof Node)) {
            return false;
        }
        const roots = [
            departmentsMenuPanelRef.value,
            usersMenuPanelRef.value,
            overflowMenuPanelRef.value,
            aiModeMenuPanelRef.value,
            aiResponderMenuPanelRef.value,
            aiSettingsMenuPanelRef.value,
        ];
        return roots.some((root) => root != null && root.contains(target));
    }

    /** Закрываем при скролле страницы/родителя, но не при прокрутке внутри открытой выпадашки. */
    function onViewportScroll(e: Event) {
        if (scrollTargetInsideOpenHeaderMenu(e.target)) {
            return;
        }
        closeMenu();
        closeDepartmentsMenu();
        closeUsersMenu();
        closeAiModeMenu();
        closeAiResponderMenu();
        closeAiSettingsMenu();
    }

    window.addEventListener('keydown', onEscape);
    window.addEventListener('resize', onViewportChange);
    window.addEventListener('scroll', onViewportScroll, true);
    onBeforeUnmount(() => {
        window.removeEventListener('keydown', onEscape);
        window.removeEventListener('resize', onViewportChange);
        window.removeEventListener('scroll', onViewportScroll, true);
        if (saveDepartmentsTimer !== null) {
            window.clearTimeout(saveDepartmentsTimer);
            saveDepartmentsTimer = null;
        }
        if (saveUsersTimer !== null) {
            window.clearTimeout(saveUsersTimer);
            saveUsersTimer = null;
        }
    });

    function openSearch() {
        closeMenu();
        closeAiModeMenu();
        closeAiResponderMenu();
        emit('toggle-search');
    }

    function openContactInfo() {
        closeMenu();
        closeAiModeMenu();
        closeAiResponderMenu();
        emit('show-contact-info');
    }

    function closeChatWindow() {
        closeMenu();
        closeAiModeMenu();
        closeAiResponderMenu();
        router.visit(route('chats.index'));
    }

    /** Закрыть просмотр чата: в архив (если ещё не там) и переход к списку архива. */
    async function archiveAndCloseChat(): Promise<void> {
        closeMenu();
        closeAiModeMenu();
        closeAiResponderMenu();
        if (archivingChat.value) return;
        archivingChat.value = true;
        try {
            if (!props.chat.is_archived) {
                await axios.post(route('chats.archive', props.chat.id));
            }
            await router.visit(route('chats.archived'));
        } catch {
            showToast({ message: 'Не удалось отправить чат в архив.', type: 'warning' });
        } finally {
            archivingChat.value = false;
        }
    }

    function notImplemented(name: string) {
        closeMenu();
        showToast({ message: `«${name}» — функция скоро будет доступна.`, type: 'info' });
    }

    const displayName = computed(
        () =>
            props.chat.chat_name ||
            props.chat.contact?.name ||
            (props.chat.contact?.push_name ? `~ ${props.chat.contact.push_name}` : '') ||
            formatPhone(props.chat.contact?.phone_number) ||
            '',
    );

    function getTypingText(): string {
        const names = [...props.typingUsers.values()];
        if (names.length === 0) return '';
        if (names.length === 1) return `${names[0]} печатает...`;
        return `${names.join(', ')} печатают...`;
    }

    /**
     * Подпись «через какой подключённый номер ведётся диалог».
     * Берём номер WhatsApp-сессии чата + дружелюбное имя (display_name → wa_name).
     * Возвращаем null, когда сессия отвязана (FK SET NULL): тогда строку не рисуем.
     */
    const sessionLine = computed<{ phone: string; name: string } | null>(() => {
        const session = props.chat.whatsapp_session;
        if (!session) return null;
        const digits = formatPhone(session.phone_number);
        const phone = digits ? `+${digits}` : '';
        const name = (session.display_name ?? '').trim() || (session.wa_name ?? '').trim();
        if (!phone && !name) return null;
        return { phone, name };
    });

    const funnelCatalogList = computed(() => props.funnelCatalog ?? []);

    const funnelModuleVisible = computed(
        () => Boolean(page.props.modules?.funnels) && !props.chat.is_group,
    );

    const funnelBarColor = computed(() => {
        return props.chat.funnel_stage?.color || props.chat.funnel?.color || '#01b964';
    });

    const funnelBarStages = computed(() => {
        const funnelId = props.chat.funnel?.id;
        if (funnelId == null) {
            return [];
        }
        const entry = funnelCatalogList.value.find((x) => x.id === funnelId);
        if (!entry?.stages?.length) {
            return [];
        }
        return [...entry.stages].sort((a, b) => a.position - b.position);
    });

    const funnelBarCurrentIndex = computed(() => {
        const progress = props.chat.funnel_progress;
        if (progress?.stage_index != null && progress.stage_index >= 0) {
            return progress.stage_index;
        }
        const stageId = props.chat.funnel_stage?.id;
        if (stageId == null) {
            return -1;
        }
        const idx = funnelBarStages.value.findIndex((s) => s.id === stageId);
        return idx >= 0 ? idx : -1;
    });

    /** One cell per funnel stage for the header progress strip. */
    const funnelBarCells = computed(() => {
        if (funnelBarStages.value.length > 0) {
            return funnelBarStages.value.map((stage, index) => ({
                id: stage.id,
                name: stage.name,
                color: stage.color || funnelBarColor.value,
                stage_type: stage.stage_type,
                index,
            }));
        }

        const count = props.chat.funnel_progress?.stages_count ?? 0;
        if (count <= 0) {
            return [];
        }

        return Array.from({ length: count }, (_, index) => ({
            id: index,
            name: `Этап ${index + 1}`,
            color: funnelBarColor.value,
            index,
        }));
    });

    function funnelBarCellStyle(cellIndex: number, color: string): Record<string, string> {
        const current = funnelBarCurrentIndex.value;
        if (current < 0) {
            return { backgroundColor: 'var(--wa-chroma-neutral-bg-28)' };
        }
        if (cellIndex <= current) {
            return { backgroundColor: color };
        }
        return { backgroundColor: 'var(--wa-chroma-neutral-bg-22)' };
    }

    const funnelBarTitle = computed(() => {
        if (!funnelModuleVisible.value) return '';
        const fn = props.chat.funnel?.name;
        const st = props.chat.funnel_stage?.name;
        const reason = props.chat.funnel_ai_last_reason;
        const total = funnelBarCells.value.length;
        const pos = funnelBarCurrentIndex.value;
        const stepLabel =
            total > 0 && pos >= 0 ? `Этап ${pos + 1} из ${total}` : total > 0 ? `${total} этапов` : '';
        if (fn && st) {
            const base = stepLabel ? `${fn} — ${st} (${stepLabel})` : `${fn} — ${st}`;
            return reason ? `${base}. ${reason}` : base;
        }
        return stepLabel
            ? `Воронка продаж: ${stepLabel}. Нажмите, чтобы изменить`
            : 'Воронка продаж: нажмите, чтобы выбрать этап';
    });

    const funnelProgressPercent = computed(() => {
        const total = funnelBarCells.value.length;
        const current = funnelBarCurrentIndex.value;
        if (total <= 0 || current < 0) {
            return 0;
        }

        return Math.round(((current + 1) / total) * 100);
    });

    const funnelSnapshotTitle = computed(() => props.chat.funnel_stage?.name || 'Воронка не выбрана');

    const nextFunnelStageName = computed(() => {
        const next = funnelBarStages.value[funnelBarCurrentIndex.value + 1];

        return next?.name ?? null;
    });

    /** Одна строка под именем контакта вместо широкой карточки в тулбаре. */
    const funnelCompactLine = computed(() => {
        const parts: string[] = [];
        if (funnelModuleVisible.value && props.chat.funnel_stage?.name) {
            parts.push(props.chat.funnel_stage.name);
            if (funnelProgressPercent.value > 0) {
                parts.push(`${funnelProgressPercent.value}%`);
            }
        }
        if (aiEnabled.value || orchestratorStatusLabel.value) {
            parts.push(aiHeaderBadge.value.label);
        }
        if (nextFunnelStageName.value && funnelModuleVisible.value) {
            parts.push(`→ ${nextFunnelStageName.value}`);
        }

        return parts.length > 0 ? parts.join(' · ') : null;
    });

    const funnelCompactTitle = computed(() => funnelBarTitle.value || aiStatusTitle.value);

    const aiSnapshotTone = computed(() => {
        if (props.chat.ai_orchestrator_status === 'failed' || props.aiStatus?.status === 'failed') {
            return 'error';
        }
        if (props.chat.ai_orchestrator_status === 'needs_manager' || props.aiStatus?.status === 'blocked') {
            return 'warning';
        }
        if (props.chat.ai_orchestrator_status === 'running' || props.aiStatus?.status === 'generating' || props.aiStatus?.status === 'pending') {
            return 'busy';
        }
        if (aiEnabled.value) {
            return 'ready';
        }

        return 'idle';
    });

    const aiSnapshotLabel = computed(() => {
        if (orchestratorStatusLabel.value) {
            return orchestratorStatusLabel.value;
        }
        if (props.aiStatus?.label) {
            return props.aiStatus.label;
        }

        return aiEnabled.value ? aiModeLabel.value : 'AI выключен';
    });

    const funnelModalOpen = ref(false);
    const funnelSaving = ref(false);
    const funnelModalFunnelId = ref<number | null>(null);
    const funnelModalStageId = ref<number | null>(null);
    const funnelModalTracking = ref(true);
    const funnelModalLocked = ref(false);
    const funnelHistoryLoading = ref(false);
    const funnelHistoryError = ref<string | null>(null);
    const funnelHistory = ref<
        Array<{
            id: number;
            source: string;
            reason: string | null;
            confidence: number | null;
            created_at: string | null;
            to_funnel_id: number | null;
            to_stage_id: number | null;
        }>
    >([]);

    function openFunnelModal() {
        if (!funnelModuleVisible.value) return;
        funnelModalFunnelId.value = props.chat.funnel?.id ?? funnelCatalogList.value[0]?.id ?? null;
        funnelModalStageId.value = props.chat.funnel_stage?.id ?? null;
        funnelModalTracking.value = props.chat.funnel_tracking_enabled !== false;
        funnelModalLocked.value = Boolean(props.chat.funnel_stage_locked);
        funnelHistoryError.value = null;
        funnelHistory.value = [];
        funnelModalOpen.value = true;
    }

    function closeFunnelModal() {
        funnelModalOpen.value = false;
    }

    const modalStages = computed(() => {
        const fid = funnelModalFunnelId.value;
        if (fid == null) return [];
        const f = funnelCatalogList.value.find((x) => x.id === fid);
        return f?.stages ?? [];
    });

    const modalStagesOrdered = computed(() =>
        [...modalStages.value].sort((a, b) => a.position - b.position),
    );

    const modalStageIndex = computed(() => {
        const id = funnelModalStageId.value;
        if (id == null) {
            return -1;
        }
        return modalStagesOrdered.value.findIndex((s) => s.id === id);
    });

    const modalFunnelColor = computed(() => {
        const fid = funnelModalFunnelId.value;
        const entry = funnelCatalogList.value.find((x) => x.id === fid);
        return entry?.color || '#01b964';
    });

    function modalFunnelSegmentStyle(cellIndex: number, color: string): Record<string, string> {
        const current = modalStageIndex.value;
        if (current < 0) {
            return { backgroundColor: 'var(--wa-chroma-neutral-bg-28)' };
        }
        if (cellIndex <= current) {
            return { backgroundColor: color || modalFunnelColor.value };
        }
        return { backgroundColor: 'var(--wa-chroma-neutral-bg-22)' };
    }

    const funnelWheelRef = ref<InstanceType<typeof FunnelStageWheelPicker> | null>(null);

    watch(funnelModalFunnelId, (newFid, oldFid) => {
        if (newFid == null) {
            funnelModalStageId.value = null;
            return;
        }

        const newFunnel = funnelCatalogList.value.find((x) => x.id === newFid);
        if (!newFunnel?.stages?.length) {
            funnelModalStageId.value = null;
            return;
        }

        if (newFunnel.stages.some((s) => s.id === funnelModalStageId.value)) {
            return;
        }

        const oldFunnel = oldFid != null ? funnelCatalogList.value.find((x) => x.id === oldFid) : undefined;
        const preserved = stageIdAtPreservedIndex(oldFunnel, funnelModalStageId.value, newFunnel);
        funnelModalStageId.value = preserved ?? newFunnel.stages[0]!.id;
    });

    async function loadFunnelHistory() {
        funnelHistoryLoading.value = true;
        funnelHistoryError.value = null;
        try {
            const { data } = await axios.get(route('chats.funnel.history', props.chat.id));
            funnelHistory.value = Array.isArray(data.data) ? data.data : [];
        } catch (e: any) {
            funnelHistoryError.value = e?.response?.data?.message || 'Не удалось загрузить историю';
        } finally {
            funnelHistoryLoading.value = false;
        }
    }

    watch(funnelModalOpen, (open) => {
        if (open) {
            void loadFunnelHistory();
            void nextTick(() => funnelWheelRef.value?.refresh());
        }
    });

    function onFunnelSelect(e: Event) {
        const v = (e.target as HTMLSelectElement).value;
        funnelModalFunnelId.value = v === '' ? null : Number(v);
    }

    async function saveFunnelModal() {
        if (funnelSaving.value) return;
        funnelSaving.value = true;
        try {
            const payload: Record<string, unknown> = {
                funnel_tracking_enabled: funnelModalTracking.value,
                funnel_stage_locked: funnelModalLocked.value,
            };
            if (funnelModalFunnelId.value != null && funnelModalStageId.value != null) {
                payload.funnel_id = funnelModalFunnelId.value;
                payload.funnel_stage_id = funnelModalStageId.value;
            } else {
                payload.funnel_id = null;
                payload.funnel_stage_id = null;
            }
            await axios.patch(route('chats.funnel.update', props.chat.id), payload);
            closeFunnelModal();
            await router.reload({ only: ['chat', 'funnelCatalog'] });
        } catch (e: any) {
            const msg = e?.response?.data?.message || e?.response?.data?.errors?.funnel_id?.[0] || 'Не удалось сохранить';
            showToast({ message: typeof msg === 'string' ? msg : 'Ошибка сохранения', type: 'warning' });
        } finally {
            funnelSaving.value = false;
        }
    }
    return {
        page,
        props,
        chat,
        typingUsers,
        emit,
        canEditChatDepartments,
        employeeOwnDepartmentLabel,
        menuOpen,
        menuBtnRef,
        menuPos,
        departmentsList,
        departmentsMenuOpen,
        departmentsBtnRef,
        departmentsMenuPos,
        selectedDepartmentIds,
        savingDepartments,
        departmentModalOpen,
        departmentHistoryModalOpen,
        departmentHistoryLoading,
        departmentHistoryError,
        departmentHistory,
        currentDepartmentsHistory,
        departmentSearchQuery,
        scheduledMessagesOpen,
        archivingChat,
        selectedDepartments,
        filteredDepartments,
        assignableUsersList,
        isAdministrator,
        isManager,
        showAiResponderSelect,
        canManageAi,
        aiEnabled,
        aiMode,
        aiSaving,
        aiModeMenuOpen,
        aiModeBtnRef,
        aiModeMenuPos,
        aiResponderMenuOpen,
        aiResponderBtnRef,
        aiResponderMenuPos,
        aiResponderSearchQuery,
        aiSettingsMenuOpen,
        aiSettingsBtnRef,
        aiSettingsMenuPos,
        aiSettingsMenuPanelRef,
        aiResponderName,
        aiStatusLabel,
        aiModeLabel,
        aiSettingsSummary,
        aiHeaderBadge,
        aiAssistantButtonTitle,
        aiAssistantAriaLabel,
        aiAssistantNeedsAttention,
        aiModePickerLabel,
        aiResponderMenuButtonLabel,
        aiStatusTitle,
        orchestratorStatusLabel,
        orchestratorStatusTitle,
        showAssignUsersBlock,
        assignUsersDisabled,
        assignUsersButtonTitle,
        usersMenuOpen,
        usersBtnRef,
        usersMenuPos,
        departmentsMenuPanelRef,
        usersMenuPanelRef,
        overflowMenuPanelRef,
        aiModeMenuPanelRef,
        aiResponderMenuPanelRef,
        selectedUserIds,
        savingUsers,
        assignmentModalOpen,
        assignmentHistoryModalOpen,
        assignmentHistoryLoading,
        assignmentHistoryError,
        assignmentHistory,
        currentAssignmentsHistory,
        selectedUsers,
        aiResponderPickerSource,
        usersLabel,
        quickTaskLoading,
        aiSimulatorOpen,
        aiRiskyEnableModalOpen,
        aiRiskyEnableModal,
        aiRiskyEnableConfirming,
        userSearchQuery,
        filteredAssignableUsers,
        filteredAiResponderPicker,
        displayName,
        sessionLine,
        funnelCatalogList,
        funnelModuleVisible,
        funnelBarColor,
        funnelBarStages,
        funnelBarCurrentIndex,
        funnelBarCells,
        funnelBarTitle,
        funnelProgressPercent,
        funnelSnapshotTitle,
        nextFunnelStageName,
        funnelCompactLine,
        funnelCompactTitle,
        aiSnapshotTone,
        aiSnapshotLabel,
        funnelModalOpen,
        funnelSaving,
        funnelModalFunnelId,
        funnelModalStageId,
        funnelModalTracking,
        funnelModalLocked,
        funnelHistoryLoading,
        funnelHistoryError,
        funnelHistory,
        modalStages,
        modalStagesOrdered,
        modalStageIndex,
        modalFunnelColor,
        funnelWheelRef,
        syncSelectedFromChat,
        toggleDepartmentsMenu,
        closeDepartmentModal,
        closeDepartmentHistoryModal,
        closeDepartmentsMenu,
        toggleDepartment,
        scheduleSaveDepartments,
        syncSelectedUsersFromChat,
        roleLabel,
        userInitials,
        openAiRiskyEnableModal,
        closeAiRiskyEnableModal,
        closeAiModeMenu,
        closeAiResponderMenu,
        toggleAiModeMenu,
        toggleAiResponderMenu,
        closeAiSettingsMenu,
        toggleAiSettingsMenu,
        assignableUserRoleLine,
        toggleUsersMenu,
        closeAssignmentModal,
        closeAssignmentHistoryModal,
        formatAssignmentTime,
        onAssignUsersButtonClick,
        closeUsersMenu,
        toggleUser,
        scheduleSaveUsers,
        closeMenu,
        toggleMenu,
        onEscape,
        onViewportChange,
        scrollTargetInsideOpenHeaderMenu,
        onViewportScroll,
        openSearch,
        openContactInfo,
        closeChatWindow,
        notImplemented,
        getTypingText,
        funnelBarCellStyle,
        openFunnelModal,
        closeFunnelModal,
        modalFunnelSegmentStyle,
        onFunnelSelect,
        openDepartmentModal,
        loadDepartmentHistory,
        openDepartmentHistoryModal,
        saveDepartments,
        patchAiSettings,
        toggleAi,
        confirmAiRiskyEnable,
        createQuickTask,
        updateAiSettings,
        pickAiMode,
        pickAiResponder,
        openAssignmentModal,
        loadAssignmentHistory,
        openAssignmentHistoryModal,
        saveUsers,
        archiveAndCloseChat,
        loadFunnelHistory,
        saveFunnelModal,
    };
}
