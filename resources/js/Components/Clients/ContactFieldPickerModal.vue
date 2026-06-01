<script setup lang="ts">
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import UiModal from '@/Components/Ui/UiModal.vue';
import ContactAddFieldModal from '@/Components/Clients/ContactAddFieldModal.vue';
import type { ContactFieldDefinition } from '@/utils/contactFieldTypes';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { useToastStore } from '@/stores/toast';

const props = defineProps<{
    open: boolean;
}>();

const emit = defineEmits<{
    close: [];
    updated: [];
}>();

const { show: showToast } = useToastStore();

const loading = ref(false);
const saving = ref(false);
const search = ref('');
const fields = ref<ContactFieldDefinition[]>([]);
const draftVisibility = ref<Record<number, boolean>>({});
const addFieldOpen = ref(false);

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            void loadFields();
        }
    },
    { immediate: true },
);

async function loadFields(): Promise<void> {
    loading.value = true;
    try {
        const { data } = await axios.get(route('settings.contact-fields.list'));
        fields.value = data.fields as ContactFieldDefinition[];
        draftVisibility.value = Object.fromEntries(
            fields.value.map((field) => [field.id, field.is_visible]),
        );
    } catch {
        showToast({ message: 'Не удалось загрузить поля', duration: 4000 });
    } finally {
        loading.value = false;
    }
}

const groupedFields = computed(() => {
    const q = search.value.trim().toLowerCase();
    const groups = new Map<string, { key: string; label: string; items: ContactFieldDefinition[] }>();

    for (const field of fields.value) {
        if (q && !field.label.toLowerCase().includes(q)) {
            continue;
        }
        const key = field.group;
        if (!groups.has(key)) {
            groups.set(key, {
                key,
                label: field.group_label || key,
                items: [],
            });
        }
        groups.get(key)!.items.push(field);
    }

    return [...groups.values()];
});

const allVisible = computed({
    get() {
        return fields.value.length > 0 && fields.value.every((field) => draftVisibility.value[field.id]);
    },
    set(value: boolean) {
        for (const field of fields.value) {
            draftVisibility.value[field.id] = value;
        }
    },
});

function toggleField(id: number, value: boolean): void {
    draftVisibility.value[id] = value;
}

async function save(): Promise<void> {
    if (saving.value) {
        return;
    }
    saving.value = true;
    try {
        await axios.put(route('settings.contact-fields.visibility'), {
            visibility: fields.value.map((field) => ({
                id: field.id,
                is_visible: draftVisibility.value[field.id] ?? field.is_visible,
            })),
        });
        showToast({ message: 'Настройки полей сохранены', duration: 2500 });
        emit('updated');
        emit('close');
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } };
        showToast({ message: err.response?.data?.message || 'Не удалось сохранить', duration: 4000 });
    } finally {
        saving.value = false;
    }
}

function onFieldCreated(): void {
    addFieldOpen.value = false;
    void loadFields();
    emit('updated');
}
</script>

<template>
    <UiModal
        :open="open"
        title="Выбор полей"
        max-width="4xl"
        aria-label="Выбор полей контакта"
        @close="emit('close')"
    >
        <div class="space-y-4">
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative min-w-[220px] flex-1">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 opacity-50" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Поиск по полям"
                        class="w-full rounded-lg border-0 py-2 pl-9 pr-3 text-sm focus:ring-0 focus:outline-none"
                        :style="{ background: 'var(--ui-surface-muted)' }"
                    />
                </div>
                <button
                    type="button"
                    class="flex h-8 w-8 items-center justify-center rounded-lg text-base font-medium leading-none"
                    :style="{ background: 'var(--ui-accent)', color: '#fff' }"
                    aria-label="Добавить поле"
                    title="Добавить поле"
                    @click="addFieldOpen = true"
                >
                    +
                </button>
                <span class="rounded-full px-3 py-1 text-xs" :style="{ background: 'var(--ui-surface-muted)' }">Контакт</span>
            </div>

            <div v-if="loading" class="py-10 text-center text-sm opacity-70">Загружаем поля…</div>

            <div v-else class="max-h-[52vh] space-y-5 overflow-y-auto pr-1">
                <section v-for="group in groupedFields" :key="group.key">
                    <h3 class="mb-2 text-sm font-medium">{{ group.label }}</h3>
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <label
                            v-for="field in group.items"
                            :key="field.id"
                            class="flex cursor-pointer items-start gap-2 rounded-lg px-2 py-1.5 hover:bg-[var(--ui-surface-muted)]"
                        >
                            <UiCheckbox
                                :model-value="draftVisibility[field.id] ?? field.is_visible"
                                class="mt-0.5"
                                @update:model-value="toggleField(field.id, $event)"
                            />
                            <span class="min-w-0 text-sm">
                                {{ field.label }}
                                <span v-if="!field.is_system" class="ml-1 text-[11px] opacity-50">(своё)</span>
                            </span>
                        </label>
                    </div>
                </section>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t pt-4" :style="{ borderColor: 'var(--ui-border)' }">
                <label class="flex cursor-pointer items-center gap-2 text-sm">
                    <UiCheckbox v-model="allVisible" />
                    выбрать все
                </label>
                <div class="flex gap-2">
                    <button type="button" class="rounded-lg px-4 py-2 text-sm" :style="{ background: 'var(--ui-surface-muted)' }" @click="emit('close')">
                        Отменить
                    </button>
                    <button
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                        :style="{ background: 'var(--ui-accent)', color: '#fff' }"
                        :disabled="saving"
                        @click="save"
                    >
                        Выбрать
                    </button>
                </div>
            </div>
        </div>
    </UiModal>

    <ContactAddFieldModal :open="addFieldOpen" @close="addFieldOpen = false" @created="onFieldCreated" />
</template>
