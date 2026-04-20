<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import axios from 'axios';
import type { Department } from '@/types';

const props = defineProps<{
    departments: (Department & { users_count: number })[];
}>();

const local = ref([...props.departments]);
const showForm = ref(false);
const editingId = ref<number | null>(null);
const form = ref({ name: '', description: '' });

function openAdd() {
    editingId.value = null;
    form.value = { name: '', description: '' };
    showForm.value = true;
}

function openEdit(dept: Department) {
    editingId.value = dept.id;
    form.value = { name: dept.name, description: dept.description || '' };
    showForm.value = true;
}

async function save() {
    if (!form.value.name.trim()) return;
    try {
        if (editingId.value) {
            const { data } = await axios.put(route('settings.departments.update', editingId.value), form.value);
            const idx = local.value.findIndex((d) => d.id === editingId.value);
            if (idx !== -1) local.value[idx] = { ...local.value[idx], ...data.department };
        } else {
            const { data } = await axios.post(route('settings.departments.store'), form.value);
            local.value.push({ ...data.department, users_count: 0 });
        }
        showForm.value = false;
    } catch (err: any) {
        alert(err.response?.data?.message || 'Ошибка');
    }
}

async function remove(dept: Department) {
    if (!confirm(`Удалить отдел "${dept.name}"?`)) return;
    await axios.delete(route('settings.departments.destroy', dept.id));
    local.value = local.value.filter((d) => d.id !== dept.id);
}
</script>

<template>
    <Head title="Отделы" />
    <SettingsLayout title="Отделы" subtitle="Структура компании и распределение операторов">
        <template #actions>
            <button
                @click="openAdd"
                class="px-4 py-2 text-sm rounded-lg transition hover:brightness-95"
                :style="{ background: 'var(--wa-accent)', color: '#fff' }"
            >
                + Добавить отдел
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
                    {{ editingId ? 'Редактировать отдел' : 'Новый отдел' }}
                </h3>
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Название</label>
                        <input v-model="form.name" type="text" class="settings-input" />
                    </div>
                    <div>
                        <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Описание</label>
                        <textarea v-model="form.description" rows="2" class="settings-input"></textarea>
                    </div>
                    <div class="flex gap-2">
                        <button
                            @click="save"
                            class="px-4 py-2 text-sm rounded-lg transition hover:brightness-95"
                            :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                        >Сохранить</button>
                        <button
                            @click="showForm = false"
                            class="px-4 py-2 text-sm rounded-lg text-[var(--wa-text-secondary)] hover:bg-[var(--wa-panel-hover)]"
                        >Отмена</button>
                    </div>
                </div>
            </div>

            <!-- List -->
            <div
                class="rounded-lg border overflow-hidden"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <div v-if="local.length === 0" class="p-10 text-center text-[var(--wa-text-secondary)]">
                    Нет отделов. Нажмите «Добавить отдел», чтобы создать первый.
                </div>
                <div
                    v-for="dept in local"
                    :key="dept.id"
                    class="flex items-center justify-between px-5 py-4 border-b last:border-b-0"
                    :style="{ borderColor: 'var(--wa-border)' }"
                >
                    <div class="min-w-0">
                        <h3 class="font-medium text-[var(--wa-text)] truncate">{{ dept.name }}</h3>
                        <p v-if="dept.description" class="text-xs text-[var(--wa-text-secondary)] mt-0.5 truncate">
                            {{ dept.description }}
                        </p>
                        <span class="text-xs text-[var(--wa-text-secondary)]">{{ dept.users_count }} пользователей</span>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button
                            @click="openEdit(dept)"
                            class="px-3 py-1.5 text-xs rounded transition hover:brightness-95"
                            :style="{ color: 'var(--wa-accent)' }"
                        >Изменить</button>
                        <button
                            @click="remove(dept)"
                            class="px-3 py-1.5 text-xs text-red-500 hover:bg-red-500/10 rounded transition"
                        >Удалить</button>
                    </div>
                </div>
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
