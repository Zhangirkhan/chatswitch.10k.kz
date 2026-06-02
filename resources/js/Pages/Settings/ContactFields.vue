<script setup lang="ts">
import ContactAddFieldModal from '@/Components/Clients/ContactAddFieldModal.vue';
import ContactFieldPickerModal from '@/Components/Clients/ContactFieldPickerModal.vue';
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { useI18n } from '@/composables/useI18n';
import type { ContactFieldDefinition, ContactFieldTypeOption } from '@/utils/contactFieldTypes';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';
import { useToastStore } from '@/stores/toast';

const props = defineProps<{
    fields: ContactFieldDefinition[];
    field_types: ContactFieldTypeOption[];
    groups: Record<string, string>;
}>();

const { show: showToast } = useToastStore();
const { t } = useI18n();
const localFields = ref<ContactFieldDefinition[]>([...props.fields]);
const pickerOpen = ref(false);
const addFieldOpen = ref(false);
const deletingId = ref<number | null>(null);
const fieldPendingDelete = ref<ContactFieldDefinition | null>(null);

const deleteDescription = computed(() => {
    const field = fieldPendingDelete.value;
    if (!field) return '';
    return t('settings.contactFields.deleteDescription', { label: field.label });
});

function reloadFields(items: ContactFieldDefinition[]): void {
    localFields.value = [...items];
}

function deleteField(field: ContactFieldDefinition): void {
    if (field.is_system || deletingId.value) {
        return;
    }
    fieldPendingDelete.value = field;
}

async function confirmDeleteField(): Promise<void> {
    const field = fieldPendingDelete.value;
    fieldPendingDelete.value = null;
    if (field === null) {
        return;
    }

    deletingId.value = field.id;
    try {
        const { data } = await axios.delete(route('settings.contact-fields.destroy', field.id));
        reloadFields(data.fields as ContactFieldDefinition[]);
        showToast({ message: t('settings.contactFields.toastDeleted'), duration: 2500 });
    } catch {
        showToast({ message: t('settings.contactFields.toastDeleteError'), duration: 4000 });
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
    <Head :title="t('settings.contactFields.title')" />

    <SettingsLayout>
        <div class="mx-auto max-w-3xl space-y-4 p-6">
            <div>
                <h1 class="text-xl font-semibold">{{ t('settings.contactFields.title') }}</h1>
                <p class="mt-1 text-sm opacity-70">
                    {{ t('settings.contactFields.subtitle') }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-[var(--primitive-gap-sm)]">
                <button
                    type="button"
                    class="ui-btn ui-btn--primary"
                    @click="pickerOpen = true"
                >
                    {{ t('settings.contactFields.pickFields') }}
                </button>
                <button
                    type="button"
                    class="ui-btn ui-btn--primary ui-btn--icon text-lg leading-none"
                    :aria-label="t('settings.contactFields.addField')"
                    :title="t('settings.contactFields.addField')"
                    @click="addFieldOpen = true"
                >
                    +
                </button>
                <Link :href="route('clients.index')" class="ui-btn ui-btn--secondary">
                    {{ t('settings.contactFields.goToClients') }}
                </Link>
            </div>

            <div class="rounded-xl border overflow-x-auto" :style="{ borderColor: 'var(--ui-border)' }">
                <table class="w-full min-w-[560px] text-sm">
                    <thead :style="{ background: 'var(--ui-surface-muted)' }">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium">{{ t('settings.contactFields.colField') }}</th>
                            <th class="px-4 py-2 text-left font-medium">{{ t('settings.contactFields.colType') }}</th>
                            <th class="px-4 py-2 text-left font-medium">{{ t('settings.contactFields.colSection') }}</th>
                            <th class="px-4 py-2 text-left font-medium">{{ t('settings.contactFields.colVisibility') }}</th>
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
                                <span v-if="!field.is_system" class="ml-1 text-xs opacity-50">{{ t('settings.contactFields.customBadge') }}</span>
                            </td>
                            <td class="px-4 py-2 opacity-80">{{ field.type }}</td>
                            <td class="px-4 py-2 opacity-80">{{ field.section }}</td>
                            <td class="px-4 py-2">{{ field.is_visible ? t('settings.contactFields.visibleYes') : t('settings.contactFields.visibleNo') }}</td>
                            <td class="px-4 py-2 text-right">
                                <button
                                    v-if="!field.is_system"
                                    type="button"
                                    class="text-xs text-[var(--ui-danger)] disabled:opacity-50"
                                    :disabled="deletingId === field.id"
                                    @click="deleteField(field)"
                                >
                                    {{ t('common.delete') }}
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

        <DangerConfirmModal
            :open="fieldPendingDelete !== null"
            :title="t('settings.contactFields.deleteTitle')"
            :description="deleteDescription"
            :confirm-label="t('common.delete')"
            @close="fieldPendingDelete = null"
            @confirm="confirmDeleteField"
        />
    </SettingsLayout>
</template>
