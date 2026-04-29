<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import type { Department } from '@/types';

interface DeptUser {
    id: number;
    name: string;
    email: string;
    department_id?: number | null;
}

interface DepartmentRow extends Department {
    users_count: number;
    users: DeptUser[];
}

interface AssignmentUser {
    id: number;
    name: string;
    email: string;
    department_id: number | null;
    is_active: boolean;
}

const props = defineProps<{
    departments: DepartmentRow[];
    users: AssignmentUser[];
}>();

const localDepartments = ref<DepartmentRow[]>([...props.departments]);
const localUsers = ref<AssignmentUser[]>([...props.users]);

watch(
    () => props.departments,
    (v) => {
        localDepartments.value = [...v];
    },
    { deep: true },
);
watch(
    () => props.users,
    (v) => {
        localUsers.value = [...v];
    },
    { deep: true },
);

const modalOpen = ref(false);
const saving = ref(false);
const memberSearch = ref('');

const form = ref({
    name: '',
    description: '',
    is_active: true,
});

/** Пользователи, отмеченные как входящие в редактируемый отдел */
const selectedMemberIds = ref<number[]>([]);

const showMemberSearch = computed(() => localUsers.value.length > 10);

const filteredUsersForPicker = computed(() => {
    const list = localUsers.value;
    if (!showMemberSearch.value || !memberSearch.value.trim()) {
        return list;
    }
    const q = memberSearch.value.trim().toLowerCase();
    return list.filter((u) => {
        const name = (u.name || '').toLowerCase();
        const email = (u.email || '').toLowerCase();
        return name.includes(q) || email.includes(q);
    });
});

function openCreate() {
    form.value = { name: '', description: '', is_active: true };
    selectedMemberIds.value = [];
    memberSearch.value = '';
    modalOpen.value = true;
}

function closeModal() {
    modalOpen.value = false;
}

function toggleMember(id: number) {
    const idx = selectedMemberIds.value.indexOf(id);
    if (idx === -1) {
        selectedMemberIds.value = [...selectedMemberIds.value, id];
    } else {
        selectedMemberIds.value = selectedMemberIds.value.filter((x) => x !== id);
    }
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

async function saveModal() {
    if (!form.value.name.trim()) {
        alert('Укажите название отдела');
        return;
    }
    if (saving.value) return;
    saving.value = true;
    try {
        const { data } = await axios.post(route('settings.departments.store'), {
            name: form.value.name.trim(),
            description: form.value.description.trim() || null,
            is_active: form.value.is_active,
        });
        const deptId = data.department?.id as number;

        await axios.post(route('settings.departments.members.sync', deptId), {
            user_ids: selectedMemberIds.value,
        });

        closeModal();
        router.reload({ only: ['departments', 'users'] });
    } catch (err: unknown) {
        alert(validationMessage(err));
    } finally {
        saving.value = false;
    }
}

async function removeDepartment(dept: DepartmentRow) {
    if (!confirm(`Удалить отдел «${dept.name}»? У пользователей поле отдела будет очищено.`)) {
        return;
    }
    try {
        await axios.delete(route('settings.departments.destroy', dept.id));
        router.reload({ only: ['departments', 'users'] });
    } catch (err: unknown) {
        alert(validationMessage(err));
    }
}
</script>

<template>
    <Head title="Отделы" />
    <SettingsLayout title="Отделы" subtitle="Структура компании и распределение операторов">
        <template #actions>
            <button
                type="button"
                class="px-4 py-2 text-sm rounded-lg transition hover:brightness-95"
                :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                @click="openCreate"
            >
                + Отдел
            </button>
        </template>

        <div class="w-full px-6 py-6 space-y-4">
            <p class="text-sm text-[var(--wa-text-secondary)] max-w-3xl">
                У каждого пользователя один отдел. Назначение в отдел здесь совпадает с полем «Отдел» в карточке пользователя
                («Пользователи»).
            </p>

            <div
                class="rounded-lg border overflow-hidden"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <div v-if="localDepartments.length === 0" class="p-10 text-center text-[var(--wa-text-secondary)]">
                    Нет отделов. Нажмите «+ Отдел».
                </div>
                <div
                    v-for="dept in localDepartments"
                    :key="dept.id"
                    class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-5 py-4 border-b last:border-b-0"
                    :style="{ borderColor: 'var(--wa-border)' }"
                >
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h3 class="font-medium text-[var(--wa-text)] truncate">{{ dept.name }}</h3>
                            <span
                                v-if="dept.is_active === false"
                                class="text-[10px] uppercase tracking-wide px-2 py-0.5 rounded bg-zinc-500/20 text-zinc-400"
                            >Неактивен</span>
                        </div>
                        <p v-if="dept.description" class="text-xs text-[var(--wa-text-secondary)] mt-0.5 line-clamp-2">
                            {{ dept.description }}
                        </p>
                        <p class="text-xs text-[var(--wa-text-secondary)] mt-1">
                            {{ dept.users_count }} {{ dept.users_count === 1 ? 'пользователь' : 'пользователей' }}
                            <template v-if="dept.users?.length">
                                ·
                                <span class="text-[var(--wa-text)]">{{ dept.users.map((u) => u.name).join(', ') }}</span>
                            </template>
                        </p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button
                            type="button"
                            class="px-3 py-1.5 text-sm rounded-lg text-red-400 border border-red-500/30 hover:bg-red-500/10"
                            @click="removeDepartment(dept)"
                        >
                            Удалить
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal -->
        <Teleport to="body">
            <div
                v-if="modalOpen"
                class="fixed inset-0 z-[2000] flex items-center justify-center p-4 bg-black/60"
                role="dialog"
                aria-modal="true"
                aria-label="Новый отдел"
                @click.self="closeModal"
            >
                <div
                    class="w-full max-w-lg max-h-[min(90vh,720px)] overflow-hidden flex flex-col rounded-xl border shadow-2xl"
                    :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border-strong)' }"
                    @click.stop
                >
                    <div class="px-5 py-4 border-b shrink-0" :style="{ borderColor: 'var(--wa-border)' }">
                        <h3 class="text-base font-medium text-[var(--wa-text)]">Новый отдел</h3>
                    </div>

                    <div class="flex-1 overflow-y-auto wa-scrollbar px-5 py-4 space-y-4">
                        <div>
                            <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Название</label>
                            <input v-model="form.name" type="text" class="settings-input" maxlength="255" />
                        </div>
                        <div>
                            <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Описание</label>
                            <textarea
                                v-model="form.description"
                                rows="3"
                                class="settings-input resize-y min-h-[4rem]"
                                maxlength="1000"
                            />
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input v-model="form.is_active" type="checkbox" class="w-4 h-4 rounded" />
                            <span class="text-sm text-[var(--wa-text)]">Отдел активен (участвует в чатах и списках)</span>
                        </label>

                        <div class="pt-2 border-t" :style="{ borderColor: 'var(--wa-border)' }">
                            <p class="text-sm font-medium text-[var(--wa-text)] mb-1">Сотрудники отдела</p>
                            <p class="text-xs text-[var(--wa-text-secondary)] mb-3">
                                Отмеченные пользователи будут отнесены к этому отделу. У одного пользователя может быть только один
                                отдел.
                            </p>

                            <div v-if="showMemberSearch" class="mb-3">
                                <input
                                    v-model="memberSearch"
                                    type="search"
                                    placeholder="Поиск по имени или email…"
                                    class="settings-input"
                                    autocomplete="off"
                                />
                            </div>

                            <div class="max-h-52 overflow-y-auto rounded-lg border wa-scrollbar" :style="{ borderColor: 'var(--wa-border)' }">
                                <label
                                    v-for="u in filteredUsersForPicker"
                                    :key="u.id"
                                    class="flex items-start gap-3 px-3 py-2 border-b last:border-b-0 cursor-pointer hover:brightness-95"
                                    :style="{
                                        borderColor: 'var(--wa-border)',
                                        opacity: u.is_active ? 1 : 0.55,
                                    }"
                                >
                                    <input
                                        type="checkbox"
                                        class="w-4 h-4 rounded mt-0.5 shrink-0"
                                        :checked="selectedMemberIds.includes(u.id)"
                                        @change="toggleMember(u.id)"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm text-[var(--wa-text)] truncate">{{ u.name }}</div>
                                        <div class="text-xs text-[var(--wa-text-secondary)] truncate">{{ u.email }}</div>
                                        <div
                                            v-if="u.department_id != null"
                                            class="text-[10px] text-amber-400/90 mt-0.5"
                                        >
                                            Сейчас в другом отделе — при сохранении перейдёт сюда
                                        </div>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div
                        class="flex justify-end gap-2 px-5 py-3 border-t shrink-0"
                        :style="{ borderColor: 'var(--wa-border)' }"
                    >
                        <button
                            type="button"
                            class="px-4 py-2 text-sm rounded-lg text-[var(--wa-text-secondary)] hover:bg-[var(--wa-panel-hover)]"
                            @click="closeModal"
                        >
                            Отмена
                        </button>
                        <button
                            type="button"
                            class="px-4 py-2 text-sm rounded-lg transition hover:brightness-95 disabled:opacity-50"
                            :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                            :disabled="saving"
                            @click="saveModal"
                        >
                            {{ saving ? 'Сохранение…' : 'Сохранить' }}
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
    box-sizing: border-box;
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    border-radius: 0.5rem;
    border: 1px solid var(--wa-border-strong);
    background: var(--wa-bg);
    color: var(--wa-text);
    outline: none;
}
.settings-input:focus {
    border-color: var(--wa-accent);
    box-shadow: 0 0 0 1px color-mix(in srgb, var(--wa-accent) 35%, transparent);
}
</style>
