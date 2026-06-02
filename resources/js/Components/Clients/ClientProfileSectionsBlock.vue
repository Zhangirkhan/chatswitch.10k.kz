<script setup lang="ts">
import ClientActivityTimeline from '@/Components/Clients/ClientActivityTimeline.vue';
import ClientFinancePlaceholder from '@/Components/Clients/ClientFinancePlaceholder.vue';
import ClientProfileSection from '@/Components/Clients/ClientProfileSection.vue';
import type { ClientProfile, ClientProfileField } from '@/Components/Clients/clientProfileTypes';
import { useI18n } from '@/composables/useI18n';

const props = withDefaults(
    defineProps<{
        profile: ClientProfile | null;
        loading?: boolean;
        error?: string | null;
        editable?: boolean;
        compact?: boolean;
        contactName?: string;
    }>(),
    {
        loading: false,
        error: null,
        editable: false,
        compact: false,
        contactName: '',
    },
);

const emit = defineEmits<{
    saveField: [field: ClientProfileField, value: unknown];
    uploadField: [field: ClientProfileField, file: File];
    clearField: [field: ClientProfileField];
}>();

const { t } = useI18n();

function sectionByKey(key: string) {
    return props.profile?.sections.find((section) => section.key === key) ?? null;
}
</script>

<template>
    <div class="space-y-3">
        <div v-if="loading" class="py-6 text-center text-sm opacity-70">{{ t('clients.detail.loadingProfile') }}</div>
        <div v-else-if="error" class="rounded-lg border px-4 py-3 text-sm" :style="{ borderColor: 'var(--ui-border)' }">
            {{ error }}
        </div>
        <template v-else-if="profile">
            <ClientProfileSection
                v-if="sectionByKey('basic')"
                :title="sectionByKey('basic')!.title"
                :semantic="sectionByKey('basic')!.semantic"
                :fields="sectionByKey('basic')!.fields"
                :editable="editable"
                :compact="compact"
                :contact-id="profile.contact_id"
                :contact-name="contactName"
                @save-field="(f, v) => emit('saveField', f, v)"
                @upload-field="(f, file) => emit('uploadField', f, file)"
                @clear-field="(f) => emit('clearField', f)"
            />
            <ClientProfileSection
                v-if="sectionByKey('contacts')"
                :title="sectionByKey('contacts')!.title"
                :semantic="sectionByKey('contacts')!.semantic"
                :fields="sectionByKey('contacts')!.fields"
                :editable="editable"
                :compact="compact"
                :contact-id="profile.contact_id"
                :contact-name="contactName"
                @save-field="(f, v) => emit('saveField', f, v)"
                @upload-field="(f, file) => emit('uploadField', f, file)"
                @clear-field="(f) => emit('clearField', f)"
            />
            <ClientProfileSection
                v-if="sectionByKey('finance') && !compact"
                :title="sectionByKey('finance')!.title"
                :semantic="sectionByKey('finance')!.semantic"
                :fields="[]"
                :default-open="true"
            >
                <ClientFinancePlaceholder :message="sectionByKey('finance')!.message" />
            </ClientProfileSection>
            <ClientProfileSection
                v-if="sectionByKey('b2b')"
                :title="sectionByKey('b2b')!.title"
                :semantic="sectionByKey('b2b')!.semantic"
                :fields="sectionByKey('b2b')!.fields"
                :editable="editable"
                :compact="compact"
                :contact-id="profile.contact_id"
                :contact-name="contactName"
                @save-field="(f, v) => emit('saveField', f, v)"
                @upload-field="(f, file) => emit('uploadField', f, file)"
                @clear-field="(f) => emit('clearField', f)"
            />
            <ClientProfileSection
                v-if="sectionByKey('history') && !compact"
                :title="sectionByKey('history')!.title"
                :semantic="sectionByKey('history')!.semantic"
                :fields="sectionByKey('history')!.fields"
            >
                <ClientActivityTimeline :items="sectionByKey('history')!.activity || []" />
            </ClientProfileSection>
            <ClientProfileSection
                v-if="sectionByKey('tasks_notes')"
                :title="sectionByKey('tasks_notes')!.title"
                :semantic="sectionByKey('tasks_notes')!.semantic"
                :fields="sectionByKey('tasks_notes')!.fields"
                :editable="editable"
                :compact="compact"
                :contact-id="profile.contact_id"
                :contact-name="contactName"
                @save-field="(f, v) => emit('saveField', f, v)"
                @upload-field="(f, file) => emit('uploadField', f, file)"
                @clear-field="(f) => emit('clearField', f)"
            />
        </template>
    </div>
</template>
