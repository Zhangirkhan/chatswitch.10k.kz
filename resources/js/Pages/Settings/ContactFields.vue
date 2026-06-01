<script setup lang="ts">
import ContactAddFieldModal from '@/Components/Clients/ContactAddFieldModal.vue';
import ContactFieldPickerModal from '@/Components/Clients/ContactFieldPickerModal.vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import type { ContactFieldDefinition, ContactFieldTypeOption } from '@/utils/contactFieldTypes';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    fields: ContactFieldDefinition[];
    field_types: ContactFieldTypeOption[];
    groups: Record<string, string>;
}>();

const pickerOpen = ref(true);
const addFieldOpen = ref(false);
</script>

<template>
    <Head title="Поля контактов" />

    <SettingsLayout>
        <div class="mx-auto max-w-3xl space-y-4 p-6">
            <div>
                <h1 class="text-xl font-semibold">Поля контактов</h1>
                <p class="mt-1 text-sm opacity-70">
                    Настройте, какие поля показывать в карточке клиента, и добавляйте свои — как в Bitrix24.
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
                    class="rounded-lg px-4 py-2 text-sm"
                    :style="{ background: 'var(--ui-surface-muted)' }"
                    @click="addFieldOpen = true"
                >
                    + Добавить поле
                </button>
                <Link :href="route('clients.index')" class="rounded-lg px-4 py-2 text-sm" :style="{ background: 'var(--ui-surface-muted)' }">
                    К клиентам
                </Link>
            </div>

            <p class="text-sm opacity-70">
                В карточке клиента администратор также может открыть «Поля» и «+ Поле» прямо из шапки модального окна.
            </p>
        </div>

        <ContactFieldPickerModal :open="pickerOpen" @close="pickerOpen = false" />
        <ContactAddFieldModal :open="addFieldOpen" @close="addFieldOpen = false" />
    </SettingsLayout>
</template>
