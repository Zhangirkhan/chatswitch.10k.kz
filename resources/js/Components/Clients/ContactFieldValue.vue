<script setup lang="ts">
import type { ClientProfileField } from '@/Components/Clients/clientProfileTypes';
import UserAvatar from '@/Components/UserAvatar.vue';
import { MONEY_CURRENCIES } from '@/utils/contactFieldTypes';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        field: ClientProfileField;
        editable?: boolean;
        compact?: boolean;
        contactId?: number | null;
        contactName?: string;
    }>(),
    {
        editable: false,
        compact: false,
        contactId: null,
        contactName: '',
    },
);

const emit = defineEmits<{
    save: [field: ClientProfileField, value: unknown];
    upload: [field: ClientProfileField, file: File];
    clear: [field: ClientProfileField];
}>();

const uploading = ref(false);

const fieldType = computed(() => props.field.type || 'string');
const previewUrl = computed(() => props.field.preview_url || null);
const isEditable = computed(() => props.editable && props.field.editable && !!props.field.definition_id);

function textDraft(): string {
    const raw = props.field.raw_value;
    if (typeof raw === 'string' && raw !== '') {
        return raw;
    }
    return props.field.value === '—' ? '' : props.field.value;
}

function moneyDraft(): { amount: string; currency: string } {
    const raw = props.field.raw_value;
    if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
        return {
            amount: String((raw as Record<string, unknown>).amount ?? ''),
            currency: String((raw as Record<string, unknown>).currency ?? 'KZT'),
        };
    }

    const parts = textDraft().split(/\s+/);
    if (parts.length >= 2) {
        return { amount: parts[0] ?? '', currency: parts[1] ?? 'KZT' };
    }

    return { amount: textDraft(), currency: 'KZT' };
}

function booleanDraft(): string {
    if (props.field.value === 'Да') {
        return '1';
    }
    if (props.field.value === 'Нет') {
        return '0';
    }
    return '';
}

function onBlur(event: Event): void {
    const target = event.target as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement;
    emit('save', props.field, target.value);
}

function onBooleanChange(event: Event): void {
    const target = event.target as HTMLSelectElement;
    if (target.value === '') {
        emit('save', props.field, null);
        return;
    }
    emit('save', props.field, target.value === '1');
}

function onMoneyBlur(amount: string, currency: string): void {
    if (!amount.trim()) {
        emit('save', props.field, null);
        return;
    }
    emit('save', props.field, { amount: amount.trim(), currency });
}

function onFileSelected(event: Event): void {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];
    target.value = '';
    if (!file) {
        return;
    }
    uploading.value = true;
    emit('upload', props.field, file);
    uploading.value = false;
}

function fileUrl(): string | null {
    const json = props.field.value_json;
    if (json && typeof json.url === 'string') {
        return json.url;
    }
    return previewUrl.value;
}
</script>

<template>
    <div class="min-w-0">
        <template v-if="!isEditable">
            <div v-if="fieldType === 'photo' && previewUrl" class="flex items-center gap-2">
                <UserAvatar :name="contactName || field.label" :src="previewUrl" :size="compact ? 36 : 48" />
                <span v-if="!compact" class="text-xs opacity-70">WhatsApp / загружено</span>
            </div>
            <a
                v-else-if="fieldType === 'link' && textDraft()"
                :href="textDraft()"
                target="_blank"
                rel="noopener noreferrer"
                class="break-all text-[var(--ui-accent)] underline"
            >
                {{ textDraft() }}
            </a>
            <a
                v-else-if="(fieldType === 'file' || fieldType === 'photo') && fileUrl()"
                :href="fileUrl()!"
                target="_blank"
                rel="noopener noreferrer"
                class="break-all text-[var(--ui-accent)] underline"
            >
                {{ field.value !== '—' ? field.value : 'Открыть файл' }}
            </a>
            <div v-else class="whitespace-pre-wrap break-words">{{ field.value }}</div>
        </template>

        <template v-else>
            <div v-if="fieldType === 'photo' || fieldType === 'file'" class="space-y-2">
                <div v-if="previewUrl && fieldType === 'photo'" class="flex items-center gap-3">
                    <UserAvatar :name="contactName || field.label" :src="previewUrl" :size="compact ? 40 : 56" />
                </div>
                <div v-else-if="fileUrl() && fieldType === 'file'" class="text-sm">
                    <a :href="fileUrl()!" target="_blank" rel="noopener noreferrer" class="text-[var(--ui-accent)] underline">
                        {{ textDraft() || 'Текущий файл' }}
                    </a>
                </div>
                <div class="flex flex-wrap gap-2">
                    <label class="ui-btn ui-btn--secondary ui-btn--sm cursor-pointer">
                        {{ uploading ? 'Загрузка…' : 'Загрузить' }}
                        <input
                            type="file"
                            class="hidden"
                            :accept="fieldType === 'photo' ? 'image/*' : undefined"
                            :disabled="uploading"
                            @change="onFileSelected"
                        />
                    </label>
                    <button
                        v-if="previewUrl || fileUrl()"
                        type="button"
                        class="ui-btn ui-btn--ghost ui-btn--sm"
                        @click="emit('clear', field)"
                    >
                        Удалить
                    </button>
                </div>
            </div>

            <select
                v-else-if="fieldType === 'boolean'"
                class="ui-select ui-field-control"
                :value="booleanDraft()"
                @change="onBooleanChange"
            >
                <option value="">—</option>
                <option value="1">Да</option>
                <option value="0">Нет</option>
            </select>

            <select
                v-else-if="fieldType === 'list' && field.options?.choices?.length"
                class="ui-select ui-field-control"
                :value="textDraft()"
                @change="onBlur"
            >
                <option value="">—</option>
                <option v-for="choice in field.options.choices" :key="choice" :value="choice">{{ choice }}</option>
            </select>

            <div v-else-if="fieldType === 'money'" class="flex gap-2">
                <input
                    type="text"
                    class="ui-input ui-field-control min-w-0 flex-1"
                    :value="moneyDraft().amount"
                    placeholder="Сумма"
                    @blur="onMoneyBlur(($event.target as HTMLInputElement).value, moneyDraft().currency)"
                />
                <select
                    class="ui-select ui-field-control max-w-[7rem]"
                    :value="moneyDraft().currency"
                    @change="onMoneyBlur(moneyDraft().amount, ($event.target as HTMLSelectElement).value)"
                >
                    <option v-for="currency in MONEY_CURRENCIES" :key="currency" :value="currency">{{ currency }}</option>
                </select>
            </div>

            <textarea
                v-else-if="fieldType === 'text' || fieldType === 'address'"
                rows="2"
                class="ui-input ui-field-control"
                :value="textDraft()"
                @blur="onBlur"
            />

            <input
                v-else
                :type="fieldType === 'number' ? 'number' : fieldType === 'date' ? 'date' : fieldType === 'datetime' ? 'datetime-local' : fieldType === 'link' ? 'url' : 'text'"
                class="ui-input ui-field-control"
                :value="textDraft()"
                @blur="onBlur"
            />
        </template>
    </div>
</template>

<style scoped>
.ui-field-control {
    min-height: 34px;
    border-radius: 10px;
    font-size: 0.875rem;
}

textarea.ui-field-control {
    min-height: 4.5rem;
    resize: vertical;
}
</style>
