<script setup lang="ts">
import ContactAddFieldModal from '@/Components/Clients/ContactAddFieldModal.vue';
import ContactFieldPickerModal from '@/Components/Clients/ContactFieldPickerModal.vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import type { ContactFieldDefinition, ContactFieldTypeOption } from '@/utils/contactFieldTypes';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { ref } from 'vue';
import { useToastStore } from '@/stores/toast';

const props = defineProps<{
    fields: ContactFieldDefinition[];
    field_types: ContactFieldTypeOption[];
    groups: Record<string, string>;
}>();

const { show: showToast } = useToastStore();
const localFields = ref<ContactFieldDefinition[]>([...props.fields]);
const pickerOpen = ref(false);
const addFieldOpen = ref(false);
const deletingId = ref<number | null>(null);

function reloadFields(items: ContactFieldDefinition[]): void {
    localFields.value = [...items];
}

async function deleteField(field: ContactFieldDefinition): Promise<void> {
    if (field.is_system || deletingId.value) {
        return;
    }
    if (!window.confirm(`Удалить поле «${field.label}»?`)) {
        return;
    }
    deletingId.value = field.id;
    try {
        const { data } = await axios.delete(route('settings.contact-fields.destroy', field.id));
        reloadFields(data.fields as ContactFieldDefinition[]);
        showToast({ message: 'Поле удалено', duration: 2500 });
    } catch {
        showToast({ message: 'Не удалось удалить поле', duration: 4000 });
    } finally {
        deletingId.value = null;
    }
}

function onFieldCreated(): void {
    addFieldOpen.value = false;
    void axios.get(route('settings.contact-fields.list')).then(({ data }) => {
        reloadFields(data.fields as ContactFieldDefinition[]);
    });
}
</script>

<template>
    <Head title="Поля контактов" />

    <SettingsLayout>
        <div class="mx-auto max-w-3xl space-y-4 p-6">
            <div>
                <h1 class="text-xl font-semibold">Поля контактов</h1>
                <p class="mt-1 text-sm opacity-70">
                    Настройте видимость полей в карточке клиента и добавляйте свои типы полей.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ background: 'var(--ui-accent)', color: '#fff' }"
                    @click="pickerOpen = true"
                >
                    Выбор полей
                </button>
                <button
                    type="button"
                    class="flex h-9 w-9 items-center justify-center rounded-lg text-lg leading-none"
                    :style="{ background: 'var(--ui-surface-muted)' }"
                    aria-label="Добавить поле"
                    title="Добавить поле"
                    @click="addFieldOpen = true"
                >
                    +
                </button>
                <Link :href="route('clients.index')" class="rounded-lg px-4 py-2 text-sm" :style="{ background: 'var(--ui-surface-muted)' }">
                    К клиентам
                </Link>
            </div>

            <div class="rounded-xl border overflow-hidden" :style="{ borderColor: 'var(--ui-border)' }">
                <table class="w-full text-sm">
                    <thead :style="{ background: 'var(--ui-surface-muted)' }">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium">Поле</th>
                            <th class="px-4 py-2 text-left font-medium">Тип</th>
                            <th class="px-4 py-2 text-left font-medium">Секция</th>
                            <th class="px-4 py-2 text-left font-medium">Видимость</th>
                            <th class="px-4 py-2 text-right font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="field in localFields"
                            :key="field.id"
                            class="border-t"
                            :style="{ borderColor: 'var(--ui-border)' }"
                        >
                            <td class="px-4 py-2">
                                {{ field.label }}
                                <span v-if="!field.is_system" class="ml-1 text-xs opacity-50">(своё)</span>
                            </td>
                            <td class="px-4 py-2 opacity-80">{{ field.type }}</td>
                            <td class="px-4 py-2 opacity-80">{{ field.section }}</td>
                            <td class="px-4 py-2">{{ field.is_visible ? 'Да' : 'Нет' }}</td>
                            <td class="px-4 py-2 text-right">
                                <button
                                    v-if="!field.is_system"
                                    type="button"
                                    class="text-xs text-[var(--ui-danger)] disabled:opacity-50"
                                    :disabled="deletingId === field.id"
                                    @click="deleteField(field)"
                                >
                                    Удалить
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ContactFieldPickerModal
            :open="pickerOpen"
            @close="pickerOpen = false"
            @updated="void axios.get(route('settings.contact-fields.list')).then(({ data }) => reloadFields(data.fields))"
        />
        <ContactAddFieldModal :open="addFieldOpen" @close="addFieldOpen = false" @created="onFieldCreated" />
    </SettingsLayout>
</template>
