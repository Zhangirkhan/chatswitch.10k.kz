<script setup lang="ts">
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import { formatPhone } from '@/utils/phone';

interface SuperAdminCompanyUser {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    phones: string[];
    is_active: boolean;
    is_owner: boolean;
    department_id: number | null;
    department: { id: number; name: string } | null;
    departments: Array<{ id: number; name: string }>;
    roles: Array<{ name: string }>;
    whatsapp_sessions: Array<{ id: number; session_name: string; display_name: string | null; status: string }>;
    created_at: string | null;
}
import { useI18n } from '@/composables/useI18n';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const { t } = useI18n();

const props = defineProps<{
    companyId: number;
    users: SuperAdminCompanyUser[];
    departments: Array<{ id: number; name: string; parent_id: number | null; is_active: boolean }>;
    whatsappSessions: Array<{ id: number; session_name: string; display_name: string | null; status: string }>;
}>();

function roleLabel(role: string): string {
    if (role === 'administrator' || role === 'manager' || role === 'employee') {
        return t(`superAdmin.companies.users.roles.${role}`);
    }
    return role;
}

const filterRole = ref('');
const filterDepartmentId = ref<number | ''>('');
const filterStatus = ref('');

const editingId = ref<number | null>(null);

const userForm = useForm({
    name: '',
    email: '',
    password: '',
    role: 'employee' as 'administrator' | 'manager' | 'employee',
    department_ids: [] as number[],
});

const editForm = useForm({
    name: '',
    email: '',
    is_active: true,
    role: 'employee' as 'administrator' | 'manager' | 'employee',
    password: '',
    department_ids: [] as number[],
});

const filteredUsers = computed(() => {
    let list = [...props.users];

    if (filterRole.value) {
        list = list.filter((u) => u.roles.some((r) => r.name === filterRole.value));
    }

    if (filterDepartmentId.value !== '') {
        const deptId = Number(filterDepartmentId.value);
        list = list.filter(
            (u) =>
                u.department_id === deptId
                || u.departments.some((d) => d.id === deptId),
        );
    }

    if (filterStatus.value === 'active') {
        list = list.filter((u) => u.is_active);
    } else if (filterStatus.value === 'inactive') {
        list = list.filter((u) => !u.is_active);
    }

    return list;
});

const activeDepartments = computed(() => props.departments.filter((d) => d.is_active));

function userRoleLabel(user: SuperAdminCompanyUser): string {
    const name = user.roles?.[0]?.name;
    return name ? roleLabel(name) : t('superAdmin.common.emDash');
}

function departmentsLabel(user: SuperAdminCompanyUser): string {
    if (user.departments.length > 0) {
        return user.departments.map((d) => d.name).join(', ');
    }
    if (user.department?.name) {
        return user.department.name;
    }

    return t('superAdmin.common.emDash');
}

function phonesLabel(user: SuperAdminCompanyUser): string {
    const list = user.phones.length > 0 ? user.phones : user.phone ? [user.phone] : [];

    return list.map((p) => formatPhone(p) || p).join(', ') || t('superAdmin.common.emDash');
}

function whatsappLabel(user: SuperAdminCompanyUser): string {
    if (user.whatsapp_sessions.length === 0) {
        return t('superAdmin.common.emDash');
    }

    return user.whatsapp_sessions
        .map((s) => s.display_name || s.session_name)
        .join(', ');
}

function formatDateOnly(iso: string | null): string {
    if (!iso) return t('superAdmin.common.emDash');
    return new Date(iso).toLocaleDateString('ru-RU', { dateStyle: 'medium' });
}

function toggleDepartmentId(form: typeof userForm | typeof editForm, id: number): void {
    const idx = form.department_ids.indexOf(id);
    if (idx === -1) {
        form.department_ids.push(id);
    } else {
        form.department_ids.splice(idx, 1);
    }
}

function startEdit(user: SuperAdminCompanyUser): void {
    editingId.value = user.id;
    editForm.name = user.name;
    editForm.email = user.email ?? '';
    editForm.is_active = user.is_active;
    editForm.role = (user.roles?.[0]?.name as 'administrator' | 'manager' | 'employee') ?? 'employee';
    editForm.password = '';
    editForm.department_ids = user.departments.map((d) => d.id);
    if (editForm.department_ids.length === 0 && user.department_id) {
        editForm.department_ids = [user.department_id];
    }
    editForm.clearErrors();
}

function saveEdit(userId: number): void {
    editForm.put(`/companies/${props.companyId}/users/${userId}`, {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null;
        },
    });
}

function resetPassword(user: SuperAdminCompanyUser): void {
    const label = user.email || user.name;
    if (!confirm(t('superAdmin.companies.users.resetPasswordConfirm', { label }))) return;
    router.post(`/companies/${props.companyId}/users/${user.id}/reset-password`, {}, { preserveScroll: true });
}

function deactivate(user: SuperAdminCompanyUser): void {
    if (!confirm(t('superAdmin.companies.users.deactivateConfirm', { name: user.name }))) return;
    router.put(`/companies/${props.companyId}/users/${user.id}`, {
        name: user.name,
        email: user.email,
        is_active: false,
        role: user.roles?.[0]?.name ?? 'employee',
        department_ids: user.departments.map((d) => d.id),
    }, { preserveScroll: true });
}

const assigningOwnerId = ref<number | null>(null);

function isAdministrator(user: SuperAdminCompanyUser): boolean {
    return user.roles.some((r) => r.name === 'administrator');
}

function assignOwner(user: SuperAdminCompanyUser): void {
    if (user.is_owner || assigningOwnerId.value !== null) {
        return;
    }

    assigningOwnerId.value = user.id;
    router.patch(
        `/companies/${props.companyId}/owner`,
        { user_id: user.id },
        {
            preserveScroll: true,
            onFinish: () => {
                assigningOwnerId.value = null;
            },
        },
    );
}

function formErrorMessage(form: typeof userForm | typeof editForm): string {
    const values = Object.values(form.errors);
    return values.find((v) => typeof v === 'string' && v.length > 0) ?? '';
}
</script>

<template>
    <div class="space-y-6">
        <section v-if="departments.length > 0" class="ui-panel px-4 py-3">
            <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold text-ui-text">{{ t('superAdmin.companies.users.departmentsTitle') }}</h2>
                <span class="text-xs text-ui-text-muted">{{ departments.length }}</span>
            </div>
            <div class="flex flex-wrap gap-2">
                <span
                    v-for="d in departments"
                    :key="d.id"
                    class="ui-badge"
                    :class="d.is_active ? 'ui-badge--neutral' : 'ui-badge--neutral opacity-60'"
                >
                    {{ d.name }}
                    <span v-if="!d.is_active" class="text-ui-text-muted">{{ t('superAdmin.companies.users.departmentsInactive') }}</span>
                </span>
            </div>
        </section>

        <div class="ui-panel company-users-table overflow-hidden p-0">
            <div class="company-users-toolbar">
                <h2 class="company-users-toolbar__title">
                    {{ t('superAdmin.companies.users.title') }}
                    <span class="company-users-toolbar__count">{{ filteredUsers.length }}</span>
                    <span v-if="filteredUsers.length !== users.length" class="company-users-toolbar__count-muted">
                        / {{ users.length }}
                    </span>
                </h2>
                <div class="company-users-toolbar__filters" role="group" :aria-label="t('superAdmin.companies.users.filtersAriaLabel')">
                    <label class="company-users-toolbar__filter">
                        <span class="company-users-toolbar__filter-label">{{ t('superAdmin.companies.users.filterRole') }}</span>
                        <select v-model="filterRole" class="ui-select">
                            <option value="">{{ t('superAdmin.companies.users.filterAllRoles') }}</option>
                            <option value="administrator">{{ t('superAdmin.companies.users.roles.administrator') }}</option>
                            <option value="manager">{{ t('superAdmin.companies.users.roles.manager') }}</option>
                            <option value="employee">{{ t('superAdmin.companies.users.roles.employee') }}</option>
                        </select>
                    </label>
                    <label class="company-users-toolbar__filter">
                        <span class="company-users-toolbar__filter-label">{{ t('superAdmin.companies.users.filterDepartment') }}</span>
                        <select v-model="filterDepartmentId" class="ui-select">
                            <option value="">{{ t('superAdmin.companies.users.filterAllDepartments') }}</option>
                            <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                        </select>
                    </label>
                    <label class="company-users-toolbar__filter">
                        <span class="company-users-toolbar__filter-label">{{ t('superAdmin.companies.users.filterStatus') }}</span>
                        <select v-model="filterStatus" class="ui-select">
                            <option value="">{{ t('superAdmin.companies.users.filterAllStatuses') }}</option>
                            <option value="active">{{ t('superAdmin.companies.users.filterActive') }}</option>
                            <option value="inactive">{{ t('superAdmin.companies.users.filterInactive') }}</option>
                        </select>
                    </label>
                </div>
            </div>

            <div class="ui-table-panel">
                <table>
                    <thead>
                        <tr>
                            <th>{{ t('superAdmin.companies.users.tableUser') }}</th>
                            <th>Email</th>
                            <th>{{ t('superAdmin.companies.users.tablePhone') }}</th>
                            <th>{{ t('superAdmin.companies.users.tableRole') }}</th>
                            <th>{{ t('superAdmin.companies.users.tableDepartments') }}</th>
                            <th>WhatsApp</th>
                            <th>{{ t('superAdmin.companies.users.tableStatus') }}</th>
                            <th class="text-right">{{ t('superAdmin.common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="u in filteredUsers" :key="u.id">
                            <tr v-if="editingId !== u.id">
                                <td class="!text-ui-text">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium">{{ u.name }}</span>
                                        <span v-if="u.is_owner" class="ui-badge ui-badge--neutral text-[0.6875rem] text-ui-accent">
                                            {{ t('superAdmin.companies.users.ownerBadge') }}
                                        </span>
                                    </div>
                                </td>
                                <td>{{ u.email || t('superAdmin.common.emDash') }}</td>
                                <td class="whitespace-nowrap tabular-nums">{{ phonesLabel(u) }}</td>
                                <td>
                                    <span class="ui-badge ui-badge--neutral">{{ userRoleLabel(u) }}</span>
                                </td>
                                <td class="max-w-[11rem]">
                                    <span class="line-clamp-2" :title="departmentsLabel(u)">{{ departmentsLabel(u) }}</span>
                                </td>
                                <td class="max-w-[9rem]">
                                    <span class="block truncate" :title="whatsappLabel(u)">{{ whatsappLabel(u) }}</span>
                                </td>
                                <td>
                                    <div class="flex flex-col gap-0.5 items-start">
                                        <span class="ui-badge" :class="u.is_active ? 'ui-badge--success' : 'ui-badge--neutral'">
                                            {{ u.is_active ? t('superAdmin.companies.users.statusActive') : t('superAdmin.companies.users.statusInactive') }}
                                        </span>
                                        <span class="text-xs text-ui-text-muted whitespace-nowrap">
                                            {{ t('superAdmin.companies.users.since', { date: formatDateOnly(u.created_at) }) }}
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="company-users-actions">
                                        <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="startEdit(u)">
                                            {{ t('superAdmin.companies.users.edit') }}
                                        </button>
                                        <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="resetPassword(u)">
                                            {{ t('superAdmin.companies.users.password') }}
                                        </button>
                                        <button
                                            v-if="u.is_active && isAdministrator(u) && !u.is_owner"
                                            type="button"
                                            class="ui-btn ui-btn--ghost ui-btn--sm"
                                            :disabled="assigningOwnerId === u.id"
                                            @click="assignOwner(u)"
                                        >
                                            {{ assigningOwnerId === u.id ? t('superAdmin.companies.users.assigningOwner') : t('superAdmin.companies.users.assignOwner') }}
                                        </button>
                                        <button
                                            v-if="u.is_active"
                                            type="button"
                                            class="ui-btn ui-btn--danger-ghost ui-btn--sm"
                                            @click="deactivate(u)"
                                        >
                                            {{ t('superAdmin.companies.users.deactivate') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-else class="company-users-table__edit-row">
                                <td colspan="8">
                                    <form class="max-w-lg space-y-3" @submit.prevent="saveEdit(u.id)">
                                        <div class="grid gap-3 sm:grid-cols-2">
                                            <input v-model="editForm.name" class="ui-input" required />
                                            <input
                                                v-model="editForm.email"
                                                type="email"
                                                class="ui-input"
                                                :placeholder="t('superAdmin.companies.users.emailOptional')"
                                            />
                                        </div>
                                        <select v-model="editForm.role" class="ui-select w-full">
                                            <option value="administrator">{{ t('superAdmin.companies.users.roles.administrator') }}</option>
                                            <option value="manager">{{ t('superAdmin.companies.users.roles.manager') }}</option>
                                            <option value="employee">{{ t('superAdmin.companies.users.roles.employee') }}</option>
                                        </select>
                                        <div v-if="activeDepartments.length > 0" class="space-y-1">
                                            <span class="text-xs text-ui-text-secondary">{{ t('superAdmin.companies.users.departmentsTitle') }}</span>
                                            <div class="flex flex-wrap gap-3">
                                                <label
                                                    v-for="d in activeDepartments"
                                                    :key="d.id"
                                                    class="flex items-center gap-2 text-sm"
                                                >
                                                    <UiCheckbox
                                                        :model-value="editForm.department_ids.includes(d.id)"
                                                        size="sm"
                                                        @update:model-value="toggleDepartmentId(editForm, d.id)"
                                                    />
                                                    {{ d.name }}
                                                </label>
                                            </div>
                                        </div>
                                        <label class="flex items-center gap-2 text-sm">
                                            <UiCheckbox v-model="editForm.is_active" size="sm" />
                                            {{ t('superAdmin.companies.users.activeCheckbox') }}
                                        </label>
                                        <input v-model="editForm.password" type="password" class="ui-input" :placeholder="t('superAdmin.companies.users.newPasswordOptional')" />
                                        <div class="flex gap-2">
                                            <button type="submit" class="ui-btn ui-btn--primary ui-btn--sm" :disabled="editForm.processing">
                                                {{ t('superAdmin.common.save') }}
                                            </button>
                                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="editingId = null">
                                                {{ t('superAdmin.common.cancel') }}
                                            </button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <p v-if="users.length === 0" class="company-users-table__empty">{{ t('superAdmin.companies.users.empty') }}</p>
            <p v-else-if="filteredUsers.length === 0" class="company-users-table__empty">
                {{ t('superAdmin.companies.users.emptyFiltered') }}
            </p>
        </div>

        <section class="ui-settings-section max-w-xl">
            <h2 class="mb-3 text-base font-semibold">{{ t('superAdmin.companies.users.addTitle') }}</h2>
            <p v-if="formErrorMessage(userForm)" class="mb-3 text-sm text-ui-danger">{{ formErrorMessage(userForm) }}</p>
            <form
                class="space-y-3"
                @submit.prevent="userForm.post(`/companies/${companyId}/users`, { preserveScroll: true, onSuccess: () => userForm.reset() })"
            >
                <input v-model="userForm.name" :placeholder="t('superAdmin.companies.users.addNamePlaceholder')" class="ui-input" required />
                <input
                    v-model="userForm.email"
                    type="email"
                    :placeholder="userForm.role === 'administrator' ? 'Email (обязателен для администратора)' : t('superAdmin.companies.users.emailOptional')"
                    class="ui-input"
                    :required="userForm.role === 'administrator'"
                />
                <input v-model="userForm.password" type="password" :placeholder="t('superAdmin.companies.users.addPasswordPlaceholder')" class="ui-input" required />
                <select v-model="userForm.role" class="ui-select w-full">
                    <option value="administrator">{{ t('superAdmin.companies.users.roles.administrator') }}</option>
                    <option value="manager">{{ t('superAdmin.companies.users.roles.manager') }}</option>
                    <option value="employee">{{ t('superAdmin.companies.users.roles.employee') }}</option>
                </select>
                <div v-if="activeDepartments.length > 0" class="space-y-1">
                    <span class="text-xs text-ui-text-secondary">{{ t('superAdmin.companies.users.departmentsTitle') }}</span>
                    <div class="flex flex-wrap gap-3">
                        <label v-for="d in activeDepartments" :key="d.id" class="flex items-center gap-2 text-sm">
                            <UiCheckbox
                                :model-value="userForm.department_ids.includes(d.id)"
                                size="sm"
                                @update:model-value="toggleDepartmentId(userForm, d.id)"
                            />
                            {{ d.name }}
                        </label>
                    </div>
                </div>
                <button type="submit" class="ui-btn ui-btn--primary ui-btn--sm" :disabled="userForm.processing">{{ t('superAdmin.companies.users.addSubmit') }}</button>
            </form>
        </section>
    </div>
</template>

<style scoped>
.company-users-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: 12px 20px;
    padding: 14px 20px;
    border-bottom: 1px solid var(--ui-border);
    background: var(--ui-surface-muted);
}

.company-users-toolbar__title {
    margin: 0;
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--ui-text);
    line-height: 1.3;
}

.company-users-toolbar__count {
    margin-left: 0.35rem;
    font-variant-numeric: tabular-nums;
    color: var(--ui-accent);
}

.company-users-toolbar__count-muted {
    font-weight: 500;
    color: var(--ui-text-muted);
}

.company-users-toolbar__filters {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 10px 12px;
    margin-left: auto;
}

.company-users-toolbar__filter {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 9.5rem;
    width: 10.5rem;
}

.company-users-toolbar__filter-label {
    font-size: 0.6875rem;
    font-weight: 500;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    color: var(--ui-text-muted);
}

.company-users-toolbar__filter .ui-select {
    width: 100%;
    min-height: 2.25rem;
}

.company-users-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 4px;
}

.company-users-table :deep(th:last-child),
.company-users-table :deep(td:last-child) {
    width: 1%;
    white-space: nowrap;
}

.company-users-table__edit-row :deep(td) {
    background: color-mix(in srgb, var(--ui-surface-muted) 55%, transparent);
    padding: 16px 20px;
}

.company-users-table__empty {
    margin: 0;
    padding: 2rem 1.25rem;
    text-align: center;
    font-size: 0.875rem;
    color: var(--ui-text-muted);
}

@media (max-width: 767px) {
    .company-users-toolbar__filters {
        width: 100%;
        margin-left: 0;
    }

    .company-users-toolbar__filter {
        flex: 1 1 calc(50% - 6px);
        min-width: 0;
        width: auto;
    }
}
</style>
