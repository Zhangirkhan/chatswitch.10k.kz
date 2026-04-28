<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import axios from 'axios';
import type { User, Department, WhatsappSession } from '@/types';

const props = defineProps<{
    users: User[];
    departments: Department[];
    whatsappSessions: WhatsappSession[];
    availableRoles: string[];
}>();

const local = ref<User[]>([...props.users]);
const showForm = ref(false);
const editingId = ref<number | null>(null);
const form = ref({
    name: '',
    email: '',
    password: '',
    role: 'employee',
    department_id: null as number | null,
    is_active: true,
    whatsapp_session_ids: [] as number[],
});

const roleLabels: Record<string, string> = {
    administrator: 'Администратор',
    manager: 'Руководитель',
    employee: 'Сотрудник',
};

function openAdd() {
    editingId.value = null;
    form.value = {
        name: '',
        email: '',
        password: '',
        role: 'employee',
        department_id: null,
        is_active: true,
        whatsapp_session_ids: [],
    };
    showForm.value = true;
}

function openEdit(user: User) {
    editingId.value = user.id;
    form.value = {
        name: user.name,
        email: user.email,
        password: '',
        role: user.roles?.[0] || 'employee',
        department_id: user.department_id,
        is_active: user.is_active,
        whatsapp_session_ids: [...(user.whatsapp_session_ids || [])],
    };
    showForm.value = true;
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
    if (status === 'connected') return '#25d366';
    if (status === 'qr_pending' || status === 'connecting') return '#f59e0b';
    return '#ef4444';
}

function buildPayload(): Record<string, unknown> {
    const dept = form.value.department_id;
    const department_id =
        dept === null || dept === undefined || (typeof dept === 'string' && dept === '')
            ? null
            : Number(dept);

    const payload: Record<string, unknown> = {
        name: form.value.name.trim(),
        email: form.value.email.trim(),
        phones: [],
        role: form.value.role,
        department_id,
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
        alert('Укажите пароль для нового пользователя');
        return;
    }
    try {
        const payload = buildPayload();
        if (editingId.value) {
            const { data } = await axios.put(route('settings.users.update', editingId.value), payload);
            const idx = local.value.findIndex((u) => u.id === editingId.value);
            if (idx !== -1) local.value[idx] = data.user;
        } else {
            const { data } = await axios.post(route('settings.users.store'), payload);
            local.value.push(data.user);
        }
        showForm.value = false;
    } catch (err: unknown) {
        alert(validationMessage(err));
    }
}

async function remove(user: User) {
    if (!confirm(`Удалить пользователя "${user.name}"?`)) return;
    await axios.delete(route('settings.users.destroy', user.id));
    local.value = local.value.filter((u) => u.id !== user.id);
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
            <!-- Form -->
            <div
                v-if="showForm"
                class="rounded-lg border p-5"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <h3 class="text-sm font-medium mb-4 text-[var(--wa-text)]">
                    {{ editingId ? 'Редактировать пользователя' : 'Новый пользователь' }}
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Имя</label>
                        <input v-model="form.name" type="text" class="settings-input" />
                    </div>
                    <div>
                        <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Email</label>
                        <input v-model="form.email" type="email" class="settings-input" />
                    </div>
                    <div>
                        <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">
                            Пароль {{ editingId ? '(оставьте пустым, если не меняете)' : '' }}
                        </label>
                        <input v-model="form.password" type="password" class="settings-input" />
                    </div>
                    <div>
                        <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Роль</label>
                        <select v-model="form.role" class="settings-input">
                            <option v-for="r in availableRoles" :key="r" :value="r">{{ roleLabels[r] || r }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Отдел</label>
                        <select v-model="form.department_id" class="settings-input">
                            <option :value="null">— Без отдела —</option>
                            <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                        </select>
                    </div>

                    <div class="col-span-full">
                        <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">
                            Подключения WhatsApp
                        </label>
                        <p class="text-xs text-[var(--wa-text-secondary)] mb-2">
                            Названия из раздела «Подключения» (например «WhatsApp #1»). Номер телефона аккаунта WhatsApp здесь не
                            показывается.
                        </p>
                        <div
                            v-if="whatsappSessions.length === 0"
                            class="text-xs text-[var(--wa-text-secondary)] italic"
                        >
                            Пока нет сессий. Добавьте их в «Подключения».
                        </div>
                        <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-2">
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
                                    class="w-4 h-4 rounded"
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

                    <div v-if="editingId" class="flex items-center gap-2 col-span-full">
                        <input v-model="form.is_active" type="checkbox" class="w-4 h-4 rounded" />
                        <label class="text-sm text-[var(--wa-text-secondary)]">Активен</label>
                    </div>
                </div>
                <div class="flex gap-2 mt-4">
                    <button
                        class="px-4 py-2 text-sm rounded-lg transition hover:brightness-95"
                        :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                        @click="save"
                    >Сохранить</button>
                    <button
                        class="px-4 py-2 text-sm rounded-lg text-[var(--wa-text-secondary)] hover:bg-[var(--wa-panel-hover)]"
                        @click="showForm = false"
                    >Отмена</button>
                </div>
            </div>

            <!-- Users table -->
            <div
                class="rounded-lg border overflow-hidden"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <table class="w-full text-sm">
                    <thead :style="{ background: 'var(--wa-panel-header)' }">
                        <tr>
                            <th class="text-left px-5 py-3 font-medium text-[var(--wa-text-secondary)]">Имя</th>
                            <th class="text-left px-5 py-3 font-medium text-[var(--wa-text-secondary)]">Email</th>
                            <th class="text-left px-5 py-3 font-medium text-[var(--wa-text-secondary)]">Роль</th>
                            <th class="text-left px-5 py-3 font-medium text-[var(--wa-text-secondary)]">Отдел</th>
                            <th class="text-left px-5 py-3 font-medium text-[var(--wa-text-secondary)]">WhatsApp</th>
                            <th class="text-left px-5 py-3 font-medium text-[var(--wa-text-secondary)]">Статус</th>
                            <th class="text-right px-5 py-3 font-medium text-[var(--wa-text-secondary)]">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="local.length === 0">
                            <td colspan="7" class="px-5 py-10 text-center text-[var(--wa-text-secondary)]">
                                Нет пользователей
                            </td>
                        </tr>
                        <tr
                            v-for="user in local"
                            :key="user.id"
                            class="border-t"
                            :style="{ borderColor: 'var(--wa-border)' }"
                        >
                            <td class="px-5 py-3 font-medium text-[var(--wa-text)]">{{ user.name }}</td>
                            <td class="px-5 py-3 text-[var(--wa-text-secondary)]">{{ user.email }}</td>
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
                            <td class="px-5 py-3 text-[var(--wa-text-secondary)]">{{ user.department?.name || '—' }}</td>
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
                                        :class="user.is_active ? 'bg-[#25d366]' : 'bg-red-400'"
                                    ></span>
                                    <span class="text-xs text-[var(--wa-text-secondary)]">
                                        {{ user.is_active ? 'Активен' : 'Отключён' }}
                                    </span>
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <button
                                    class="text-xs hover:underline mr-3"
                                    :style="{ color: 'var(--wa-accent)' }"
                                    @click="openEdit(user)"
                                >Изменить</button>
                                <button
                                    class="text-xs text-red-500 hover:underline"
                                    @click="remove(user)"
                                >Удалить</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
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
