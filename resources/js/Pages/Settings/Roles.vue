<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type TenantRole = {
    id: number;
    name: string;
    guard_name: string;
    users_count: number;
    permissions: string[];
    is_protected: boolean;
};

type PermissionGroups = Record<string, { label: string; permissions: Record<string, string> }>;

const props = defineProps<{
    roles: TenantRole[];
    permissionGroups: PermissionGroups;
    protectedRoleNames: string[];
}>();

const editingId = ref<number | null>(null);
const showForm = ref(false);
const deleteTarget = ref<TenantRole | null>(null);
const deleteOpen = ref(false);

const form = useForm({
    name: '',
    permissions: [] as string[],
});

const allPermissionKeys = computed(() => {
    const keys: string[] = [];
    for (const group of Object.values(props.permissionGroups)) {
        keys.push(...Object.keys(group.permissions));
    }
    return keys;
});

const editingRole = computed(() => props.roles.find((role) => role.id === editingId.value) ?? null);

function openCreate(): void {
    editingId.value = null;
    form.clearErrors();
    form.name = '';
    form.permissions = [];
    showForm.value = true;
}

function openEdit(role: TenantRole): void {
    editingId.value = role.id;
    form.clearErrors();
    form.name = role.name;
    form.permissions = [...role.permissions];
    showForm.value = true;
}

function closeForm(): void {
    showForm.value = false;
    editingId.value = null;
}

function togglePermission(key: string, checked: boolean): void {
    if (checked) {
        if (!form.permissions.includes(key)) {
            form.permissions.push(key);
        }
        return;
    }
    form.permissions = form.permissions.filter((item) => item !== key);
}

function submit(): void {
    if (editingId.value === null) {
        form.post(route('settings.roles.store'), {
            preserveScroll: true,
            onSuccess: () => closeForm(),
        });
        return;
    }

    form.put(route('settings.roles.update', editingId.value), {
        preserveScroll: true,
        onSuccess: () => closeForm(),
    });
}

function confirmDelete(role: TenantRole): void {
    deleteTarget.value = role;
    deleteOpen.value = true;
}

function destroyRole(): void {
    if (!deleteTarget.value) {
        return;
    }

    router.delete(route('settings.roles.destroy', deleteTarget.value.id), {
        preserveScroll: true,
        onFinish: () => {
            deleteOpen.value = false;
            deleteTarget.value = null;
        },
    });
}
</script>

<template>
    <Head title="Роли и права" />

    <SettingsLayout title="Роли и права" subtitle="Кастомные роли компании и матрица доступа">
        <div class="w-full space-y-6 px-6 py-6">
            <section class="ui-settings-section">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold" :style="{ color: 'var(--ui-text)' }">
                            Роли компании
                        </h2>
                        <p class="mt-1 text-sm" :style="{ color: 'var(--ui-text-secondary)' }">
                            Назначайте сотрудникам роли с нужным набором прав. Системные роли можно переименовать, но не удалить.
                        </p>
                    </div>
                    <button type="button" class="ui-btn ui-btn--primary" @click="openCreate">
                        + Новая роль
                    </button>
                </div>

                <div class="mt-4 overflow-x-auto">
                    <table class="ui-table w-full min-w-[640px]">
                        <thead>
                            <tr>
                                <th>Роль</th>
                                <th>Права</th>
                                <th>Сотрудники</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="role in roles" :key="role.id">
                                <td>
                                    <div class="font-medium">{{ role.name }}</div>
                                    <div v-if="role.is_protected" class="text-xs" :style="{ color: 'var(--ui-text-muted)' }">
                                        Системная роль
                                    </div>
                                </td>
                                <td class="text-sm" :style="{ color: 'var(--ui-text-secondary)' }">
                                    {{ role.permissions.length }}
                                </td>
                                <td>{{ role.users_count }}</td>
                                <td class="text-right">
                                    <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="openEdit(role)">
                                        Изменить
                                    </button>
                                    <button
                                        v-if="!role.is_protected"
                                        type="button"
                                        class="ui-btn ui-btn--ghost ui-btn--sm text-red-600"
                                        :disabled="role.users_count > 0"
                                        @click="confirmDelete(role)"
                                    >
                                        Удалить
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="showForm" class="ui-panel space-y-4 px-4 py-4">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold" :style="{ color: 'var(--ui-text)' }">
                        {{ editingRole ? `Редактирование: ${editingRole.name}` : 'Новая роль' }}
                    </h3>
                    <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="closeForm">
                        Закрыть
                    </button>
                </div>

                <label class="block max-w-md">
                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide" :style="{ color: 'var(--ui-text-secondary)' }">
                        Название роли
                    </span>
                    <input v-model="form.name" type="text" maxlength="64" class="ui-input w-full" required>
                    <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                </label>

                <div class="space-y-4">
                    <div
                        v-for="(group, groupKey) in permissionGroups"
                        :key="groupKey"
                        class="rounded-lg border px-4 py-3"
                        :style="{ borderColor: 'var(--ui-border)' }"
                    >
                        <h4 class="text-sm font-semibold" :style="{ color: 'var(--ui-text)' }">{{ group.label }}</h4>
                        <div class="mt-3 grid gap-2 md:grid-cols-2">
                            <label
                                v-for="(label, permissionKey) in group.permissions"
                                :key="permissionKey"
                                class="flex items-start gap-2 text-sm"
                            >
                                <UiCheckbox
                                    :model-value="form.permissions.includes(permissionKey)"
                                    @update:model-value="(checked) => togglePermission(permissionKey, checked)"
                                />
                                <span>
                                    <span class="font-medium" :style="{ color: 'var(--ui-text)' }">{{ label }}</span>
                                    <span class="block text-xs" :style="{ color: 'var(--ui-text-muted)' }">{{ permissionKey }}</span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <p v-if="form.errors.permissions" class="text-xs text-red-500">{{ form.errors.permissions }}</p>

                <div class="flex gap-2">
                    <button type="button" class="ui-btn ui-btn--primary" :disabled="form.processing" @click="submit">
                        {{ form.processing ? 'Сохранение…' : 'Сохранить' }}
                    </button>
                    <button type="button" class="ui-btn ui-btn--secondary" @click="closeForm">
                        Отмена
                    </button>
                </div>
            </section>
        </div>

        <DangerConfirmModal
            :open="deleteOpen"
            title="Удалить роль?"
            :description="deleteTarget ? `Роль «${deleteTarget.name}» будет удалена без возможности восстановления.` : ''"
            confirm-label="Удалить"
            @close="deleteOpen = false"
            @confirm="destroyRole"
        />
    </SettingsLayout>
</template>
