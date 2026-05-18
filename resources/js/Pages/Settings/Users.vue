<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
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

const local = ref<User[]>([...props.users.data]);
const filters = ref<UserFilters>({
    search: props.filters.search || '',
    role: props.filters.role || '',
    department_id: props.filters.department_id || null,
    status: props.filters.status || '',
});
const showForm = ref(false);
const editingId = ref<number | null>(null);
const form = ref({
    name: '',
    email: '',
    phone: '',
    password: '',
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

const roleLabels: Record<string, string> = {
    administrator: 'Администратор',
    manager: 'Руководитель',
    employee: 'Сотрудник',
};

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
    form.value = {
        name: '',
        email: '',
        phone: '',
        password: '',
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
    const primaryPhone = (user.phone || user.phones?.[0] || '').trim();
    const deptIds = user.department_ids && user.department_ids.length
        ? [...user.department_ids]
        : (user.department_id != null ? [user.department_id] : []);
    form.value = {
        name: user.name,
        email: user.email,
        phone: primaryPhone,
        password: '',
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

function userPrimaryPhone(user: User): string {
    const p = (user.phone || user.phones?.[0] || '').trim();
    return p !== '' ? p : '—';
}

function buildPayload(): Record<string, unknown> {
    const deptIds = [...new Set(form.value.department_ids.map((x) => Number(x)).filter((x) => Number.isFinite(x) && x > 0))];

    const phoneTrim = form.value.phone.trim();
    const payload: Record<string, unknown> = {
        name: form.value.name.trim(),
        email: form.value.email.trim(),
        phone: phoneTrim !== '' ? phoneTrim : null,
        phones: phoneTrim !== '' ? [phoneTrim] : [],
        role: form.value.role,
        company_id: form.value.company_id,
        department_ids: deptIds,
        whatsapp_session_ids: form.value.whatsapp_session_ids,
    };

    if (editingId.value) {
        payload.is_active = form.value.is_active;
        if (form.value.password.trim()) {
            payload.password = form.value.password;
        }
    } else {
        payload.password = form.value.password;
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
    return data?.message || 'Ошибка сохранения';
}

async function save() {
    if (!form.value.name.trim() || !form.value.email.trim()) return;
    if (!editingId.value && !form.value.password.trim()) {
        showToast({ message: 'Укажите пароль для нового пользователя', duration: 4000 });
        return;
    }
    try {
        const payload = buildPayload();
        if (editingId.value) {
            await axios.put(route('settings.users.update', editingId.value), payload);
            showToast({ message: 'Данные пользователя сохранены', duration: 3000 });
        } else {
            await axios.post(route('settings.users.store'), payload);
            showToast({ message: 'Пользователь создан', duration: 3000 });
        }
        showForm.value = false;
        await router.reload({ only: ['users'] });
    } catch (err: unknown) {
        showToast({ message: validationMessage(err), duration: 6000 });
    }
}

async function remove(user: User) {
    if (!confirm(`Удалить пользователя "${user.name}"?`)) return;
    try {
        await axios.delete(route('settings.users.destroy', user.id));
        showToast({ message: 'Пользователь удалён', duration: 3000 });
        await router.reload({ only: ['users'] });
    } catch (err: unknown) {
        showToast({ message: validationMessage(err), duration: 6000 });
    }
}
</script>

<template>
    <Head title="Пользователи" />
    <SettingsLayout title="Пользователи" subtitle="Операторы, руководители и администраторы">
        <template #actions>
            <button
                @click="openAdd"
                class="px-4 py-2 text-sm rounded-lg transition hover:brightness-95"
                :style="{ background: 'var(--wa-accent)', color: '#fff' }"
            >
                + Добавить пользователя
            </button>
        </template>

        <div class="w-full px-6 py-6 space-y-4">
            <p class="text-sm text-[var(--wa-text-secondary)] max-w-3xl">
                Нажмите «Изменить» у пользователя в таблице или «+ Добавить пользователя», чтобы открыть форму.
            </p>

            <div
                class="rounded-lg border p-4 grid grid-cols-1 gap-3 lg:grid-cols-[minmax(220px,1fr)_180px_220px_160px_auto]"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <input
                    v-model="filters.search"
                    type="search"
                    class="settings-input"
                    placeholder="Поиск по имени, email, телефону, отделу, роли"
                />
                <select v-model="filters.role" class="settings-input">
                    <option value="">Все роли</option>
                    <option v-for="role in availableRoles" :key="role" :value="role">
                        {{ roleLabels[role] || role }}
                    </option>
                </select>
                <select v-model="filters.department_id" class="settings-input">
                    <option :value="null">Все отделы</option>
                    <option v-for="node in departmentTree" :key="node.dept.id" :value="node.dept.id">
                        {{ `${'— '.repeat(node.depth)}${node.dept.name}` }}
                    </option>
                </select>
                <select v-model="filters.status" class="settings-input">
                    <option value="">Любой статус</option>
                    <option value="active">Активные</option>
                    <option value="inactive">Отключённые</option>
                </select>
                <button
                    type="button"
                    class="px-4 py-2 text-sm rounded-lg transition hover:brightness-95"
                    :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-text)' }"
                    @click="resetFilters"
                >
                    Сбросить
                </button>
            </div>

            <div class="flex items-center justify-between gap-3 text-xs text-[var(--wa-text-secondary)]">
                <span>Показано {{ props.users.from || 0 }}–{{ props.users.to || 0 }} из {{ props.users.total }}</span>
                <span>Страница {{ props.users.current_page }} из {{ props.users.last_page }}</span>
            </div>

            <!-- Users table: действия сразу после имени, чтобы кнопки не уезжали за край экрана -->
            <div
                class="rounded-lg border overflow-x-auto"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <table class="w-full text-sm min-w-[720px]">
                    <thead :style="{ background: 'var(--wa-panel-header)' }">
                        <tr>
                            <th class="text-left px-5 py-3 font-medium text-[var(--wa-text-secondary)]">Имя</th>
                            <th class="text-right px-5 py-3 font-medium text-[var(--wa-text-secondary)] whitespace-nowrap w-[1%]">
                                Действия
                            </th>
                            <th class="text-left px-5 py-3 font-medium text-[var(--wa-text-secondary)]">Email</th>
                            <th class="text-left px-5 py-3 font-medium text-[var(--wa-text-secondary)]">Телефон</th>
                            <th class="text-left px-5 py-3 font-medium text-[var(--wa-text-secondary)]">Роль</th>
                            <th class="text-left px-5 py-3 font-medium text-[var(--wa-text-secondary)]">Отделы</th>
                            <th class="text-left px-5 py-3 font-medium text-[var(--wa-text-secondary)]">WhatsApp</th>
                            <th class="text-left px-5 py-3 font-medium text-[var(--wa-text-secondary)]">Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="local.length === 0">
                            <td colspan="8" class="px-5 py-10 text-center text-[var(--wa-text-secondary)]">
                                Нет пользователей
                            </td>
                        </tr>
                        <tr
                            v-for="user in local"
                            :key="user.id"
                            class="border-t transition-colors hover:bg-[color-mix(in_srgb,var(--wa-panel-hover)_65%,transparent)] cursor-pointer"
                            :style="{ borderColor: 'var(--wa-border)' }"
                            title="Нажмите строку, чтобы редактировать"
                            @click="openEdit(user)"
                        >
                            <td class="px-5 py-3 font-medium text-[var(--wa-text)]">{{ user.name }}</td>
                            <td class="px-5 py-3 text-right whitespace-nowrap align-middle" @click.stop>
                                <button
                                    type="button"
                                    class="text-xs px-2 py-1 rounded-md border mr-2 transition hover:brightness-95"
                                    :style="{ color: 'var(--wa-accent)', borderColor: 'var(--wa-border-strong)' }"
                                    @click="openEdit(user)"
                                >
                                    Изменить
                                </button>
                                <button
                                    type="button"
                                    class="text-xs px-2 py-1 rounded-md border border-red-500/40 text-red-400 transition hover:bg-red-500/10"
                                    @click="remove(user)"
                                >
                                    Удалить
                                </button>
                            </td>
                            <td class="px-5 py-3 text-[var(--wa-text-secondary)]">{{ user.email }}</td>
                            <td class="px-5 py-3 text-[var(--wa-text-secondary)] text-xs font-mono">{{ userPrimaryPhone(user) }}</td>
                            <td class="px-5 py-3">
                                <span
                                    class="text-xs px-2 py-0.5 rounded-full font-medium"
                                    :class="{
                                        'bg-red-100 text-red-700': user.roles?.[0] === 'administrator',
                                        'bg-blue-100 text-blue-700': user.roles?.[0] === 'manager',
                                        'bg-gray-100 text-gray-700': user.roles?.[0] === 'employee',
                                    }"
                                >
                                    {{ roleLabels[user.roles?.[0]] || user.roles?.[0] || '—' }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <div v-if="userDepartmentNames(user).length > 0" class="flex flex-wrap gap-1">
                                    <span
                                        v-for="name in userDepartmentNames(user)"
                                        :key="name"
                                        class="text-xs px-2 py-0.5 rounded-full"
                                        :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-text)' }"
                                    >
                                        {{ name }}
                                    </span>
                                </div>
                                <span v-else class="text-xs text-[var(--wa-text-secondary)]">—</span>
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
                                        :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-text)' }"
                                        :title="sessionLabel(s)"
                                    >
                                        <span
                                            class="w-1.5 h-1.5 rounded-full"
                                            :style="{ background: sessionStatusColor(s.status) }"
                                        ></span>
                                        {{ sessionLabel(s) }}
                                    </span>
                                </div>
                                <span v-else class="text-xs text-[var(--wa-text-secondary)]">—</span>
                            </td>
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center gap-2">
                                    <span
                                        class="w-2 h-2 rounded-full"
                                        :class="user.is_active ? 'bg-[var(--wa-accent)]' : 'bg-red-400'"
                                    ></span>
                                    <span class="text-xs text-[var(--wa-text-secondary)]">
                                        {{ user.is_active ? 'Активен' : 'Отключён' }}
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
                    :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-text)' }"
                    :disabled="props.users.current_page <= 1"
                    @click="goToPage(props.users.current_page - 1)"
                >
                    Назад
                </button>
                <div class="text-sm text-[var(--wa-text-secondary)]">
                    {{ props.users.current_page }} / {{ props.users.last_page }}
                </div>
                <button
                    type="button"
                    class="rounded-lg px-3 py-2 text-sm disabled:opacity-40"
                    :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-text)' }"
                    :disabled="props.users.current_page >= props.users.last_page"
                    @click="goToPage(props.users.current_page + 1)"
                >
                    Вперёд
                </button>
            </div>
        </div>

        <Teleport to="body">
            <div
                v-if="showForm"
                class="fixed inset-0 z-[2000] flex items-center justify-center p-4 bg-black/60"
                role="dialog"
                aria-modal="true"
                :aria-label="editingId ? 'Редактирование пользователя' : 'Новый пользователь'"
                @click.self="closeModal"
            >
                <div
                    class="w-full max-w-xl max-h-[min(90vh,800px)] overflow-hidden flex flex-col rounded-xl border shadow-2xl"
                    :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border-strong)' }"
                    @click.stop
                >
                    <div class="px-5 py-4 border-b shrink-0 flex items-center justify-between gap-3" :style="{ borderColor: 'var(--wa-border)' }">
                        <h3 class="text-base font-medium text-[var(--wa-text)]">
                            {{ editingId ? 'Редактировать пользователя' : 'Новый пользователь' }}
                        </h3>
                        <button
                            type="button"
                            class="text-sm text-[var(--wa-text-secondary)] hover:text-[var(--wa-text)] px-2 py-1 rounded"
                            aria-label="Закрыть"
                            @click="closeModal"
                        >
                            ✕
                        </button>
                    </div>

                    <div class="flex-1 overflow-y-auto wa-scrollbar px-5 py-4 space-y-3">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div class="sm:col-span-2">
                                <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Имя</label>
                                <input v-model="form.name" type="text" class="settings-input" autocomplete="name" />
                            </div>
                            <div>
                                <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Email</label>
                                <input v-model="form.email" type="email" class="settings-input" autocomplete="email" />
                            </div>
                            <div>
                                <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Телефон</label>
                                <input
                                    v-model="form.phone"
                                    type="tel"
                                    class="settings-input"
                                    placeholder="+7…"
                                    autocomplete="tel"
                                />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">
                                    Пароль {{ editingId ? '(оставьте пустым, если не меняете)' : '' }}
                                </label>
                                <input v-model="form.password" type="password" class="settings-input" autocomplete="new-password" />
                            </div>
                            <div>
                                <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Роль</label>
                                <select v-model="form.role" class="settings-input">
                                    <option v-for="r in availableRoles" :key="r" :value="r">{{ roleLabels[r] || r }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="pt-1">
                            <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Отделы</label>
                            <p class="text-xs text-[var(--wa-text-secondary)] mb-2">
                                Сотрудник может состоять в нескольких отделах одновременно — отметьте все нужные.
                            </p>
                            <div
                                v-if="departmentTree.length === 0"
                                class="text-xs text-[var(--wa-text-secondary)] italic"
                            >
                                Отделов пока нет. Создайте их в разделе «Отделы».
                            </div>
                            <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-1 max-h-48 overflow-y-auto wa-scrollbar pr-1">
                                <label
                                    v-for="node in departmentTree"
                                    :key="node.dept.id"
                                    class="flex items-center gap-2 px-3 py-1.5 rounded-lg border cursor-pointer transition hover:brightness-95"
                                    :style="{
                                        background: form.department_ids.includes(node.dept.id) ? 'var(--wa-selected)' : 'var(--wa-bg)',
                                        borderColor: form.department_ids.includes(node.dept.id) ? 'var(--wa-accent)' : 'var(--wa-border-strong)',
                                        paddingLeft: `${0.75 + node.depth * 1}rem`,
                                    }"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="form.department_ids.includes(node.dept.id)"
                                        class="w-4 h-4 rounded shrink-0"
                                        @change="toggleDepartment(node.dept.id)"
                                    />
                                    <span class="text-sm text-[var(--wa-text)] truncate">
                                        {{ node.dept.name }}<span
                                            v-if="node.dept.is_active === false"
                                            class="text-xs text-[var(--wa-text-secondary)]"
                                        >
                                            (неактивен)
                                        </span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div class="pt-1">
                            <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Подключения WhatsApp</label>
                            <p class="text-xs text-[var(--wa-text-secondary)] mb-2">
                                Названия из раздела «Подключения». Номер аккаунта WhatsApp здесь не показывается.
                            </p>
                            <div
                                v-if="whatsappSessions.length === 0"
                                class="text-xs text-[var(--wa-text-secondary)] italic"
                            >
                                Пока нет сессий. Добавьте их в «Подключения».
                            </div>
                            <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-48 overflow-y-auto wa-scrollbar pr-1">
                                <label
                                    v-for="s in whatsappSessions"
                                    :key="s.id"
                                    class="flex items-center gap-3 px-3 py-2 rounded-lg border cursor-pointer transition hover:brightness-95"
                                    :style="{
                                        background: form.whatsapp_session_ids.includes(s.id) ? 'var(--wa-selected)' : 'var(--wa-bg)',
                                        borderColor: form.whatsapp_session_ids.includes(s.id) ? 'var(--wa-accent)' : 'var(--wa-border-strong)',
                                    }"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="form.whatsapp_session_ids.includes(s.id)"
                                        class="w-4 h-4 rounded shrink-0"
                                        @change="toggleSession(s.id)"
                                    />
                                    <span
                                        class="w-2 h-2 rounded-full shrink-0"
                                        :style="{ background: sessionStatusColor(s.status) }"
                                        :title="s.status"
                                    ></span>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-medium text-[var(--wa-text)] truncate">
                                            {{ sessionLabel(s) }}
                                        </div>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <div v-if="editingId" class="flex items-center gap-2 pt-1">
                            <input id="user-active" v-model="form.is_active" type="checkbox" class="w-4 h-4 rounded" />
                            <label for="user-active" class="text-sm text-[var(--wa-text)] cursor-pointer">Активен (может входить в систему)</label>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 px-5 py-3 border-t shrink-0" :style="{ borderColor: 'var(--wa-border)' }">
                        <button
                            type="button"
                            class="px-4 py-2 text-sm rounded-lg text-[var(--wa-text-secondary)] hover:bg-[var(--wa-panel-hover)]"
                            @click="closeModal"
                        >
                            Отмена
                        </button>
                        <button
                            type="button"
                            class="px-4 py-2 text-sm rounded-lg transition hover:brightness-95"
                            :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                            @click="save"
                        >
                            Сохранить
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </SettingsLayout>
</template>

<style scoped>
.settings-input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border-radius: 0.5rem;
    font-size: 0.875rem;
    background: var(--wa-bg);
    color: var(--wa-text);
    border: 1px solid var(--wa-border-strong);
    transition: border-color 0.15s ease;
}
.settings-input:focus {
    outline: none;
    border-color: var(--wa-accent);
}
</style>
