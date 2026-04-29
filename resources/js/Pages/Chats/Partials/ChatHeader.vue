<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ref, onBeforeUnmount, computed, watch } from 'vue';
import axios from 'axios';
import Avatar from '@/Components/Avatar.vue';
import type { AssignableUser, Chat, Department } from '@/types';
import { formatPhone } from '@/utils/phone';
import ScheduledMessagesModal from './ScheduledMessagesModal.vue';

type MenuPos = { top: number; right: number };

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
}>();

const menuOpen = ref(false);
const menuBtnRef = ref<HTMLButtonElement | null>(null);
const menuPos = ref<MenuPos>({ top: 0, right: 0 });

const muted = ref(false);
const working = ref(false);

const departmentsList = computed<Department[]>(() => props.departments ?? []);
const departmentsMenuOpen = ref(false);
const departmentsBtnRef = ref<HTMLButtonElement | null>(null);
const departmentsMenuPos = ref<MenuPos>({ top: 0, right: 0 });
const selectedDepartmentIds = ref<number[]>([]);
const savingDepartments = ref(false);
const departmentSearchQuery = ref('');
const scheduledMessagesOpen = ref(false);
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

const departmentsLabel = computed<string>(() => {
    const count = selectedDepartmentIds.value.length;
    if (count === 0) return 'Отделы';
    if (count === 1) {
        return selectedDepartments.value[0]?.name ?? 'Отдел';
    }
    return selectedDepartments.value.map((d) => d.name).join(', ');
});

function toggleDepartmentsMenu() {
    if (departmentsMenuOpen.value) {
        departmentsMenuOpen.value = false;
        return;
    }
    departmentsMenuPos.value = computeMenuPosition(departmentsBtnRef.value);
    departmentsMenuOpen.value = true;
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
const selectedUserIds = ref<number[]>([]);
const savingUsers = ref(false);
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

function toggleUsersMenu() {
    if (usersMenuOpen.value) {
        usersMenuOpen.value = false;
        return;
    }
    usersMenuPos.value = computeMenuPosition(usersBtnRef.value);
    usersMenuOpen.value = true;
}

function onAssignUsersButtonClick() {
    if (assignUsersDisabled.value) {
        return;
    }
    toggleUsersMenu();
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
    menuPos.value = computeMenuPosition(menuBtnRef.value, 4);
    menuOpen.value = true;
}

function onEscape(e: KeyboardEvent) {
    if (e.key === 'Escape') {
        closeMenu();
        closeDepartmentsMenu();
        closeUsersMenu();
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
    if (menuOpen.value) {
        menuPos.value = computeMenuPosition(menuBtnRef.value, 4);
    }
}
function scrollTargetInsideOpenHeaderMenu(target: EventTarget | null): boolean {
    if (!(target instanceof Node)) {
        return false;
    }
    const roots = [departmentsMenuPanelRef.value, usersMenuPanelRef.value, overflowMenuPanelRef.value];
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

async function togglePin() {
    closeMenu();
    if (working.value) return;
    working.value = true;
    try {
        await axios.post(route('chats.toggle-pin', props.chat.id));
        router.reload({ only: ['chat', 'chats', 'unreadChatsCount'] });
    } finally {
        working.value = false;
    }
}

async function toggleArchive() {
    closeMenu();
    if (working.value) return;
    working.value = true;
    try {
        await axios.post(route('chats.archive', props.chat.id));
    } finally {
        working.value = false;
        router.visit(route('chats.index'));
    }
}

function openSearch() {
    closeMenu();
    emit('toggle-search');
}

function openContactInfo() {
    closeMenu();
    emit('show-contact-info');
}

function toggleMute() {
    closeMenu();
    muted.value = !muted.value;
}

function closeChatWindow() {
    closeMenu();
    router.visit(route('chats.index'));
}

async function clearChat() {
    closeMenu();
    if (!confirm('Очистить всю историю этого чата? Это действие необратимо.')) return;
    working.value = true;
    try {
        await axios.post(route('chats.clear', props.chat.id));
        router.reload({ only: ['messages', 'chat', 'unreadChatsCount'] });
    } finally {
        working.value = false;
    }
}

function notImplemented(name: string) {
    closeMenu();
    alert(`«${name}» — функция скоро будет доступна.`);
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
</script>

<template>
    <div class="h-[60px] bg-[var(--wa-panel-header)] flex items-center px-4 gap-3 shrink-0 relative">
        <Link :href="route('chats.index')" class="md:hidden text-[var(--wa-icon)]">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
        </Link>

        <div @click="openContactInfo" class="cursor-pointer shrink-0">
            <Avatar
                :avatar-url="chat.contact?.profile_picture_url"
                :name="displayName"
                :is-group="chat.is_group"
                :size="40"
            />
        </div>

        <div
            @click="openContactInfo"
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
        </div>

        <div class="flex items-center gap-2 shrink-0">
            <!-- Отделы: сотрудник только видит свой; админ/руководитель — выбор -->
            <div class="relative">
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
                        class="label-pill label-pill-dept"
                        :class="{ 'label-pill-dept-active': selectedDepartmentIds.length > 0 }"
                        :title="selectedDepartmentIds.length ? selectedDepartments.map((d) => d.name).join(', ') : 'Прикрепить отделы к чату'"
                        @click="toggleDepartmentsMenu"
                    >
                        <span class="truncate">{{ departmentsLabel }}</span>
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
                                borderColor: 'var(--wa-border-strong)',
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
                                    <span class="assign-avatar assign-avatar-dept" aria-hidden="true">
                                        {{ d.name?.charAt(0)?.toUpperCase() }}
                                    </span>
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
                                    class="px-5 py-4 text-sm"
                                    :style="{ color: 'var(--wa-text-secondary)' }"
                                >
                                    {{ departmentSearchQuery.trim() ? 'Ничего не найдено' : 'Нет доступных отделов. Создайте их в разделе «Настройки → Отделы».' }}
                                </div>
                            </div>
                        </div>
                    </Teleport>
                </template>
            </div>

            <button
                type="button"
                class="label-pill label-pill-scheduled"
                title="Отложенные сообщения"
                @click="scheduledMessagesOpen = true"
            >
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l2.5 1.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span class="hidden lg:inline">Отложенные</span>
            </button>

            <!-- Сотрудники: одна кнопка с аватарками; зелёная иерархия -->
            <div v-if="showAssignUsersBlock" class="relative">
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
                        class="flex -space-x-2 shrink-0"
                        aria-hidden="true"
                    >
                        <div
                            v-for="u in selectedUsers.slice(0, 3)"
                            :key="u.id"
                            class="staff-pill-avatar header-staff-avatar"
                            :title="u.name"
                        >
                            {{ u.name?.charAt(0)?.toUpperCase() }}
                        </div>
                        <div
                            v-if="selectedUserIds.length > 3"
                            class="staff-pill-avatar header-staff-avatar header-staff-more"
                        >
                            +{{ selectedUserIds.length - 3 }}
                        </div>
                    </div>
                    <div
                        v-else
                        class="staff-pill-avatar header-staff-avatar"
                        aria-hidden="true"
                    >
                        +
                    </div>
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
                            borderColor: 'var(--wa-border-strong)',
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
                                <span class="assign-avatar assign-avatar-staff" aria-hidden="true">
                                    {{ u.name?.charAt(0)?.toUpperCase() }}
                                </span>
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
                                class="px-5 py-4 text-sm"
                                :style="{ color: 'var(--wa-text-secondary)' }"
                            >
                                {{ userSearchQuery.trim() ? 'Ничего не найдено' : 'Нет пользователей для списка' }}
                            </div>
                        </div>
                    </div>
                </Teleport>
            </div>

            <div
                v-else-if="chat.assignments?.length"
                class="label-pill label-pill-staff label-pill-staff-static label-pill-staff-avatars"
                title="Ответственные за этот чат"
            >
                <div class="flex -space-x-2 shrink-0" aria-hidden="true">
                    <div
                        v-for="a in chat.assignments.slice(0, 3)"
                        :key="a.id"
                        class="staff-pill-avatar header-staff-avatar"
                    >
                        {{ a.user?.name?.charAt(0)?.toUpperCase() }}
                    </div>
                    <div
                        v-if="chat.assignments.length > 3"
                        class="staff-pill-avatar header-staff-avatar header-staff-more"
                    >
                        +{{ chat.assignments.length - 3 }}
                    </div>
                </div>
            </div>

            <button type="button" class="wa-header-btn" title="Поиск" @click="openSearch">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
            </button>

            <div class="relative">
                <button
                    ref="menuBtnRef"
                    class="wa-header-btn"
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
                            borderColor: 'var(--wa-border-strong)',
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
                    <button class="menu-item" @click="notImplemented('Выбрать сообщения')" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Выбрать сообщения
                    </button>
                    <button class="menu-item" @click="toggleMute" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path v-if="!muted" stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                            <path v-else stroke-linecap="round" stroke-linejoin="round" d="M5.586 15L4 17h5a3 3 0 006 0h5l-1.405-1.405M3 3l18 18M9 5.341V5a2 2 0 114 0v.341" />
                        </svg>
                        {{ muted ? 'Включить звук' : 'Без звука' }}
                    </button>
                    <button class="menu-item" @click="togglePin" type="button">
                        <svg class="menu-icon" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2l-2-2z" />
                        </svg>
                        {{ chat.is_pinned ? 'Убрать из избранного' : 'Добавить в избранное' }}
                    </button>
                    <button class="menu-item" @click="notImplemented('Добавить в список')" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h10M4 18h10M19 15v6m-3-3h6" />
                        </svg>
                        Добавить в список
                    </button>
                    <button class="menu-item" @click="toggleArchive" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 01-2-2V5a2 2 0 012-2h14a2 2 0 012 2v1a2 2 0 01-2 2M5 8v11a2 2 0 002 2h10a2 2 0 002-2V8M10 12h4" />
                        </svg>
                        {{ chat.is_archived ? 'Разархивировать' : 'Архивировать' }}
                    </button>
                    <button class="menu-item" @click="closeChatWindow" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        Закрыть окно чата
                    </button>

                    <div class="my-1 h-px" :style="{ background: 'var(--wa-border)' }"></div>

                    <button class="menu-item" @click="notImplemented('Пожаловаться')" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 2H21l-3 6 3 6h-8.5l-1-2H5a2 2 0 00-2 2z" />
                        </svg>
                        Пожаловаться
                    </button>
                    <button class="menu-item" @click="notImplemented('Заблокировать')" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                        </svg>
                        Заблокировать
                    </button>
                    <button class="menu-item menu-item-danger" @click="clearChat" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4l16 16M4 20L20 4" />
                        </svg>
                        Очистить чат
                    </button>
                    </div>
                </Teleport>
            </div>
        </div>

        <ScheduledMessagesModal
            :open="scheduledMessagesOpen"
            :chat-id="chat.id"
            @close="scheduledMessagesOpen = false"
        />
    </div>
</template>

<style scoped>
.wa-header-btn {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 9999px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--wa-icon);
    transition: background-color 0.15s ease;
}
.wa-header-btn:hover {
    background-color: var(--wa-rail-btn-hover);
}
.label-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    height: 2.15rem;
    padding: 0 0.72rem;
    border-radius: 9999px;
    font-size: 0.8125rem;
    color: var(--wa-text);
    background-color: var(--wa-panel);
    border: 1px solid var(--wa-border-strong);
    transition: background-color 0.15s ease, border-color 0.15s ease;
    max-width: 220px;
}
.label-pill:hover {
    background-color: var(--wa-panel-hover);
    border-color: var(--wa-border-strong);
}
.label-pill-active {
    color: var(--wa-accent);
    border-color: color-mix(in srgb, var(--wa-accent) 60%, transparent);
    background-color: color-mix(in srgb, var(--wa-accent) 12%, var(--wa-panel));
}
.label-pill-active:hover {
    background-color: color-mix(in srgb, var(--wa-accent) 18%, var(--wa-panel));
}

/* Отделы — янтарь (иерархия: не зелёный «сотрудники») */
.label-pill-dept {
    color: #fde68a;
    border-color: color-mix(in srgb, #f59e0b 45%, var(--wa-border-strong));
    background-color: color-mix(in srgb, #f59e0b 10%, var(--wa-panel));
    padding-inline: 0.95rem;
    max-width: 14rem;
}
.label-pill-dept:hover:not(:disabled) {
    background-color: color-mix(in srgb, #f59e0b 16%, var(--wa-panel));
    border-color: color-mix(in srgb, #f59e0b 55%, var(--wa-border-strong));
}
.label-pill-dept-active {
    color: #fffbeb;
    border-color: color-mix(in srgb, #f59e0b 70%, transparent);
    background-color: color-mix(in srgb, #f59e0b 22%, var(--wa-panel));
}
.label-pill-dept-active:hover:not(:disabled) {
    background-color: color-mix(in srgb, #f59e0b 28%, var(--wa-panel));
}
.label-pill-dept-static {
    pointer-events: none;
}
.label-pill-dept-static.label-pill-dept-active {
    opacity: 1;
}

.label-pill-scheduled {
    color: #bfdbfe;
    border-color: color-mix(in srgb, #3b82f6 45%, var(--wa-border-strong));
    background-color: color-mix(in srgb, #3b82f6 10%, var(--wa-panel));
    max-width: none;
    padding-inline: 0.75rem;
}
.label-pill-scheduled:hover {
    background-color: color-mix(in srgb, #3b82f6 16%, var(--wa-panel));
    border-color: color-mix(in srgb, #3b82f6 60%, var(--wa-border-strong));
}

/* Сотрудники — зелень (как акцент WhatsApp) */
.label-pill-staff {
    border-color: var(--wa-border-strong);
}
.label-pill-staff-avatars {
    min-width: 0;
    height: 2.15rem;
    padding: 0 0.42rem;
    justify-content: center;
    overflow: visible;
}
.label-pill-staff-active {
    color: var(--wa-accent);
    border-color: color-mix(in srgb, var(--wa-accent) 60%, transparent);
    background-color: color-mix(in srgb, var(--wa-accent) 12%, var(--wa-panel));
}
.label-pill-staff-active:hover:not(:disabled) {
    background-color: color-mix(in srgb, var(--wa-accent) 18%, var(--wa-panel));
}
.label-pill-staff-static {
    pointer-events: none;
    opacity: 0.95;
}

.staff-pill-avatar {
    background: color-mix(in srgb, var(--wa-accent) 24%, var(--wa-panel));
    color: var(--wa-accent);
    border-color: var(--wa-panel-header);
}
.header-staff-avatar {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 1.55rem;
    height: 1.55rem;
    border-radius: 9999px;
    border-width: 2px;
    font-size: 0.64rem;
    font-weight: 800;
}
.header-staff-more {
    color: var(--wa-text-secondary);
    background: color-mix(in srgb, var(--wa-panel) 82%, var(--wa-accent) 18%);
    font-weight: 700;
}

.dept-checkbox-dept {
    accent-color: #f59e0b;
}
.dept-checkbox-staff {
    accent-color: var(--wa-accent);
}

.dept-btn-dept-primary {
    color: #422006;
    background: linear-gradient(180deg, #fcd34d, #f59e0b);
    font-weight: 600;
}
.dept-btn-dept-primary:hover:not(:disabled) {
    filter: brightness(1.05);
}
.dept-btn-dept-primary:disabled {
    opacity: 0.5;
}

.dept-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.5rem 1rem;
    cursor: pointer;
    font-size: 0.875rem;
    color: var(--wa-text);
    transition: background-color 0.12s ease;
}
.dept-item:hover {
    background-color: var(--wa-panel-hover);
}
.dept-checkbox {
    width: 1rem;
    height: 1rem;
    accent-color: var(--wa-accent);
    cursor: pointer;
    flex-shrink: 0;
}
.dept-btn {
    padding: 0.375rem 0.875rem;
    font-size: 0.8125rem;
    border-radius: 9999px;
    color: var(--wa-text-secondary);
    background-color: transparent;
    transition: background-color 0.12s ease, color 0.12s ease;
}
.dept-btn:hover {
    background-color: var(--wa-panel-hover);
    color: var(--wa-text);
}
.dept-btn-primary {
    color: var(--wa-accent-on);
    background-color: var(--wa-accent);
    font-weight: 600;
}
.dept-btn-primary:hover {
    background-color: var(--wa-accent);
    color: var(--wa-accent-on);
    opacity: 0.9;
}
.dept-btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.assign-popover {
    background: var(--wa-panel-header);
}
.assign-selected {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
    padding: 0.65rem 0.75rem 0.55rem;
    border-bottom: 1px solid var(--wa-border);
}
.assign-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    max-width: 7.75rem;
    min-height: 1.85rem;
    padding: 0.28rem 0.6rem 0.28rem 0.7rem;
    border-radius: 9999px;
    font-size: 0.8rem;
    font-weight: 600;
    line-height: 1.1;
}
.assign-chip-staff {
    color: #dcfff2;
    background: linear-gradient(180deg, color-mix(in srgb, var(--wa-accent) 96%, #ffffff 4%), color-mix(in srgb, var(--wa-accent) 86%, var(--wa-bg) 14%));
    box-shadow: 0 0 0 1px color-mix(in srgb, var(--wa-accent) 30%, transparent) inset;
}
.assign-chip-dept {
    color: #fff7ed;
    background: linear-gradient(180deg, #f59e0b, color-mix(in srgb, #f59e0b 82%, #78350f 18%));
    box-shadow: 0 0 0 1px color-mix(in srgb, #f59e0b 35%, transparent) inset;
}
.assign-chip:hover {
    filter: brightness(1.05);
}
.assign-search-wrap {
    padding: 0.6rem 0.75rem;
    border-bottom: 1px solid var(--wa-border);
    flex-shrink: 0;
}
.assign-searchbox {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    width: 100%;
    min-height: 2.7rem;
    padding: 0 0.75rem;
    border: 1px solid var(--wa-border-strong);
    border-radius: 0.75rem;
    color: var(--wa-text-secondary);
    background: color-mix(in srgb, var(--wa-panel) 72%, var(--wa-panel-input) 28%);
    transition: border-color 0.12s ease, box-shadow 0.12s ease, background-color 0.12s ease;
}
.assign-searchbox:focus-within {
    border-color: color-mix(in srgb, var(--wa-accent) 70%, var(--wa-border-strong));
    box-shadow: 0 0 0 1px color-mix(in srgb, var(--wa-accent) 30%, transparent);
    background: color-mix(in srgb, var(--wa-panel) 84%, var(--wa-panel-input) 16%);
}
.assign-search {
    width: 100%;
    box-sizing: border-box;
    padding: 0;
    font-size: 0.875rem;
    border: 0;
    background: transparent;
    color: var(--wa-text);
    outline: none;
}
.assign-search::placeholder {
    color: var(--wa-text-secondary);
}
/* Высота списка — flex внутри панели; прокрутка в контейнере */
.assign-list {
    min-height: 0;
}
.assign-row {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    width: 100%;
    min-height: 3.35rem;
    padding: 0.42rem 0.75rem;
    color: var(--wa-text);
    transition: background-color 0.12s ease;
}
.assign-row:hover {
    background: var(--wa-panel-hover);
}
.assign-row-staff-active {
    background: color-mix(in srgb, var(--wa-accent) 16%, transparent);
}
.assign-row-staff-active:hover {
    background: color-mix(in srgb, var(--wa-accent) 20%, transparent);
}
.assign-row-dept-active {
    background: color-mix(in srgb, #f59e0b 16%, transparent);
}
.assign-row-dept-active:hover {
    background: color-mix(in srgb, #f59e0b 20%, transparent);
}
.assign-avatar {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 2.15rem;
    height: 2.15rem;
    flex: 0 0 2.15rem;
    border-radius: 9999px;
    font-size: 0.86rem;
    font-weight: 700;
}
.assign-avatar-staff {
    color: #dcfff2;
    background: var(--wa-accent);
}
.assign-avatar-dept {
    color: #fff7ed;
    background: #f59e0b;
}
.assign-name {
    font-size: 0.9rem;
    font-weight: 500;
    line-height: 1.2;
}
.assign-role {
    margin-top: 0.12rem;
    font-size: 0.74rem;
    line-height: 1.2;
}
.assign-check {
    width: 1.05rem;
    height: 1.05rem;
    flex: 0 0 auto;
}
.assign-check-staff {
    color: var(--wa-accent);
}
.assign-check-dept {
    color: #f59e0b;
}
.header-menu {
    animation: header-menu-pop 0.12s ease-out;
}
@keyframes header-menu-pop {
    from { opacity: 0; transform: translateY(-4px) scale(0.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
.menu-item {
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
    background-color: var(--wa-panel-hover);
}
.menu-icon {
    width: 1.125rem;
    height: 1.125rem;
    color: var(--wa-text-secondary);
    flex-shrink: 0;
}
.menu-item-danger {
    color: #ef4444;
}
.menu-item-danger .menu-icon {
    color: #ef4444;
}
.menu-item-danger:hover {
    background-color: rgba(239, 68, 68, 0.08);
}
</style>
