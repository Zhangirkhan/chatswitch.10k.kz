<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import type { Contact } from '@/types';
import { formatPhone } from '@/utils/phone';

const props = defineProps<{
    contacts: Contact[];
    search?: string;
}>();

const q = ref((props.search || '').toString());
const savingId = ref<number | null>(null);
const error = ref<string | null>(null);
const local = ref<Contact[]>([...(props.contacts || [])]);

watch(
    () => props.contacts,
    (val) => {
        local.value = [...(val || [])];
    },
);

let t: ReturnType<typeof setTimeout> | null = null;
watch(q, (val) => {
    if (t) clearTimeout(t);
    t = setTimeout(() => {
        router.get(route('contacts.index'), { search: val || undefined }, { preserveState: true, preserveScroll: true, replace: true });
    }, 250);
});

const rows = computed(() => local.value);

function label(c: Contact): string {
    return c.name || c.push_name || formatPhone(c.phone_number) || '';
}

async function saveName(c: Contact, name: string) {
    if (savingId.value) return;
    error.value = null;
    savingId.value = c.id;
    try {
        const { data } = await axios.patch(route('contacts.update', c.id), { name });
        const updated = (data?.contact || null) as Contact | null;
        if (updated) {
            const idx = local.value.findIndex((x) => x.id === c.id);
            if (idx >= 0) local.value[idx] = { ...local.value[idx], ...updated };
        }
    } catch (e: any) {
        error.value = e?.response?.data?.message || e?.response?.data?.error || 'Не удалось сохранить';
    } finally {
        savingId.value = null;
    }
}
</script>

<template>
    <Head title="Контакты" />
    <AuthenticatedLayout>
        <div class="flex h-full w-full">
            <div class="flex-1 min-w-0 flex flex-col overflow-hidden">
                <div class="h-[60px] px-5 flex items-center justify-between border-b"
                     :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border)' }"
                >
                    <div class="text-base font-medium" :style="{ color: 'var(--wa-text)' }">Контакты</div>
                    <div class="w-[420px] max-w-[60vw] relative">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <input
                            v-model="q"
                            type="text"
                            placeholder="Поиск контактов…"
                            class="w-full pl-10 pr-3 py-2 rounded-full text-sm border-0 focus:ring-0 focus:outline-none"
                            :style="{ background: 'var(--wa-panel)', color: 'var(--wa-text)' }"
                        />
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto wa-scrollbar">
                    <div v-if="error" class="px-5 py-3 text-sm" style="color:#ff6b6b;">
                        {{ error }}
                    </div>

                    <div class="px-5 py-4">
                        <div class="text-xs mb-3" :style="{ color: 'var(--wa-text-secondary)' }">
                            {{ rows.length }} контактов
                        </div>

                        <div class="grid grid-cols-1 gap-2">
                            <div
                                v-for="c in rows"
                                :key="c.id"
                                class="rounded-xl border p-4"
                                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
                            >
                                <div class="flex items-start justify-between gap-4">
                                    <div class="min-w-0">
                                        <div class="text-sm font-medium truncate" :style="{ color: 'var(--wa-text)' }">
                                            {{ label(c) || 'Без имени' }}
                                        </div>
                                        <div class="text-xs mt-1" :style="{ color: 'var(--wa-text-secondary)' }">
                                            {{ formatPhone(c.phone_number) || '' }}
                                        </div>
                                        <div v-if="c.push_name && (!c.name || c.push_name !== c.name)" class="text-xs mt-1" :style="{ color: 'var(--wa-text-secondary)' }">
                                            WhatsApp: {{ c.push_name }}
                                        </div>
                                    </div>

                                    <div class="w-[260px] shrink-0">
                                        <label class="block text-[11px] mb-1" :style="{ color: 'var(--wa-text-secondary)' }">Имя (сохранённое)</label>
                                        <input
                                            :value="c.name || ''"
                                            class="w-full px-3 py-2 rounded-xl border-0 focus:ring-0 focus:outline-none"
                                            :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-text)' }"
                                            placeholder="Введите имя…"
                                            @change="saveName(c, ($event.target as HTMLInputElement).value)"
                                        />
                                        <div class="text-[11px] mt-1" :style="{ color: 'var(--wa-text-secondary)' }">
                                            {{ savingId === c.id ? 'Сохранение…' : 'Изменение сохраняется по потере фокуса' }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

