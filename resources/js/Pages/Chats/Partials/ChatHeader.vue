<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ref, onBeforeUnmount, computed, watch, nextTick, provide, reactive } from 'vue';
import axios from 'axios';
import Avatar from '@/Components/Avatar.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import FunnelStageIcon from '@/Components/Funnel/FunnelStageIcon.vue';
import FunnelStageWheelPicker from '@/Components/FunnelStageWheelPicker.vue';
import ChatHeaderDialogs from './ChatHeaderDialogs.vue';
import { CHAT_HEADER_DIALOGS_KEY } from './chatHeaderDialogsKey';
import type { AssignableUser, Chat, Department, FunnelCatalogEntry } from '@/types';
import { formatPhone } from '@/utils/phone';
import { initialsFromName } from '@/utils/initials';
import { stageIdAtPreservedIndex } from '@/utils/funnelStageMapping';
import ScheduledMessagesModal from './ScheduledMessagesModal.vue';
import AiSimulatorModal from './AiSimulatorModal.vue';
import { useToastStore } from '@/stores/toast';

const { show: showToast } = useToastStore();

type MenuPos = { top: number; right: number };
type AiRiskyEnableModalState = {
    message: string;
    warnings: string[];
    readinessScore: number | null;
    settingsUrl: string;
};

type AiStatus = {
    id: number;
    mode: string;
    status: string;
    label: string;
    message: string;
    hint: string | null;
    knowledge_context: {
        rules: number;
        products: number;
        services: number;
    } | null;
    tone_source: {
        source: string;
        label: string;
        hint: string;
    } | null;
    draft_reply: string | null;
    technical_error: string | null;
    updated_at: string | null;
};

/**
 * Считаем координаты выпадашки относительно viewport, чтобы Teleport в body
 * выводил её поверх всего интерфейса независимо от overflow:hidden у предков.
 */
function computeMenuPosition(btn: HTMLElement | null, gap = 8): MenuPos {
    if (!btn) return { top: 0, right: 0 };
    const rect = btn.getBoundingClientRect();
    return {
        top: rect.bottom + gap,
        right: Math.max(8, window.innerWidth - rect.right),
    };
}

const props = defineProps<{
    chat: Chat;
    typingUsers: Map<number, string>;
    departments?: Department[];
    assignableUsers?: AssignableUser[];
    aiStatus?: AiStatus | null;
    funnelCatalog?: FunnelCatalogEntry[];
}>();

const page = usePage<any>();

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

const emit = defineEmits<{
    (e: 'toggle-search'): void;
    (e: 'show-contact-info'): void;
    (e: 'open-ai'): void;
}>();

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

        <div
            role="button"
            tabindex="0"
            aria-label="Информация о контакте"
            @click="openContactInfo"
            @keydown.enter.prevent="openContactInfo"
            @keydown.space.prevent="openContactInfo"
            class="cursor-pointer shrink-0"
        >
            <Avatar
                :avatar-url="chat.contact?.profile_picture_url"
                :name="displayName"
                :is-group="chat.is_group"
                :size="40"
            />
        </div>

        <div
            role="button"
            tabindex="0"
            aria-label="Информация о контакте"
            @click="openContactInfo"
            @keydown.enter.prevent="openContactInfo"
            @keydown.space.prevent="openContactInfo"
            class="flex-1 min-w-0 cursor-pointer"
        >
            <h2 class="text-base text-[var(--wa-text)] truncate font-normal">
                {{ chat.chat_name || chat.contact?.push_name || formatPhone(chat.contact?.phone_number) || 'Без имени' }}
            </h2>
            <p class="text-xs text-[var(--wa-text-secondary)] truncate">
                <template v-if="typingUsers.size > 0">
                    <span class="text-[var(--wa-accent)]">{{ getTypingText() }}</span>
                </template>
                <template v-else>
                    в сети
                </template>
            </p>
            <p
                v-if="sessionLine"
                class="text-[11px] leading-tight text-[var(--wa-text-secondary)] truncate opacity-80"
                :title="`Чат ведётся через ваш номер ${sessionLine.phone}${sessionLine.name ? ` (${sessionLine.name})` : ''}`"
            >
                <span class="opacity-60">через</span>
                <span v-if="sessionLine.phone" class="ml-1 font-medium tabular-nums">{{ sessionLine.phone }}</span>
                <span v-if="sessionLine.name" class="ml-1">· {{ sessionLine.name }}</span>
            </p>
            <button
                v-if="funnelCompactLine"
                type="button"
                class="header-funnel-compact mt-0.5 w-full text-left"
                :class="`header-funnel-compact-${aiSnapshotTone}`"
                :title="funnelCompactTitle"
                @click.stop="funnelModuleVisible ? openFunnelModal() : emit('open-ai')"
            >
                <span
                    v-if="funnelModuleVisible && chat.funnel_stage"
                    class="header-funnel-compact-dot shrink-0"
                    :style="{ background: `${funnelBarColor}22`, color: funnelBarColor }"
                >
                    <FunnelStageIcon :type="chat.funnel_stage?.stage_type" :size="10" />
                </span>
                <span class="truncate">{{ funnelCompactLine }}</span>
                <span
                    v-if="funnelModuleVisible && funnelProgressPercent > 0"
                    class="header-funnel-compact-bar shrink-0"
                    aria-hidden="true"
                >
                    <span :style="{ width: `${funnelProgressPercent}%`, background: funnelBarColor }"></span>
                </span>
            </button>
        </div>


        <div class="chat-header-toolbar flex flex-nowrap items-center gap-1.5 min-w-0 shrink">
            <!-- Отделы: сотрудник только видит свой; админ/руководитель — выбор -->
            <div class="header-dept-control relative">
                <div
                    v-if="!canEditChatDepartments"
                    class="label-pill label-pill-dept label-pill-dept-static cursor-default opacity-95"
                    :class="{ 'label-pill-dept-active': (page.props.auth?.user?.department_id ?? null) !== null }"
                    title="Ваш отдел. Изменить отделы чата могут только руководитель или администратор."
                >
                    <span class="truncate">{{ employeeOwnDepartmentLabel }}</span>
                </div>

                <template v-else>
                    <button
                        ref="departmentsBtnRef"
                        type="button"
                        class="label-pill label-pill-dept label-pill-icon label-pill-icon-badge"
                        :class="{ 'label-pill-dept-active': selectedDepartmentIds.length > 0 }"
                        :title="selectedDepartmentIds.length ? `Отделы: ${selectedDepartments.map((d) => d.name).join(', ')}` : 'Прикрепить отделы к чату'"
                        :aria-label="selectedDepartmentIds.length ? `Отделы: ${selectedDepartments.map((d) => d.name).join(', ')}` : 'Прикрепить отделы к чату'"
                        @click="openDepartmentModal"
                    >
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
                        </svg>
                        <span
                            v-if="selectedDepartmentIds.length > 0"
                            class="label-pill-count-badge ui-tab-badge ui-tab-badge--team"
                            aria-hidden="true"
                        >{{ selectedDepartmentIds.length }}</span>
                    </button>

                    <Teleport to="body">
                        <div
                            v-if="departmentsMenuOpen"
                            @click="closeDepartmentsMenu"
                            class="fixed inset-0 z-[900]"
                        ></div>

                        <div
                            v-if="departmentsMenuOpen"
                            ref="departmentsMenuPanelRef"
                            class="fixed w-[min(92vw,360px)] max-h-[min(90vh,440px)] flex flex-col rounded-xl shadow-2xl z-[1000] border header-menu assign-popover overflow-hidden"
                            :style="{
                                top: `${departmentsMenuPos.top}px`,
                                right: `${departmentsMenuPos.right}px`,
                                background: 'var(--wa-panel-header)',
                                borderColor: 'var(--wa-control-border)',
                            }"
                            @click.stop
                        >
                            <div
                                v-if="selectedDepartments.length"
                                class="assign-selected"
                            >
                                <button
                                    v-for="d in selectedDepartments"
                                    :key="d.id"
                                    type="button"
                                    class="assign-chip assign-chip-dept"
                                    :title="d.name"
                                    @click="toggleDepartment(d.id)"
                                >
                                    <span class="truncate">{{ d.name }}</span>
                                    <svg class="w-3.5 h-3.5 shrink-0 opacity-70" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                                <span
                                    v-if="savingDepartments"
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
                                        v-model="departmentSearchQuery"
                                        type="search"
                                        autocomplete="off"
                                        placeholder="Поиск..."
                                        class="assign-search"
                                    />
                                </label>
                            </div>

                            <div class="assign-list wa-scrollbar flex-1 min-h-0 overflow-y-auto">
                                <button
                                    v-for="d in filteredDepartments"
                                    :key="d.id"
                                    type="button"
                                    class="assign-row"
                                    :class="{ 'assign-row-dept-active': selectedDepartmentIds.includes(d.id) }"
                                    @click="toggleDepartment(d.id)"
                                >
                                    <Avatar :name="d.name" :size="36" variant="group" fallback-initials class="shrink-0" />
                                    <span class="flex-1 truncate text-left assign-name">{{ d.name }}</span>
                                    <svg
                                        v-if="selectedDepartmentIds.includes(d.id)"
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
                                    v-if="filteredDepartments.length === 0"
                                    class="ui-empty-state ui-empty-state--dashed border-0 shadow-none rounded-none text-left"
                                >
                                    {{ departmentSearchQuery.trim() ? 'Ничего не найдено' : 'Нет доступных отделов. Создайте их в разделе «Настройки → Отделы».' }}
                                </div>
                            </div>
                        </div>
                    </Teleport>
                </template>
            </div>

            <div
                v-if="canManageAi"
                class="header-action-group header-ai-group header-ai-control"
                :class="{ 'header-ai-group-on': aiEnabled }"
            >
                <button
                    type="button"
                    class="header-ai-toggle"
                    :class="{ 'header-ai-toggle-on': aiEnabled }"
                    :title="aiEnabled ? 'AI сам отвечает на новые сообщения клиента. Нажмите, чтобы выключить.' : 'AI не отвечает автоматически. Нажмите, чтобы включить автоответы.'"
                    :aria-label="aiEnabled ? 'Выключить AI-автоответы' : 'Включить AI-автоответы'"
                    :disabled="aiSaving"
                    @click="toggleAi"
                >
                    <span class="ai-state-dot" :class="{ 'ai-state-dot-on': aiEnabled }"></span>
                    <span class="header-ai-toggle-text">{{ aiModeLabel }}</span>
                </button>

                <button
                    v-if="aiEnabled"
                    ref="aiSettingsBtnRef"
                    type="button"
                    class="ai-menu-trigger ai-settings-trigger"
                    :disabled="aiSaving"
                    :title="`Настройки AI: ${aiSettingsSummary}`"
                    aria-haspopup="dialog"
                    :aria-expanded="aiSettingsMenuOpen"
                    @click="toggleAiSettingsMenu"
                >
                    <svg class="w-4 h-4 shrink-0 opacity-80" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 010 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 010-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <span class="ai-menu-trigger-label hidden lg:inline truncate max-w-[7.5rem]">{{ aiSettingsSummary }}</span>
                </button>
            </div>

            <div class="header-ai-chat-control">
                <button
                    type="button"
                    class="header-ai-assistant-btn ui-status-badge"
                    :class="`ui-status-badge--${aiHeaderBadge.tone}`"
                    :title="aiAssistantButtonTitle"
                    :aria-label="aiAssistantAriaLabel"
                    @click="closeAiModeMenu(); closeAiResponderMenu(); closeAiSettingsMenu(); emit('open-ai')"
                >
                    <svg class="header-ai-assistant-btn__icon shrink-0" fill="none" stroke="currentColor" stroke-width="1.85" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3.5 3.5V16z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17.5 3.5l.75 1.5 1.5.75-1.5.75-.75 1.5-.75-1.5-1.5-.75 1.5-.75.75-1.5z" />
                    </svg>
                    <span class="header-ai-assistant-btn__label hidden xl:inline">AI-чат</span>
                    <span
                        v-if="aiAssistantNeedsAttention"
                        class="header-ai-assistant-btn__status-dot"
                        :class="`header-ai-assistant-btn__status-dot--${aiHeaderBadge.tone}`"
                        :title="aiHeaderBadge.label"
                        aria-hidden="true"
                    ></span>
                </button>
            </div>

            <div
                v-if="orchestratorStatusLabel && !funnelCompactLine"
                class="label-pill label-pill-orchestrator"
                :class="{
                    'label-pill-orchestrator-wait': chat.ai_orchestrator_status === 'needs_manager',
                    'label-pill-orchestrator-error': chat.ai_orchestrator_status === 'failed',
                }"
                :title="orchestratorStatusTitle"
            >
                <span class="ai-state-dot ai-state-dot-on"></span>
                <span class="truncate">{{ orchestratorStatusLabel }}</span>
            </div>

            <Teleport to="body">
                <template v-if="canManageAi && aiEnabled">
                    <div
                        v-if="aiSettingsMenuOpen"
                        class="fixed inset-0 z-[900]"
                        @click="closeAiSettingsMenu"
                    ></div>
                    <div
                        v-if="aiSettingsMenuOpen"
                        ref="aiSettingsMenuPanelRef"
                        class="fixed z-[1000] flex max-h-[min(88vh,480px)] w-[min(92vw,360px)] flex-col overflow-hidden rounded-xl border shadow-2xl header-menu assign-popover"
                        :style="{
                            top: `${aiSettingsMenuPos.top}px`,
                            right: `${aiSettingsMenuPos.right}px`,
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
                                :class="{ 'assign-row-staff-active': aiMode === 'auto' }"
                                role="option"
                                :aria-selected="aiMode === 'auto'"
                                @click="pickAiMode('auto')"
                            >
                                <span class="flex-1 min-w-0 text-left">
                                    <span class="block truncate assign-name">Автоответ</span>
                                    <span class="block truncate text-[11px] assign-role" :style="{ color: 'var(--wa-text-secondary)' }">
                                        Отправлять ответ клиенту автоматически
                                    </span>
                                </span>
                                <svg
                                    v-if="aiMode === 'auto'"
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
                                :class="{ 'assign-row-staff-active': aiMode === 'draft' }"
                                role="option"
                                :aria-selected="aiMode === 'draft'"
                                @click="pickAiMode('draft')"
                            >
                                <span class="flex-1 min-w-0 text-left">
                                    <span class="block truncate assign-name">Черновик</span>
                                    <span class="block truncate text-[11px] assign-role" :style="{ color: 'var(--wa-text-secondary)' }">
                                        Подставлять текст в поле ввода без отправки
                                    </span>
                                </span>
                                <svg
                                    v-if="aiMode === 'draft'"
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
                        <template v-if="showAiResponderSelect">
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
                                    v-model="aiResponderSearchQuery"
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
                                :class="{ 'assign-row-staff-active': chat.ai_responder_user_id == null }"
                                role="option"
                                :aria-selected="chat.ai_responder_user_id == null"
                                @click="pickAiResponder(null)"
                            >
                                <span class="assign-avatar assign-avatar-staff" aria-hidden="true">AI</span>
                                <span class="flex-1 min-w-0 text-left">
                                    <span class="block truncate assign-name">Автовыбор</span>
                                    <span class="block truncate text-[11px] assign-role" :style="{ color: 'var(--wa-text-secondary)' }">
                                        Система выберет ответчика из назначенных на чат
                                    </span>
                                </span>
                                <svg
                                    v-if="chat.ai_responder_user_id == null"
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
                                v-for="u in filteredAiResponderPicker"
                                :key="u.id"
                                type="button"
                                class="assign-row"
                                :class="{ 'assign-row-staff-active': chat.ai_responder_user_id === u.id }"
                                role="option"
                                :aria-selected="chat.ai_responder_user_id === u.id"
                                @click="pickAiResponder(u.id)"
                            >
                                <UserAvatar :name="u.name" :size="36" class="shrink-0" />
                                <span class="flex-1 min-w-0 text-left">
                                    <span class="block truncate assign-name">{{ u.name }}</span>
                                    <span
                                        v-if="assignableUserRoleLine(u)"
                                        class="block truncate text-[11px] assign-role"
                                        :style="{ color: 'var(--wa-text-secondary)' }"
                                    >
                                        {{ assignableUserRoleLine(u) }}
                                    </span>
                                </span>
                                <svg
                                    v-if="chat.ai_responder_user_id === u.id"
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
                                v-if="aiResponderSearchQuery.trim() && filteredAiResponderPicker.length === 0"
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
            <div v-if="showAssignUsersBlock" class="header-staff-control relative">
                <button
                    ref="usersBtnRef"
                    type="button"
                    class="label-pill label-pill-staff label-pill-staff-avatars"
                    :class="{
                        'label-pill-staff-active': selectedUserIds.length > 0,
                        'opacity-50 cursor-not-allowed': assignUsersDisabled,
                    }"
                    :disabled="assignUsersDisabled"
                    :title="assignUsersButtonTitle"
                    @click="onAssignUsersButtonClick"
                >
                    <div
                        v-if="selectedUsers.length"
                        class="header-staff-avatar-stack shrink-0"
                        aria-hidden="true"
                    >
                        <UserAvatar
                            v-for="u in selectedUsers.slice(0, 3)"
                            :key="u.id"
                            :name="u.name"
                            :size="22"
                            class="header-staff-avatar"
                            :title="u.name"
                        />
                        <div
                            v-if="selectedUserIds.length > 3"
                            class="staff-pill-avatar header-staff-avatar header-staff-more"
                        >
                            +{{ selectedUserIds.length - 3 }}
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
                        v-if="usersMenuOpen"
                        @click="closeUsersMenu"
                        class="fixed inset-0 z-[900]"
                    ></div>

                    <div
                        v-if="usersMenuOpen"
                        ref="usersMenuPanelRef"
                            class="fixed w-[min(92vw,360px)] max-h-[min(90vh,440px)] flex flex-col rounded-xl shadow-2xl z-[1000] border header-menu assign-popover overflow-hidden"
                        :style="{
                            top: `${usersMenuPos.top}px`,
                            right: `${usersMenuPos.right}px`,
                            background: 'var(--wa-panel-header)',
                            borderColor: 'var(--wa-control-border)',
                        }"
                        @click.stop
                    >
                        <div
                            v-if="selectedUsers.length"
                            class="assign-selected"
                        >
                            <button
                                v-for="u in selectedUsers"
                                :key="u.id"
                                type="button"
                                class="assign-chip assign-chip-staff"
                                :title="u.name"
                                @click="toggleUser(u.id)"
                            >
                                <span class="truncate">{{ u.name }}</span>
                                <svg class="w-4 h-4 shrink-0 opacity-70" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                            <span
                                v-if="savingUsers"
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
                                    v-model="userSearchQuery"
                                    type="search"
                                    autocomplete="off"
                                    placeholder="Поиск..."
                                    class="assign-search"
                                />
                            </label>
                        </div>

                        <div class="assign-list wa-scrollbar flex-1 min-h-0 overflow-y-auto">
                            <button
                                v-for="u in filteredAssignableUsers"
                                :key="u.id"
                                type="button"
                                class="assign-row"
                                :class="{ 'assign-row-staff-active': selectedUserIds.includes(u.id) }"
                                @click="toggleUser(u.id)"
                            >
                                <UserAvatar :name="u.name" :size="36" class="shrink-0" />
                                <span class="flex-1 min-w-0 text-left">
                                    <span class="block truncate assign-name">{{ u.name }}</span>
                                    <div
                                        v-if="assignableUserRoleLine(u)"
                                        class="truncate assign-role"
                                        :style="{ color: 'var(--wa-text-secondary)' }"
                                    >
                                        {{ assignableUserRoleLine(u) }}
                                    </div>
                                </span>
                                <svg
                                    v-if="selectedUserIds.includes(u.id)"
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
                                v-if="filteredAssignableUsers.length === 0"
                                class="ui-empty-state ui-empty-state--dashed border-0 shadow-none rounded-none text-left"
                            >
                                {{ userSearchQuery.trim() ? 'Ничего не найдено' : 'Нет пользователей для списка' }}
                            </div>
                        </div>
                    </div>
                </Teleport>
            </div>

            <div
                v-else-if="chat.assignments?.length"
                class="header-staff-control label-pill label-pill-staff label-pill-staff-static label-pill-staff-avatars"
                title="Ответственные за этот чат"
            >
                <div class="header-staff-avatar-stack shrink-0" aria-hidden="true">
                    <UserAvatar
                        v-for="a in chat.assignments.slice(0, 3)"
                        :key="a.id"
                        :name="a.user?.name"
                        :size="22"
                        class="header-staff-avatar"
                    />
                    <div
                        v-if="chat.assignments.length > 3"
                        class="staff-pill-avatar header-staff-avatar header-staff-more"
                    >
                        +{{ chat.assignments.length - 3 }}
                    </div>
                </div>
            </div>

            <div class="header-menu-control flex items-center gap-1 shrink-0">
                <button type="button" class="wa-header-btn shrink-0 hidden xl:flex" title="Поиск" @click="openSearch">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </button>

                <div class="relative shrink-0">
                    <button
                        ref="menuBtnRef"
                        class="wa-header-btn shrink-0"
                        title="Меню"
                        @click="toggleMenu"
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
                        v-if="menuOpen"
                        @click="closeMenu"
                        class="fixed inset-0 z-[900]"
                    ></div>

                    <div
                        v-if="menuOpen"
                        ref="overflowMenuPanelRef"
                        class="fixed min-w-[240px] rounded-lg shadow-xl py-2 z-[1000] border header-menu"
                        :style="{
                            top: `${menuPos.top}px`,
                            right: `${menuPos.right}px`,
                            background: 'var(--wa-panel-header)',
                            borderColor: 'var(--wa-control-border)',
                        }"
                    >
                    <button class="menu-item" @click="openContactInfo" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Данные контакта
                    </button>
                    <button class="menu-item" @click="openSearch" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        Поиск
                    </button>
                    <button class="menu-item" @click="scheduledMessagesOpen = true; closeMenu()" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l2.5 1.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Отложенные сообщения
                    </button>
                    <button
                        v-if="funnelModuleVisible"
                        class="menu-item"
                        type="button"
                        @click="openFunnelModal(); closeMenu()"
                    >
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5" />
                        </svg>
                        Этап воронки
                    </button>
                    <button
                        v-if="page.props.modules?.org_tasks"
                        class="menu-item"
                        type="button"
                        :disabled="quickTaskLoading"
                        @click="closeMenu(); createQuickTask()"
                    >
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        {{ quickTaskLoading ? 'Создание задачи…' : 'Задача по чату' }}
                    </button>
                    <button
                        v-if="canManageAi"
                        class="menu-item"
                        type="button"
                        @click="aiSimulatorOpen = true; closeMenu()"
                    >
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714a2.25 2.25 0 00.659 1.591L19 14.5M14.25 3.104c.251.023.501.05.75.082M19 14.5l-2.47 2.47a2.25 2.25 0 01-1.59.659H9.06a2.25 2.25 0 01-1.591-.659L5 14.5m14 0V17.25a2.25 2.25 0 01-2.25 2.25H7.25A2.25 2.25 0 015 17.25V14.5" />
                        </svg>
                        Симулятор AI
                    </button>
                    <button
                        class="menu-item menu-item-danger"
                        :disabled="archivingChat"
                        @click="archiveAndCloseChat"
                        type="button"
                    >
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        Закрыть чат и в архив
                    </button>
                    <button class="menu-item" @click="closeChatWindow" type="button">
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

        <ChatHeaderDialogs />

        <button
            v-if="funnelModuleVisible"
            type="button"
            class="absolute inset-x-0 bottom-0 z-[5] h-3 w-full border-0 p-0 m-0 bg-transparent cursor-pointer group"
            :title="funnelBarTitle"
            :aria-label="funnelBarTitle || 'Воронка продаж — открыть настройку'"
            :aria-valuenow="funnelBarCurrentIndex >= 0 ? funnelBarCurrentIndex + 1 : 0"
            :aria-valuemin="0"
            :aria-valuemax="funnelBarCells.length"
            role="progressbar"
            @click="openFunnelModal"
        >
            <span
                v-if="funnelBarCells.length"
                class="pointer-events-none absolute inset-x-0 bottom-0 flex h-[3px] gap-px px-px group-hover:opacity-95"
                aria-hidden="true"
            >
                <span
                    v-for="cell in funnelBarCells"
                    :key="cell.id"
                    class="min-w-0 flex-1 rounded-[1px] transition-colors duration-500 ease-out"
                    :class="{
                        'ring-1 ring-inset ring-white/35 dark:ring-black/25':
                            cell.index === funnelBarCurrentIndex && funnelBarCurrentIndex >= 0,
                    }"
                    :style="funnelBarCellStyle(cell.index, cell.color)"
                    :title="cell.name"
                />
            </span>
            <span
                v-else
                class="pointer-events-none absolute inset-x-0 bottom-0 h-[3px] rounded-sm bg-black/10 dark:bg-white/10"
                aria-hidden="true"
            />
        </button>

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
<style scoped src="./chat-header-assign.css"></style>
