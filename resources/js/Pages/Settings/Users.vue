<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { useRoleLabels } from '@/composables/useRoleLabels';
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import UiFilterField from '@/Components/Ui/UiFilterField.vue';
import UiFilterPanel from '@/Components/Ui/UiFilterPanel.vue';
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import type { User, Department, WhatsappSession } from '@/types';
import { useToastStore } from '@/stores/toast';

type PaginationPayload<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

type UserFilters = {
    search: string;
    role: string;
    department_id: number | null;
    status: string;
};

type CompanyOption = {
    id: number;
    name: string;
};

const props = defineProps<{
    users: PaginationPayload<User>;
    filters: UserFilters;
    departments: Department[];
    companies: CompanyOption[];
    whatsappSessions: WhatsappSession[];
    availableRoles: string[];
}>();

const { show: showToast } = useToastStore();
const { t } = useI18n();
const { label: roleLabel } = useRoleLabels();

const local = ref<User[]>([...props.users.data]);
const filters = ref<UserFilters>({
    search: props.filters.search || '',
    role: props.filters.role || '',
    department_id: props.filters.department_id || null,
    status: props.filters.status || '',
});
const showForm = ref(false);
const formError = ref('');
const saving = ref(false);
const editingHasPin = ref(false);
const editingId = ref<number | null>(null);
const userDeleteDialogOpen = ref(false);
const userDeleteTarget = ref<User | null>(null);
const userDeleting = ref(false);
const form = ref({
    name: '',
    email: '',
    phone: '',
    password: '',
    pin: '',
    role: 'employee',
    company_id: null as number | null,
    department_ids: [] as number[],
    is_active: true,
    whatsapp_session_ids: [] as number[],
});

/**
 * Плоский список отделов с глубиной — для чек-листа в форме.
 * Сортировка: рекурсивный обход от корней (parent_id == null) с сортировкой
 * детей по name. Отделы-сироты (с битым parent_id) — в самом конце как корни.
 */
const departmentTree = computed(() => {
    const byParent = new Map<number | null, Department[]>();
    for (const d of props.departments) {
        const key = d.parent_id ?? null;
        const arr = byParent.get(key) ?? [];
        arr.push(d);
        byParent.set(key, arr);
    }
    for (const [, arr] of byParent) {
        arr.sort((a, b) => a.name.localeCompare(b.name, 'ru'));
    }
    const visited = new Set<number>();
    const out: Array<{ dept: Department; depth: number }> = [];
    const visit = (parentId: number | null, depth: number): void => {
        for (const d of byParent.get(parentId) ?? []) {
            if (visited.has(d.id)) continue;
            visited.add(d.id);
            out.push({ dept: d, depth });
            visit(d.id, depth + 1);
        }
    };
    visit(null, 0);
    for (const d of props.departments) {
        if (!visited.has(d.id)) {
            visited.add(d.id);
            out.push({ dept: d, depth: 0 });
        }
    }
    return out;
});

function deptNameById(id: number): string {
    const d = props.departments.find((x) => x.id === id);
    return d ? d.name : `#${id}`;
}

function userDepartmentNames(user: User): string[] {
    const ids = user.department_ids && user.department_ids.length
        ? user.department_ids
        : (user.department_id != null ? [user.department_id] : []);
    return ids.map(deptNameById);
}

watch(
    () => props.users,
    (next) => {
        if (!showForm.value) {
            local.value = [...next.data];
        }
    },
    { deep: true },
);

watch(
    () => props.filters,
    (next) => {
        filters.value = {
            search: next.search || '',
            role: next.role || '',
            department_id: next.department_id || null,
            status: next.status || '',
        };
    },
);

let filterTimer: ReturnType<typeof setTimeout> | null = null;
watch(
    filters,
    () => {
        if (filterTimer) clearTimeout(filterTimer);
        filterTimer = setTimeout(() => visitUsers({ page: undefined }), 250);
    },
    { deep: true },
);

function visitUsers(overrides: Record<string, string | number | null | undefined> = {}): void {
    router.get(
        route('settings.users'),
        {
            search: filters.value.search.trim() || undefined,
            role: filters.value.role || undefined,
            department_id: filters.value.department_id || undefined,
            status: filters.value.status || undefined,
            page: props.users.current_page > 1 ? props.users.current_page : undefined,
            ...overrides,
        },
        { preserveState: true, replace: true },
    );
}

function goToPage(page: number): void {
    if (page < 1 || page > props.users.last_page || page === props.users.current_page) return;
    visitUsers({ page });
}

function resetFilters(): void {
    filters.value = {
        search: '',
        role: '',
        department_id: null,
        status: '',
    };
}

function openAdd() {
    editingId.value = null;
    editingHasPin.value = false;
    formError.value = '';
    form.value = {
        name: '',
        email: '',
        phone: '',
        password: '',
        pin: '',
        role: 'employee',
        company_id: props.companies[0]?.id ?? null,
        department_ids: [],
        is_active: true,
        whatsapp_session_ids: [],
    };
    showForm.value = true;
}

function openEdit(user: User) {
    editingId.value = user.id;
    editingHasPin.value = Boolean((user as User & { has_pin?: boolean }).has_pin);
    formError.value = '';
    const primaryPhone = (user.phone || user.phones?.[0] || '').trim();
    const deptIds = user.department_ids && user.department_ids.length
        ? [...user.department_ids]
        : (user.department_id != null ? [user.department_id] : []);
    form.value = {
        name: user.name,
        email: user.email ?? '',
        phone: primaryPhone,
        password: '',
        pin: '',
        role: user.roles?.[0] || 'employee',
        company_id: user.company_id ?? null,
        department_ids: deptIds,
        is_active: user.is_active,
        whatsapp_session_ids: [...(user.whatsapp_session_ids || [])],
    };
    showForm.value = true;
}

function toggleDepartment(id: number) {
    const idx = form.value.department_ids.indexOf(id);
    if (idx === -1) {
        form.value.department_ids = [...form.value.department_ids, id];
    } else {
        form.value.department_ids = form.value.department_ids.filter((x) => x !== id);
    }
}

function closeModal() {
    showForm.value = false;
}

function toggleSession(id: number) {
    const idx = form.value.whatsapp_session_ids.indexOf(id);
    if (idx === -1) {
        form.value.whatsapp_session_ids.push(id);
    } else {
        form.value.whatsapp_session_ids.splice(idx, 1);
    }
}

/** Подпись из «Подключений» (display_name), не номер SIM и не номер аккаунта WhatsApp. */
function sessionLabel(s: WhatsappSession): string {
    const name = s.display_name?.trim();
    if (name) {
        return name;
    }
    return s.session_name;
}

function sessionStatusColor(status: string): string {
    if (status === 'connected') return '#01b964';
    if (status === 'qr_pending' || status === 'connecting') return '#f59e0b';
    return '#ef4444';
}

function userHasPin(user: User): boolean {
    return Boolean((user as User & { has_pin?: boolean }).has_pin);
}

function userPrimaryPhone(user: User): string {
    const p = (user.phone || user.phones?.[0] || '').trim();
    return p !== '' ? p : '—';
}

function buildPayload(): Record<string, unknown> {
    const deptIds = [...new Set(form.value.department_ids.map((x) => Number(x)).filter((x) => Number.isFinite(x) && x > 0))];

    const phoneTrim = form.value.phone.trim();
    const pinTrim = form.value.pin.trim();
    const emailTrim = form.value.email.trim();
    const payload: Record<string, unknown> = {
        name: form.value.name.trim(),
        email: emailTrim !== '' ? emailTrim : null,
        phone: phoneTrim !== '' ? phoneTrim : null,
        phones: phoneTrim !== '' ? [phoneTrim] : [],
        role: form.value.role,
        company_id: form.value.company_id,
        department_ids: deptIds,
        whatsapp_session_ids: form.value.whatsapp_session_ids,
    };

    if (editingId.value) {
        payload.is_active = form.value.is_active;
        payload.pin = pinTrim;
        if (form.value.password.trim()) {
            payload.password = form.value.password;
        }
    } else {
        if (form.value.password.trim()) {
            payload.password = form.value.password;
        }
        if (pinTrim !== '') {
            payload.pin = pinTrim;
        }
    }

    return payload;
}

function validationMessage(err: unknown): string {
    const e = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } };
    const data = e.response?.data;
    if (data?.errors) {
        return Object.values(data.errors)
            .flat()
            .join('\n');
    }
    return data?.message || t('settings.users.errorSave');
}

async function save() {
    formError.value = '';

    if (!form.value.name.trim()) {
        formError.value = t('settings.users.errorNameRequired');
        return;
    }

    if (!editingId.value) {
        const hasPassword = form.value.password.trim() !== '';
        const hasPin = form.value.pin.trim() !== '';
        if (!hasPassword && !hasPin) {
            formError.value = t('settings.usersForm.errorCredentials');
            return;
        }
    }

    saving.value = true;
    try {
        const payload = buildPayload();
        if (editingId.value) {
            await axios.put(route('settings.users.update', editingId.value), payload);
            showToast({ message: t('settings.users.toastSaved'), duration: 3000 });
        } else {
            await axios.post(route('settings.users.store'), payload);
            showToast({ message: t('settings.users.toastCreated'), duration: 3000 });
        }
        showForm.value = false;
        await router.reload({ only: ['users'] });
    } catch (err: unknown) {
        const message = validationMessage(err);
        formError.value = message;
        showToast({ message, duration: 6000 });
    } finally {
        saving.value = false;
    }
}

function closeUserDeleteDialog(): void {
    if (userDeleting.value) return;
    userDeleteDialogOpen.value = false;
    userDeleteTarget.value = null;
}

function requestRemoveUser(user: User): void {
    userDeleteTarget.value = user;
    userDeleteDialogOpen.value = true;
}

async function confirmRemoveUser(): Promise<void> {
    const user = userDeleteTarget.value;
    if (!user) return;
    userDeleting.value = true;
    try {
        await axios.delete(route('settings.users.destroy', user.id));
        showToast({ message: t('settings.users.toastDeleted'), duration: 3000 });
        userDeleteDialogOpen.value = false;
        userDeleteTarget.value = null;
        await router.reload({ only: ['users'] });
    } catch (err: unknown) {
        showToast({ message: validationMessage(err), duration: 6000 });
    } finally {
        userDeleting.value = false;
    }
}

const userDeleteDescription = computed(() => {
    const u = userDeleteTarget.value;
    if (!u) return '';
    return t('settings.users.deleteDescription', { name: u.name });
});
</script>

<template>
    <Head :title="t('settings.users.title')" />
    <SettingsLayout :title="t('settings.users.title')" :subtitle="t('settings.users.subtitle')">
        <template #actions>
            <button type="button" class="ui-btn ui-btn--primary" @click="openAdd">
                {{ t('settings.users.addButton') }}
            </button>
        </template>

        <div class="w-full px-6 py-6 space-y-4">
            <p class="text-sm text-[var(--ui-text-secondary)] max-w-3xl">
                {{ t('settings.users.intro') }}
            </p>

            <UiFilterPanel as="div">
                <UiFilterField wide>
                    <input
                        v-model="filters.search"
                        type="search"
                        class="settings-input"
                        :placeholder="t('settings.users.searchPlaceholder')"
                    />
                </UiFilterField>
                <UiFilterField>
                    <select v-model="filters.role" class="settings-input">
                        <option value="">{{ t('settings.users.filterRoleAll') }}</option>
                        <option v-for="role in availableRoles" :key="role" :value="role">
                            {{ roleLabel(role) }}
                        </option>
                    </select>
                </UiFilterField>
                <UiFilterField>
                    <select v-model="filters.department_id" class="settings-input">
                        <option :value="null">{{ t('settings.users.filterDeptAll') }}</option>
                        <option v-for="node in departmentTree" :key="node.dept.id" :value="node.dept.id">
                            {{ `${'— '.repeat(node.depth)}${node.dept.name}` }}
                        </option>
                    </select>
                </UiFilterField>
                <UiFilterField>
                    <select v-model="filters.status" class="settings-input">
                        <option value="">{{ t('settings.users.filterStatusAll') }}</option>
                        <option value="active">{{ t('settings.users.filterStatusActive') }}</option>
                        <option value="inactive">{{ t('settings.users.filterStatusInactive') }}</option>
                    </select>
                </UiFilterField>
                <template #actions>
                    <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="resetFilters">
                        {{ t('settings.users.resetFilters') }}
                    </button>
                </template>
            </UiFilterPanel>

            <div class="flex items-center justify-between gap-3 text-xs text-[var(--ui-text-secondary)]">
                <span>{{ t('settings.users.shownRange', { from: props.users.from || 0, to: props.users.to || 0, total: props.users.total }) }}</span>
                <span>{{ t('settings.users.pageOf', { current: props.users.current_page, last: props.users.last_page }) }}</span>
            </div>

            <!-- Users table: действия сразу после имени, чтобы кнопки не уезжали за край экрана -->
            <div class="ui-panel ui-table-panel">
                <table>
                    <thead>
                        <tr>
                            <th class="text-left px-5 py-3 font-medium text-[var(--ui-text-secondary)]">{{ t('settings.users.colName') }}</th>
                            <th class="text-right px-5 py-3 font-medium text-[var(--ui-text-secondary)] whitespace-nowrap w-[1%]">
                                {{ t('settings.users.colActions') }}
                            </th>
                            <th class="text-left px-5 py-3 font-medium text-[var(--ui-text-secondary)]">{{ t('settings.users.colEmail') }}</th>
                            <th class="text-left px-5 py-3 font-medium text-[var(--ui-text-secondary)]">{{ t('settings.users.colPhone') }}</th>
                            <th class="text-left px-5 py-3 font-medium text-[var(--ui-text-secondary)]">{{ t('settings.users.colRole') }}</th>
                            <th class="text-left px-5 py-3 font-medium text-[var(--ui-text-secondary)]">{{ t('settings.users.colDepartments') }}</th>
                            <th class="text-left px-5 py-3 font-medium text-[var(--ui-text-secondary)]">{{ t('settings.users.colWhatsapp') }}</th>
                            <th class="text-left px-5 py-3 font-medium text-[var(--ui-text-secondary)]">{{ t('settings.users.colStatus') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="local.length === 0">
                            <td colspan="8" class="px-5 py-10 text-center text-[var(--ui-text-secondary)]">
                                {{ t('settings.users.empty') }}
                            </td>
                        </tr>
                        <tr
                            v-for="user in local"
                            :key="user.id"
                            class="border-t transition-colors hover:bg-[color-mix(in_srgb,var(--ui-surface-hover)_65%,transparent)] cursor-pointer"
                            :style="{ borderColor: 'var(--ui-border)' }"
                            :title="t('settings.users.rowEditHint')"
                            @click="openEdit(user)"
                        >
                            <td class="px-5 py-3 font-medium text-[var(--ui-text)]">{{ user.name }}</td>
                            <td class="px-5 py-3 text-right whitespace-nowrap align-middle" @click.stop>
                                <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm mr-2" @click="openEdit(user)">
                                    {{ t('settings.users.edit') }}
                                </button>
                                <button type="button" class="ui-btn ui-btn--danger-ghost ui-btn--sm" @click="requestRemoveUser(user)">
                                    {{ t('settings.users.delete') }}
                                </button>
                            </td>
                            <td class="px-5 py-3 text-[var(--ui-text-secondary)]">{{ user.email || '—' }}</td>
                            <td class="px-5 py-3 text-[var(--ui-text-secondary)] text-xs font-mono">{{ userPrimaryPhone(user) }}</td>
                            <td class="px-5 py-3">
                                <span
                                    class="ui-badge"
                                    :class="{
                                        'ui-badge--admin': user.roles?.[0] === 'administrator',
                                        'ui-badge--manager': user.roles?.[0] === 'manager',
                                        'ui-badge--employee': user.roles?.[0] === 'employee',
                                    }"
                                >
                                    {{ roleLabel(user.roles?.[0]) }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <div v-if="userDepartmentNames(user).length > 0" class="flex flex-wrap gap-1">
                                    <span
                                        v-for="name in userDepartmentNames(user)"
                                        :key="name"
                                        class="ui-badge ui-badge--neutral"
                                    >
                                        {{ name }}
                                    </span>
                                </div>
                                <span v-else class="text-xs text-[var(--ui-text-secondary)]">—</span>
                            </td>
                            <td class="px-5 py-3">
                                <div
                                    v-if="user.whatsapp_sessions && user.whatsapp_sessions.length"
                                    class="flex flex-wrap gap-1"
                                >
                                    <span
                                        v-for="s in user.whatsapp_sessions"
                                        :key="s.id"
                                        class="inline-flex items-center gap-1.5 text-xs px-2 py-0.5 rounded-full"
                                        :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                                        :title="sessionLabel(s)"
                                    >
                                        <span
                                            class="w-1.5 h-1.5 rounded-full"
                                            :style="{ background: sessionStatusColor(s.status) }"
                                        ></span>
                                        {{ sessionLabel(s) }}
                                    </span>
                                </div>
                                <span v-else class="text-xs text-[var(--ui-text-secondary)]">—</span>
                            </td>
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center gap-2">
                                    <span
                                        class="w-2 h-2 rounded-full"
                                        :class="user.is_active ? 'bg-[var(--ui-accent)]' : 'bg-red-400'"
                                    ></span>
                                    <span class="text-xs text-[var(--ui-text-secondary)]">
                                        {{ user.is_active ? t('settings.users.statusActive') : t('settings.users.statusInactive') }}
                                    </span>
                                    <span
                                        v-if="userHasPin(user)"
                                        class="text-[10px] uppercase tracking-wide px-1.5 py-0.5 rounded"
                                        :style="{ background: 'color-mix(in srgb, var(--ui-accent) 18%, transparent)', color: 'var(--ui-accent)' }"
                                    >
                                        PIN
                                    </span>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="props.users.last_page > 1" class="flex items-center justify-between gap-3">
                <button
                    type="button"
                    class="rounded-lg px-3 py-2 text-sm disabled:opacity-40"
                    :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                    :disabled="props.users.current_page <= 1"
                    @click="goToPage(props.users.current_page - 1)"
                >
                    {{ t('common.back') }}
                </button>
                <div class="text-sm text-[var(--ui-text-secondary)]">
                    {{ props.users.current_page }} / {{ props.users.last_page }}
                </div>
                <button
                    type="button"
                    class="rounded-lg px-3 py-2 text-sm disabled:opacity-40"
                    :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                    :disabled="props.users.current_page >= props.users.last_page"
                    @click="goToPage(props.users.current_page + 1)"
                >
                    {{ t('common.forward') }}
                </button>
            </div>
        </div>

        <Teleport to="body">
            <div
                v-if="showForm"
                class="fixed inset-0 z-[2000] flex items-center justify-center p-4 bg-black/60"
                role="dialog"
                aria-modal="true"
                :aria-label="editingId ? t('settings.users.editUser') : t('settings.users.newUser')"
                @click.self="closeModal"
            >
                <div
                    class="w-full max-w-xl max-h-[min(90vh,800px)] overflow-hidden flex flex-col rounded-xl border shadow-2xl"
                    :style="{ background: 'var(--ui-surface)', borderColor: 'var(--ui-border-strong)' }"
                    @click.stop
                >
                    <div class="px-5 py-4 border-b shrink-0 flex items-center justify-between gap-3" :style="{ borderColor: 'var(--ui-border)' }">
                        <h3 class="text-base font-medium text-[var(--ui-text)]">
                            {{ editingId ? t('settings.users.editUser') : t('settings.users.newUser') }}
                        </h3>
                        <button
                            type="button"
                            class="text-sm text-[var(--ui-text-secondary)] hover:text-[var(--ui-text)] px-2 py-1 rounded"
                            :aria-label="t('settings.usersForm.closeAria')"
                            @click="closeModal"
                        >
                            ✕
                        </button>
                    </div>

                    <div class="flex-1 overflow-y-auto wa-scrollbar px-5 py-4 space-y-3">
                        <p
                            v-if="formError"
                            class="rounded-lg border px-3 py-2 text-sm"
                            :style="{
                                borderColor: 'color-mix(in srgb, #ef4444 40%, transparent)',
                                background: 'color-mix(in srgb, #ef4444 12%, transparent)',
                                color: '#fca5a5',
                            }"
                            role="alert"
                        >
                            {{ formError }}
                        </p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div class="sm:col-span-2">
                                <label class="block text-sm text-[var(--ui-text-secondary)] mb-1">{{ t('settings.usersForm.name') }}</label>
                                <input v-model="form.name" type="text" class="settings-input" autocomplete="name" />
                            </div>
                            <div>
                                <label class="block text-sm text-[var(--ui-text-secondary)] mb-1">
                                    Email <span class="text-[var(--ui-text-muted)]">{{ t('settings.usersForm.emailOptional') }}</span>
                                </label>
                                <input v-model="form.email" type="email" class="settings-input" autocomplete="email" />
                                <p class="mt-1 text-xs text-[var(--ui-text-secondary)]">
                                    {{ t('settings.usersForm.emailHint') }}
                                </p>
                            </div>
                            <div>
                                <label class="block text-sm text-[var(--ui-text-secondary)] mb-1">{{ t('settings.usersForm.phone') }}</label>
                                <input
                                    v-model="form.phone"
                                    type="tel"
                                    class="settings-input"
                                    placeholder="+7…"
                                    autocomplete="tel"
                                />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm text-[var(--ui-text-secondary)] mb-1">
                                    {{ t('settings.usersForm.password') }}
                                    <span v-if="!editingId" class="text-[var(--ui-text-muted)]">{{ t('settings.usersForm.passwordOrPin') }}</span>
                                    <span v-else class="text-[var(--ui-text-muted)]">{{ t('settings.usersForm.passwordLeaveEmpty') }}</span>
                                </label>
                                <input v-model="form.password" type="password" class="settings-input" autocomplete="new-password" />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm text-[var(--ui-text-secondary)] mb-1">
                                    {{ t('settings.usersForm.pinLabel') }}
                                </label>
                                <input
                                    v-model="form.pin"
                                    type="password"
                                    inputmode="numeric"
                                    pattern="[0-9]*"
                                    maxlength="6"
                                    class="settings-input font-mono tracking-widest"
                                    :placeholder="t('settings.usersForm.pinPlaceholder')"
                                    autocomplete="off"
                                />
                                <p class="mt-1 text-xs text-[var(--ui-text-secondary)]">
                                    <template v-if="editingId && editingHasPin">
                                        {{ t('settings.usersForm.pinSetHint') }}
                                    </template>
                                    <template v-else>
                                        {{ t('settings.usersForm.pinOptionalHint') }}
                                    </template>
                                </p>
                            </div>
                            <div>
                                <label class="block text-sm text-[var(--ui-text-secondary)] mb-1">{{ t('settings.usersForm.role') }}</label>
                                <select v-model="form.role" class="settings-input">
                                    <option v-for="r in availableRoles" :key="r" :value="r">{{ roleLabel(r) }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="pt-1">
                            <label class="block text-sm text-[var(--ui-text-secondary)] mb-1">{{ t('settings.usersForm.departments') }}</label>
                            <p class="text-xs text-[var(--ui-text-secondary)] mb-2">
                                {{ t('settings.usersForm.departmentsHint') }}
                            </p>
                            <div
                                v-if="departmentTree.length === 0"
                                class="text-xs text-[var(--ui-text-secondary)] italic"
                            >
                                {{ t('settings.usersForm.noDepartments') }}
                            </div>
                            <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-1 max-h-48 overflow-y-auto wa-scrollbar pr-1">
                                <label
                                    v-for="node in departmentTree"
                                    :key="node.dept.id"
                                    class="flex items-center gap-2 px-3 py-1.5 rounded-lg border cursor-pointer transition hover:brightness-95"
                                    :style="{
                                        background: form.department_ids.includes(node.dept.id) ? 'var(--ui-selected)' : 'var(--ui-bg)',
                                        borderColor: form.department_ids.includes(node.dept.id) ? 'var(--ui-accent)' : 'var(--ui-border-strong)',
                                        paddingLeft: `${0.75 + node.depth * 1}rem`,
                                    }"
                                >
                                    <UiCheckbox
                                        size="sm"
                                        :model-value="form.department_ids.includes(node.dept.id)"
                                        @update:model-value="toggleDepartment(node.dept.id)"
                                    />
                                    <span class="text-sm text-[var(--ui-text)] truncate">
                                        {{ node.dept.name }}<span
                                            v-if="node.dept.is_active === false"
                                            class="text-xs text-[var(--ui-text-secondary)]"
                                        >
                                            {{ t('settings.usersForm.inactive') }}
                                        </span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div class="pt-1">
                            <label class="block text-sm text-[var(--ui-text-secondary)] mb-1">{{ t('settings.usersForm.whatsappConnections') }}</label>
                            <p class="text-xs text-[var(--ui-text-secondary)] mb-2">
                                {{ t('settings.usersForm.whatsappHint') }}
                            </p>
                            <div
                                v-if="whatsappSessions.length === 0"
                                class="text-xs text-[var(--ui-text-secondary)] italic"
                            >
                                {{ t('settings.usersForm.noSessions') }}
                            </div>
                            <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-48 overflow-y-auto wa-scrollbar pr-1">
                                <label
                                    v-for="s in whatsappSessions"
                                    :key="s.id"
                                    class="flex items-center gap-3 px-3 py-2 rounded-lg border cursor-pointer transition hover:brightness-95"
                                    :style="{
                                        background: form.whatsapp_session_ids.includes(s.id) ? 'var(--ui-selected)' : 'var(--ui-bg)',
                                        borderColor: form.whatsapp_session_ids.includes(s.id) ? 'var(--ui-accent)' : 'var(--ui-border-strong)',
                                    }"
                                >
                                    <UiCheckbox
                                        size="sm"
                                        :model-value="form.whatsapp_session_ids.includes(s.id)"
                                        @update:model-value="toggleSession(s.id)"
                                    />
                                    <span
                                        class="w-2 h-2 rounded-full shrink-0"
                                        :style="{ background: sessionStatusColor(s.status) }"
                                        :title="s.status"
                                    ></span>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-medium text-[var(--ui-text)] truncate">
                                            {{ sessionLabel(s) }}
                                        </div>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <div v-if="editingId" class="flex items-center gap-2 pt-1">
                            <UiCheckbox id="user-active" v-model="form.is_active" size="sm" />
                            <label for="user-active" class="text-sm text-[var(--ui-text)] cursor-pointer">{{ t('settings.usersForm.activeLabel') }}</label>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 px-5 py-3 border-t shrink-0" :style="{ borderColor: 'var(--ui-border)' }">
                        <button
                            type="button"
                            class="px-4 py-2 text-sm rounded-lg text-[var(--ui-text-secondary)] hover:bg-[var(--ui-surface-hover)]"
                            @click="closeModal"
                        >
                            {{ t('common.cancel') }}
                        </button>
                        <button
                            type="button"
                            class="px-4 py-2 text-sm rounded-lg transition hover:brightness-95 disabled:opacity-50"
                            :style="{ background: 'var(--ui-accent)', color: '#fff' }"
                            :disabled="saving"
                            @click="save"
                        >
                            {{ saving ? t('settings.users.saving') : t('common.save') }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </SettingsLayout>

    <DangerConfirmModal
        :open="userDeleteDialogOpen"
        :title="t('settings.users.deleteTitle')"
        :description="userDeleteDescription"
        :confirm-label="t('common.delete')"
        :busy="userDeleting"
        confirm-variant="danger"
        @close="closeUserDeleteDialog"
        @confirm="confirmRemoveUser"
    />
</template>

