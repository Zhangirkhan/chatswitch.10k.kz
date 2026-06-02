<script setup lang="ts">
import UiModal from '@/Components/Ui/UiModal.vue';
import { useI18n } from '@/composables/useI18n';
import { ADDABLE_FIELD_TYPES, type ContactFieldTypeId } from '@/utils/contactFieldTypes';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { useToastStore } from '@/stores/toast';

const props = defineProps<{
    open: boolean;
    presetType?: ContactFieldTypeId | null;
}>();

const emit = defineEmits<{
    close: [];
    created: [];
}>();

const { show: showToast } = useToastStore();
const { t } = useI18n();

const step = ref<'pick' | 'form'>('pick');
const saving = ref(false);
const selectedType = ref<ContactFieldTypeId>('string');
const form = ref({
    label: '',
    section: 'contacts',
    choices: '',
});

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
            step.value = props.presetType ? 'form' : 'pick';
            selectedType.value = props.presetType || 'string';
            form.value = { label: '', section: 'contacts', choices: '' };
            return;
        }
        if (props.presetType) {
            step.value = 'form';
            selectedType.value = props.presetType;
        } else {
            step.value = 'pick';
        }
    },
);

const selectedTypeMeta = computed(() => ADDABLE_FIELD_TYPES.find((row) => row.id === selectedType.value));

const modalTitle = computed(() =>
    step.value === 'pick' ? t('clients.fields.addModalPick') : t('clients.fields.addModalNew'),
);

function pickType(type: ContactFieldTypeId): void {
    selectedType.value = type;
    step.value = 'form';
}

async function createField(): Promise<void> {
    const label = form.value.label.trim();
    if (!label) {
        showToast({ message: t('clients.fields.toastNameRequired'), duration: 3000 });
        return;
    }

    saving.value = true;
    try {
        const payload: Record<string, unknown> = {
            label,
            type: selectedType.value,
            section: form.value.section,
            group: 'additional',
        };

        if (selectedType.value === 'list') {
            const choices = form.value.choices
                .split('\n')
                .map((row) => row.trim())
                .filter(Boolean);
            payload.options = { choices };
        }

        await axios.post(route('settings.contact-fields.store'), payload);
        showToast({ message: t('clients.fields.toastCreated'), duration: 2500 });
        emit('created');
        emit('close');
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } };
        const message = err.response?.data?.errors?.label?.[0]
            || err.response?.data?.message
            || t('clients.fields.toastCreateError');
        showToast({ message, duration: 4000 });
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <UiModal
        :open="open"
        :title="modalTitle"
        max-width="md"
        :z-index="1300"
        @close="emit('close')"
    >
        <div v-if="step === 'pick'" class="max-h-[420px] space-y-1 overflow-y-auto">
            <button
                v-for="type in ADDABLE_FIELD_TYPES"
                :key="type.id"
                type="button"
                class="flex w-full flex-col rounded-lg px-3 py-2.5 text-left hover:bg-[var(--ui-surface-muted)]"
                @click="pickType(type.id)"
            >
                <span class="text-sm font-medium">{{ type.label }}</span>
                <span v-if="type.description" class="text-xs opacity-70">{{ type.description }}</span>
            </button>
        </div>

        <form v-else class="space-y-4" @submit.prevent="createField">
            <div v-if="selectedTypeMeta" class="rounded-lg px-3 py-2 text-sm" :style="{ background: 'var(--ui-surface-muted)' }">
                <div class="font-medium">{{ selectedTypeMeta.label }}</div>
                <div v-if="selectedTypeMeta.description" class="text-xs opacity-70">{{ selectedTypeMeta.description }}</div>
            </div>

            <label class="block space-y-1">
                <span class="text-xs opacity-70">{{ t('clients.fields.fieldName') }}</span>
                <input
                    v-model="form.label"
                    type="text"
                    class="w-full rounded-lg border-0 px-3 py-2 text-sm focus:ring-0 focus:outline-none"
                    :style="{ background: 'var(--ui-surface-muted)' }"
                    :placeholder="t('clients.fields.fieldNamePlaceholder')"
                />
            </label>

            <label class="block space-y-1">
                <span class="text-xs opacity-70">{{ t('clients.fields.section') }}</span>
                <select
                    v-model="form.section"
                    class="w-full rounded-lg border-0 px-3 py-2 text-sm focus:ring-0 focus:outline-none"
                    :style="{ background: 'var(--ui-surface-muted)' }"
                >
                    <option value="basic">{{ t('clients.fields.sectionBasic') }}</option>
                    <option value="contacts">{{ t('clients.fields.sectionContacts') }}</option>
                    <option value="b2b">B2B</option>
                    <option value="tasks_notes">{{ t('clients.fields.sectionTasksNotes') }}</option>
                </select>
            </label>

            <label v-if="selectedType === 'list'" class="block space-y-1">
                <span class="text-xs opacity-70">{{ t('clients.fields.listValues') }}</span>
                <textarea
                    v-model="form.choices"
                    rows="4"
                    class="w-full rounded-lg border-0 px-3 py-2 text-sm focus:ring-0 focus:outline-none"
                    :style="{ background: 'var(--ui-surface-muted)' }"
                />
            </label>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" class="rounded-lg px-3 py-2 text-sm" :style="{ background: 'var(--ui-surface-muted)' }" @click="step = 'pick'">
                    {{ t('clients.fields.back') }}
                </button>
                <button
                    type="submit"
                    class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                    :style="{ background: 'var(--ui-accent)', color: '#fff' }"
                    :disabled="saving"
                >
                    {{ t('clients.fields.create') }}
                </button>
            </div>
        </form>
    </UiModal>
</template>
