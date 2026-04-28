<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, onBeforeUnmount, computed, watch } from 'vue';
import axios from 'axios';
import Avatar from '@/Components/Avatar.vue';
import type { AssignableUser, Chat, Department } from '@/types';
import { formatPhone } from '@/utils/phone';

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

function syncSelectedFromChat() {
    selectedDepartmentIds.value = (props.chat.departments ?? []).map((d) => d.id);
}
syncSelectedFromChat();
watch(() => props.chat.id, syncSelectedFromChat);
watch(() => props.chat.departments, syncSelectedFromChat, { deep: true });

const selectedDepartments = computed<Department[]>(() =>
    departmentsList.value.filter((d) => selectedDepartmentIds.value.includes(d.id)),
);

const departmentsLabel = computed<string>(() => {
    const count = selectedDepartmentIds.value.length;
    if (count === 0) return 'Выбрать отделы';
    if (count === 1) {
        return selectedDepartments.value[0]?.name ?? 'Отдел';
    }
    return `Отделы: ${count}`;
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
}

async function saveDepartments() {
    if (savingDepartments.value) return;
    savingDepartments.value = true;
    try {
        await axios.post(route('chats.departments.sync', props.chat.id), {
            department_ids: selectedDepartmentIds.value,
        });
        router.reload({ only: ['chat'] });
        closeDepartmentsMenu();
    } finally {
        savingDepartments.value = false;
    }
}

// --- Assignable users (сотрудники + руководители) ---------------------------
const assignableUsersList = computed<AssignableUser[]>(() => props.assignableUsers ?? []);
const canAssignUsers = computed(() => assignableUsersList.value.length > 0);
const usersMenuOpen = ref(false);
const usersBtnRef = ref<HTMLButtonElement | null>(null);
const usersMenuPos = ref<MenuPos>({ top: 0, right: 0 });
const selectedUserIds = ref<number[]>([]);
const savingUsers = ref(false);

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

const userSearchQuery = ref('');
const showAssignableUserSearch = computed(() => assignableUsersList.value.length > 10);

watch(usersMenuOpen, (open) => {
    if (!open) {
        userSearchQuery.value = '';
    }
});

const filteredAssignableUsers = computed(() => {
    const list = assignableUsersList.value;
    if (!showAssignableUserSearch.value || !userSearchQuery.value.trim()) {
        return list;
    }
    const q = userSearchQuery.value.trim().toLowerCase();
    return list.filter((u) => {
        const name = (u.name || '').toLowerCase();
        const email = (u.email || '').toLowerCase();
        const role = roleLabel(u.roles).toLowerCase();
        return name.includes(q) || email.includes(q) || role.includes(q);
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
}

async function saveUsers() {
    if (savingUsers.value) return;
    savingUsers.value = true;
    try {
        await axios.post(route('chats.assign.sync', props.chat.id), {
            user_ids: selectedUserIds.value,
        });
        router.reload({ only: ['chat'] });
        closeUsersMenu();
    } finally {
        savingUsers.value = false;
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
    }
}

// Пересчитываем позицию открытых меню при ресайзе и закрываем при скролле страницы:
// это надёжнее, чем гоняться за скроллом внутри произвольных контейнеров.
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
function onViewportScroll() {
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
});

async function togglePin() {
    closeMenu();
    if (working.value) return;
    working.value = true;
    try {
        await axios.post(route('chats.toggle-pin', props.chat.id));
        router.reload({ only: ['chat', 'chats'] });
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
        router.reload({ only: ['messages', 'chat'] });
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
        props.chat.contact?.push_name ||
        props.chat.contact?.name ||
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

        <div v-if="chat.assignments?.length" class="flex -space-x-1.5 shrink-0 mr-2">
            <div
                v-for="a in chat.assignments.slice(0, 3)"
                :key="a.id"
                class="w-7 h-7 rounded-full border-2 flex items-center justify-center text-[10px] font-bold"
                :style="{
                    background: 'var(--wa-accent-soft)',
                    color: 'var(--wa-accent)',
                    borderColor: 'var(--wa-panel-header)'
                }"
                :title="a.user?.name"
            >
                {{ a.user?.name?.charAt(0)?.toUpperCase() }}
            </div>
            <div
                v-if="chat.assignments.length > 3"
                class="w-7 h-7 rounded-full border-2 flex items-center justify-center text-[10px] font-medium"
                :style="{
                    background: 'var(--wa-selected)',
                    color: 'var(--wa-text-secondary)',
                    borderColor: 'var(--wa-panel-header)'
                }"
            >
                +{{ chat.assignments.length - 3 }}
            </div>
        </div>

        <div class="flex items-center gap-2 shrink-0">
            <!-- Departments multi-select -->
            <div class="relative">
                <button
                    ref="departmentsBtnRef"
                    type="button"
                    class="label-pill"
                    :class="{ 'label-pill-active': selectedDepartmentIds.length > 0 }"
                    :title="selectedDepartmentIds.length ? selectedDepartments.map((d) => d.name).join(', ') : 'Прикрепить отделы к чату'"
                    @click="toggleDepartmentsMenu"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5a2 2 0 011.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z" />
                    </svg>
                    <span class="truncate">{{ departmentsLabel }}</span>
                    <svg class="w-3 h-3 opacity-70" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                <Teleport to="body">
                    <div
                        v-if="departmentsMenuOpen"
                        @click="closeDepartmentsMenu"
                        class="fixed inset-0 z-[900]"
                    ></div>

                    <div
                        v-if="departmentsMenuOpen"
                        class="fixed min-w-[280px] rounded-lg shadow-xl z-[1000] border header-menu overflow-hidden"
                        :style="{
                            top: `${departmentsMenuPos.top}px`,
                            right: `${departmentsMenuPos.right}px`,
                            background: 'var(--wa-panel-header)',
                            borderColor: 'var(--wa-border-strong)',
                        }"
                    >
                        <div
                            class="px-4 py-2.5 text-[13px] font-medium border-b"
                            :style="{ color: 'var(--wa-text-secondary)', borderColor: 'var(--wa-border)' }"
                        >
                            Отделы
                        </div>

                        <div class="max-h-[280px] overflow-y-auto wa-scrollbar py-1">
                            <label
                                v-for="d in departmentsList"
                                :key="d.id"
                                class="dept-item"
                            >
                                <input
                                    type="checkbox"
                                    class="dept-checkbox"
                                    :checked="selectedDepartmentIds.includes(d.id)"
                                    @change="toggleDepartment(d.id)"
                                />
                                <span class="flex-1 truncate">{{ d.name }}</span>
                            </label>

                            <div
                                v-if="departmentsList.length === 0"
                                class="px-4 py-3 text-[13px]"
                                :style="{ color: 'var(--wa-text-secondary)' }"
                            >
                                Нет доступных отделов.
                                Создайте их в разделе «Настройки → Отделы».
                            </div>
                        </div>

                        <div
                            v-if="departmentsList.length > 0"
                            class="flex items-center justify-end gap-2 px-3 py-2 border-t"
                            :style="{ borderColor: 'var(--wa-border)' }"
                        >
                            <button
                                type="button"
                                class="dept-btn"
                                @click="closeDepartmentsMenu"
                            >
                                Отмена
                            </button>
                            <button
                                type="button"
                                class="dept-btn dept-btn-primary"
                                :disabled="savingDepartments"
                                @click="saveDepartments"
                            >
                                {{ savingDepartments ? 'Сохранение...' : 'Сохранить' }}
                            </button>
                        </div>
                    </div>
                </Teleport>
            </div>

            <!-- Assignable users multi-select -->
            <div v-if="canAssignUsers" class="relative">
                <button
                    ref="usersBtnRef"
                    type="button"
                    class="label-pill"
                    :class="{ 'label-pill-active': selectedUserIds.length > 0 }"
                    :title="selectedUserIds.length ? selectedUsers.map((u) => u.name).join(', ') : 'Назначить сотрудников на чат'"
                    @click="toggleUsersMenu"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                    <span class="truncate">{{ usersLabel }}</span>
                    <svg class="w-3 h-3 opacity-70" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                <Teleport to="body">
                    <div
                        v-if="usersMenuOpen"
                        @click="closeUsersMenu"
                        class="fixed inset-0 z-[900]"
                    ></div>

                    <div
                        v-if="usersMenuOpen"
                        class="fixed min-w-[300px] rounded-lg shadow-xl z-[1000] border header-menu overflow-hidden"
                        :style="{
                            top: `${usersMenuPos.top}px`,
                            right: `${usersMenuPos.right}px`,
                            background: 'var(--wa-panel-header)',
                            borderColor: 'var(--wa-border-strong)',
                        }"
                    >
                        <div
                            class="px-4 py-2.5 text-[13px] font-medium border-b"
                            :style="{ color: 'var(--wa-text-secondary)', borderColor: 'var(--wa-border)' }"
                        >
                            Назначить сотрудников
                        </div>

                        <div
                            v-if="showAssignableUserSearch"
                            class="px-3 py-2 border-b shrink-0"
                            :style="{ borderColor: 'var(--wa-border)' }"
                            @click.stop
                        >
                            <input
                                v-model="userSearchQuery"
                                type="search"
                                autocomplete="off"
                                placeholder="Поиск по имени, почте, роли…"
                                class="users-assign-search"
                            />
                        </div>

                        <div class="users-assign-list wa-scrollbar py-1">
                            <label
                                v-for="u in filteredAssignableUsers"
                                :key="u.id"
                                class="dept-item"
                                @click.stop
                            >
                                <input
                                    type="checkbox"
                                    class="dept-checkbox"
                                    :checked="selectedUserIds.includes(u.id)"
                                    @change="toggleUser(u.id)"
                                />
                                <div class="flex-1 min-w-0">
                                    <div class="truncate text-[14px]">{{ u.name }}</div>
                                    <div
                                        v-if="roleLabel(u.roles)"
                                        class="truncate text-[11px]"
                                        :style="{ color: 'var(--wa-text-secondary)' }"
                                    >
                                        {{ roleLabel(u.roles) }}
                                    </div>
                                </div>
                            </label>
                            <div
                                v-if="filteredAssignableUsers.length === 0"
                                class="px-4 py-3 text-[13px]"
                                :style="{ color: 'var(--wa-text-secondary)' }"
                            >
                                Ничего не найдено
                            </div>
                        </div>

                        <div
                            class="flex items-center justify-end gap-2 px-3 py-2 border-t"
                            :style="{ borderColor: 'var(--wa-border)' }"
                        >
                            <button
                                type="button"
                                class="dept-btn"
                                @click="closeUsersMenu"
                            >
                                Отмена
                            </button>
                            <button
                                type="button"
                                class="dept-btn dept-btn-primary"
                                :disabled="savingUsers"
                                @click="saveUsers"
                            >
                                {{ savingUsers ? 'Сохранение...' : 'Сохранить' }}
                            </button>
                        </div>
                    </div>
                </Teleport>
            </div>

            <button class="wa-header-btn" title="Видеозвонок" @click="notImplemented('Видеозвонок')">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
            </button>
            <button class="wa-header-btn" title="Поиск" @click="openSearch">
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
                    <button class="menu-item" @click="notImplemented('Исчезающие сообщения')" type="button">
                        <svg class="menu-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Исчезающие сообщения
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
    padding: 0.375rem 0.75rem 0.375rem 0.625rem;
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
.users-assign-search {
    width: 100%;
    box-sizing: border-box;
    padding: 0.4375rem 0.75rem;
    font-size: 0.8125rem;
    border-radius: 0.5rem;
    border: 1px solid var(--wa-border-strong);
    background: var(--wa-panel);
    color: var(--wa-text);
    outline: none;
}
.users-assign-search::placeholder {
    color: var(--wa-text-secondary);
}
.users-assign-search:focus {
    border-color: var(--wa-accent);
    box-shadow: 0 0 0 1px color-mix(in srgb, var(--wa-accent) 35%, transparent);
}
/* Видимы примерно 3 строки списка; остальное — прокрутка */
.users-assign-list {
    max-height: 11.75rem;
    overflow-y: auto;
    min-height: 0;
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
