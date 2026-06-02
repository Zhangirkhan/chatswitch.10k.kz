<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { computed, ref } from 'vue';

export type FilterFieldDef = {
    id: number;
    code: string;
    label: string;
    type: string;
    section?: string;
    group?: string;
    options?: { items?: string[]; values?: string[] } | null;
};

export type FunnelStageOption = { id: number; name: string; color: string | null };
export type AssigneeOption = { id: number; name: string };

const props = defineProps<{
    fields: FilterFieldDef[];
    funnelStages: FunnelStageOption[];
    assigneeOptions: AssigneeOption[];
    modelValue: Record<string, string>;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: Record<string, string>];
    apply: [];
    reset: [];
}>();

const { t } = useI18n();

const open = ref(false);

const activeCount = computed(() => Object.values(props.modelValue).filter((v) => String(v).trim() !== '').length);

function setField(code: string, value: string): void {
    const next = { ...props.modelValue };
    const trimmed = value.trim();
    if (trimmed === '') {
        delete next[code];
    } else {
        next[code] = trimmed;
    }
    emit('update:modelValue', next);
    emit('apply');
}

function fieldValue(code: string): string {
    return props.modelValue[code] ?? '';
}

function listOptions(field: FilterFieldDef): string[] {
    const opts = field.options;
    if (!opts || typeof opts !== 'object') {
        return [];
    }
    const items = Array.isArray(opts.items) ? opts.items : Array.isArray(opts.values) ? opts.values : [];

    return items.map((item) => String(item)).filter((item) => item !== '');
}

function isSelectField(field: FilterFieldDef): boolean {
    if (field.code === 'funnel_stage' || field.code === 'assignee' || field.code === 'b2b_type') {
        return true;
    }

    return field.type === 'boolean' || field.type === 'list';
}

function inputType(field: FilterFieldDef): string {
    if (field.type === 'number' || field.type === 'money') {
        return 'number';
    }
    if (field.type === 'date') {
        return 'date';
    }
    if (field.type === 'datetime') {
        return 'datetime-local';
    }

    return 'text';
}
</script>

<template>
    <div class="space-y-2">
        <div class="flex flex-wrap items-center gap-[var(--primitive-gap-sm)]">
            <button
                type="button"
                class="ui-btn ui-btn--secondary ui-btn--sm"
                :class="{ 'ui-btn--accent-soft': open }"
                @click="open = !open"
            >
                {{ t('clients.filter.title') }}
                <span v-if="activeCount > 0" class="ui-tab-badge ui-tab-badge--neutral">{{ activeCount }}</span>
            </button>
            <button
                v-if="activeCount > 0"
                type="button"
                class="ui-btn ui-btn--ghost ui-btn--sm"
                @click="emit('reset')"
            >
                {{ t('clients.filter.reset') }}
            </button>
        </div>

        <div
            v-show="open"
            class="ui-filter-panel !max-w-none"
        >
            <div
                v-for="field in fields"
                :key="field.code"
                class="ui-filter-field"
            >
                <label class="ui-filter-field__label" :for="`client-filter-${field.code}`">{{ field.label }}</label>

                <select
                    v-if="field.code === 'funnel_stage'"
                    :id="`client-filter-${field.code}`"
                    class="ui-select"
                    :value="fieldValue(field.code)"
                    @change="setField(field.code, ($event.target as HTMLSelectElement).value)"
                >
                    <option value="">{{ t('clients.filter.allStages') }}</option>
                    <option v-for="stage in funnelStages" :key="stage.id" :value="String(stage.id)">
                        {{ stage.name }}
                    </option>
                </select>

                <select
                    v-else-if="field.code === 'assignee'"
                    :id="`client-filter-${field.code}`"
                    class="ui-select"
                    :value="fieldValue(field.code)"
                    @change="setField(field.code, ($event.target as HTMLSelectElement).value)"
                >
                    <option value="">{{ t('clients.filter.any') }}</option>
                    <option v-for="user in assigneeOptions" :key="user.id" :value="String(user.id)">
                        {{ user.name }}
                    </option>
                </select>

                <select
                    v-else-if="field.code === 'b2b_type'"
                    :id="`client-filter-${field.code}`"
                    class="ui-select"
                    :value="fieldValue(field.code)"
                    @change="setField(field.code, ($event.target as HTMLSelectElement).value)"
                >
                    <option value="">{{ t('clients.filter.anyType') }}</option>
                    <option value="B2B">B2B</option>
                    <option value="B2C">B2C</option>
                </select>

                <select
                    v-else-if="field.type === 'boolean'"
                    :id="`client-filter-${field.code}`"
                    class="ui-select"
                    :value="fieldValue(field.code)"
                    @change="setField(field.code, ($event.target as HTMLSelectElement).value)"
                >
                    <option value="">{{ t('clients.filter.anyValue') }}</option>
                    <option value="1">{{ t('common.yes') }}</option>
                    <option value="0">{{ t('common.no') }}</option>
                </select>

                <select
                    v-else-if="field.type === 'list' && listOptions(field).length > 0"
                    :id="`client-filter-${field.code}`"
                    class="ui-select"
                    :value="fieldValue(field.code)"
                    @change="setField(field.code, ($event.target as HTMLSelectElement).value)"
                >
                    <option value="">{{ t('clients.filter.anyValue') }}</option>
                    <option v-for="opt in listOptions(field)" :key="opt" :value="opt">{{ opt }}</option>
                </select>

                <input
                    v-else
                    :id="`client-filter-${field.code}`"
                    class="ui-input"
                    :type="inputType(field)"
                    :value="fieldValue(field.code)"
                    :placeholder="t('clients.filter.searchField', { label: field.label.toLowerCase() })"
                    @change="setField(field.code, ($event.target as HTMLInputElement).value)"
                />
            </div>
        </div>
    </div>
</template>
