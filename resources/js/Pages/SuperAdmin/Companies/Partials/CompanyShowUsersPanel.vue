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
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    companyId: number;
    users: SuperAdminCompanyUser[];
    departments: Array<{ id: number; name: string; parent_id: number | null; is_active: boolean }>;
    whatsappSessions: Array<{ id: number; session_name: string; display_name: string | null; status: string }>;
}>();

const roleLabels: Record<string, string> = {
    administrator: 'Администратор',
    manager: 'Менеджер',
    employee: 'Сотрудник',
};

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
    return name ? (roleLabels[name] ?? name) : '—';
}

function departmentsLabel(user: SuperAdminCompanyUser): string {
    if (user.departments.length > 0) {
        return user.departments.map((d) => d.name).join(', ');
    }
    if (user.department?.name) {
        return user.department.name;
    }

    return '—';
}

function phonesLabel(user: SuperAdminCompanyUser): string {
    const list = user.phones.length > 0 ? user.phones : user.phone ? [user.phone] : [];

    return list.map((p) => formatPhone(p) || p).join(', ') || '—';
}

function whatsappLabel(user: SuperAdminCompanyUser): string {
    if (user.whatsapp_sessions.length === 0) {
        return '—';
    }

    return user.whatsapp_sessions
        .map((s) => s.display_name || s.session_name)
        .join(', ');
}

function formatDateOnly(iso: string | null): string {
    if (!iso) return '—';
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
    if (!confirm(`Сгенерировать новый пароль для ${label}?`)) return;
    router.post(`/companies/${props.companyId}/users/${user.id}/reset-password`, {}, { preserveScroll: true });
}

function deactivate(user: SuperAdminCompanyUser): void {
    if (!confirm(`Деактивировать ${user.name}?`)) return;
    router.put(`/companies/${props.companyId}/users/${user.id}`, {
        name: user.name,
        email: user.email,
        is_active: false,
        role: user.roles?.[0]?.name ?? 'employee',
        department_ids: user.departments.map((d) => d.id),
    }, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-6">
        <section v-if="departments.length > 0" class="ui-panel px-4 py-3">
            <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold text-ui-text">Отделы</h2>
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
                    <span v-if="!d.is_active" class="text-ui-text-muted"> · выкл</span>
                </span>
            </div>
        </section>

        <div class="ui-panel company-users-table overflow-hidden p-0">
            <div class="company-users-toolbar">
                <h2 class="company-users-toolbar__title">
                    Пользователи
                    <span class="company-users-toolbar__count">{{ filteredUsers.length }}</span>
                    <span v-if="filteredUsers.length !== users.length" class="company-users-toolbar__count-muted">
                        / {{ users.length }}
                    </span>
                </h2>
                <div class="company-users-toolbar__filters" role="group" aria-label="Фильтры пользователей">
                    <label class="company-users-toolbar__filter">
                        <span class="company-users-toolbar__filter-label">Роль</span>
                        <select v-model="filterRole" class="ui-select">
                            <option value="">Все роли</option>
                            <option value="administrator">Администратор</option>
                            <option value="manager">Менеджер</option>
                            <option value="employee">Сотрудник</option>
                        </select>
                    </label>
                    <label class="company-users-toolbar__filter">
                        <span class="company-users-toolbar__filter-label">Отдел</span>
                        <select v-model="filterDepartmentId" class="ui-select">
                            <option value="">Все отделы</option>
                            <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                        </select>
                    </label>
                    <label class="company-users-toolbar__filter">
                        <span class="company-users-toolbar__filter-label">Статус</span>
                        <select v-model="filterStatus" class="ui-select">
                            <option value="">Все статусы</option>
                            <option value="active">Активные</option>
                            <option value="inactive">Выключенные</option>
                        </select>
                    </label>
                </div>
            </div>

            <div class="ui-table-panel">
                <table>
                    <thead>
                        <tr>
                            <th>Пользователь</th>
                            <th>Email</th>
                            <th>Телефон</th>
                            <th>Роль</th>
                            <th>Отделы</th>
                            <th>WhatsApp</th>
                            <th>Статус</th>
                            <th class="text-right">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="u in filteredUsers" :key="u.id">
                            <tr v-if="editingId !== u.id">
                                <td class="!text-ui-text">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium">{{ u.name }}</span>
                                        <span v-if="u.is_owner" class="ui-badge ui-badge--neutral text-[0.6875rem] text-ui-accent">
                                            Владелец
                                        </span>
                                    </div>
                                </td>
                                <td>{{ u.email || '—' }}</td>
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
                                            {{ u.is_active ? 'активен' : 'выкл' }}
                                        </span>
                                        <span class="text-xs text-ui-text-muted whitespace-nowrap">
                                            с {{ formatDateOnly(u.created_at) }}
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="company-users-actions">
                                        <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="startEdit(u)">
                                            Изменить
                                        </button>
                                        <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="resetPassword(u)">
                                            Пароль
                                        </button>
                                        <button
                                            v-if="u.is_active"
                                            type="button"
                                            class="ui-btn ui-btn--danger-ghost ui-btn--sm"
                                            @click="deactivate(u)"
                                        >
                                            Выкл
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
                                                placeholder="Email (необязательно)"
                                            />
                                        </div>
                                        <select v-model="editForm.role" class="ui-select w-full">
                                            <option value="administrator">Администратор</option>
                                            <option value="manager">Менеджер</option>
                                            <option value="employee">Сотрудник</option>
                                        </select>
                                        <div v-if="activeDepartments.length > 0" class="space-y-1">
                                            <span class="text-xs text-ui-text-secondary">Отделы</span>
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
                                            Активен
                                        </label>
                                        <input v-model="editForm.password" type="password" class="ui-input" placeholder="Новый пароль (необязательно)" />
                                        <div class="flex gap-2">
                                            <button type="submit" class="ui-btn ui-btn--primary ui-btn--sm" :disabled="editForm.processing">
                                                Сохранить
                                            </button>
                                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="editingId = null">
                                                Отмена
                                            </button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <p v-if="users.length === 0" class="company-users-table__empty">Нет пользователей</p>
            <p v-else-if="filteredUsers.length === 0" class="company-users-table__empty">
                Нет пользователей по выбранным фильтрам
            </p>
        </div>

        <section class="ui-settings-section max-w-xl">
            <h2 class="mb-3 text-base font-semibold">Добавить пользователя</h2>
            <form
                class="space-y-3"
                @submit.prevent="userForm.post(`/companies/${companyId}/users`, { preserveScroll: true, onSuccess: () => userForm.reset() })"
            >
                <input v-model="userForm.name" placeholder="Имя" class="ui-input" required />
                <input
                    v-model="userForm.email"
                    type="email"
                    placeholder="Email (необязательно)"
                    class="ui-input"
                />
                <input v-model="userForm.password" type="password" placeholder="Пароль" class="ui-input" required />
                <select v-model="userForm.role" class="ui-select w-full">
                    <option value="administrator">Администратор</option>
                    <option value="manager">Менеджер</option>
                    <option value="employee">Сотрудник</option>
                </select>
                <div v-if="activeDepartments.length > 0" class="space-y-1">
                    <span class="text-xs text-ui-text-secondary">Отделы</span>
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
                <button type="submit" class="ui-btn ui-btn--primary ui-btn--sm" :disabled="userForm.processing">Добавить</button>
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
