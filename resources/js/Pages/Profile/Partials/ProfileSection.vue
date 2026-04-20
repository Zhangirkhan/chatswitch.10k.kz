<script setup lang="ts">
import SectionHeader from './SectionHeader.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

const page = usePage<any>();
const user = computed(() => page.props.auth.user);

const form = useForm({
    name: user.value?.name ?? '',
    phone: user.value?.phone ?? '',
    email: user.value?.email ?? '',
});

const editingName = ref(false);
const editingPhone = ref(false);
const nameInput = ref<HTMLInputElement>();
const phoneInput = ref<HTMLInputElement>();
const copiedPhone = ref(false);

async function startEditName() {
    editingName.value = true;
    await nextTick();
    nameInput.value?.focus();
    nameInput.value?.select();
}

async function startEditPhone() {
    editingPhone.value = true;
    await nextTick();
    phoneInput.value?.focus();
}

function save(field: 'name' | 'phone') {
    form.patch(route('profile.update'), {
        preserveScroll: true,
        onSuccess: () => {
            if (field === 'name') editingName.value = false;
            if (field === 'phone') editingPhone.value = false;
        },
    });
}

async function copyPhone() {
    if (!form.phone) return;
    try {
        await navigator.clipboard.writeText(form.phone);
        copiedPhone.value = true;
        setTimeout(() => (copiedPhone.value = false), 1500);
    } catch {
        // Clipboard unavailable — ignore silently.
    }
}

function initial(name?: string): string {
    return (name || '?').charAt(0).toUpperCase();
}
</script>

<template>
    <div class="h-full flex flex-col">
        <SectionHeader title="Изменить профиль" />

        <div class="flex-1 overflow-y-auto wa-scrollbar">
            <!-- Avatar -->
            <div class="flex flex-col items-center py-6">
                <div class="w-[170px] h-[170px] rounded-full bg-[#6b7c85] flex items-center justify-center text-white text-6xl font-medium shadow-lg">
                    {{ initial(user?.name) }}
                </div>
                <button
                    type="button"
                    class="mt-3 inline-flex items-center gap-2 px-4 py-1.5 rounded-full border text-sm transition"
                    :style="{ borderColor: 'var(--wa-border-strong)', color: 'var(--wa-accent)' }"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                        <circle cx="12" cy="13" r="3" />
                    </svg>
                    <span>Редактировать</span>
                </button>
            </div>

            <!-- Name -->
            <div class="px-6 py-3">
                <div class="text-xs text-[var(--wa-text-secondary)] mb-2">Имя</div>
                <div class="flex items-center gap-3 border-b pb-2" :style="{ borderColor: editingName ? 'var(--wa-accent)' : 'var(--wa-border-strong)' }">
                    <input
                        v-if="editingName"
                        ref="nameInput"
                        v-model="form.name"
                        type="text"
                        maxlength="25"
                        class="flex-1 bg-transparent border-0 text-[15px] text-[var(--wa-text)] focus:outline-none focus:ring-0 p-0"
                        @keydown.enter.prevent="save('name')"
                        @keydown.escape.prevent="editingName = false; form.name = user?.name"
                        @blur="save('name')"
                    />
                    <div v-else class="flex-1 text-[15px] text-[var(--wa-text)] truncate">{{ form.name }}</div>
                    <button
                        v-if="!editingName"
                        type="button"
                        @click="startEditName"
                        class="text-[var(--wa-icon)] hover:text-[var(--wa-text)] transition"
                        title="Изменить"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                    </button>
                    <span v-if="form.processing" class="text-xs text-[var(--wa-text-secondary)]">Сохранение…</span>
                </div>
                <p v-if="form.errors.name" class="mt-1 text-xs text-red-400">{{ form.errors.name }}</p>
            </div>

            <!-- Phone -->
            <div class="px-6 py-3 mt-4">
                <div class="text-xs text-[var(--wa-text-secondary)] mb-2">Телефон</div>
                <div class="flex items-center gap-3 border-b pb-2" :style="{ borderColor: editingPhone ? 'var(--wa-accent)' : 'var(--wa-border-strong)' }">
                    <svg class="w-4 h-4 text-[var(--wa-icon)] shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h2.28a2 2 0 011.94 1.515l.7 2.8a2 2 0 01-.51 1.88l-1.54 1.54a11 11 0 005.1 5.1l1.54-1.54a2 2 0 011.88-.51l2.8.7A2 2 0 0121 16.72V19a2 2 0 01-2 2h-1C9.163 21 3 14.837 3 7V6z" />
                    </svg>
                    <input
                        v-if="editingPhone"
                        ref="phoneInput"
                        v-model="form.phone"
                        type="tel"
                        class="flex-1 bg-transparent border-0 text-[15px] text-[var(--wa-text)] focus:outline-none focus:ring-0 p-0"
                        @keydown.enter.prevent="save('phone')"
                        @keydown.escape.prevent="editingPhone = false; form.phone = user?.phone ?? ''"
                        @blur="save('phone')"
                        placeholder="+7 777 000 0000"
                    />
                    <div v-else class="flex-1 text-[15px] text-[var(--wa-text)] truncate">
                        {{ form.phone || 'Не указан' }}
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                        <button
                            v-if="!editingPhone"
                            type="button"
                            @click="startEditPhone"
                            class="p-1 text-[var(--wa-icon)] hover:text-[var(--wa-text)] transition"
                            title="Изменить"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                        </button>
                        <button
                            v-if="!editingPhone && form.phone"
                            type="button"
                            @click="copyPhone"
                            class="p-1 text-[var(--wa-icon)] hover:text-[var(--wa-text)] transition relative"
                            :title="copiedPhone ? 'Скопировано' : 'Скопировать'"
                        >
                            <svg v-if="!copiedPhone" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                            <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" :style="{ color: 'var(--wa-accent)' }">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </button>
                    </div>
                </div>
                <p v-if="form.errors.phone" class="mt-1 text-xs text-red-400">{{ form.errors.phone }}</p>
            </div>
        </div>
    </div>
</template>
