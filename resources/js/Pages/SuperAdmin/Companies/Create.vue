<script setup lang="ts">
import SuperAdminPageHeader from '@/Components/SuperAdmin/SuperAdminPageHeader.vue';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { Head, useForm } from '@inertiajs/vue3';

defineProps<{ plans: Array<{ id: number; name: string }>; reservedSlugs: string[] }>();

const { t } = useI18n();

const form = useForm({
    name: '',
    slug: '',
    phone: '',
    plan_id: null as number | null,
    owner_name: '',
    owner_email: '',
});
</script>

<template>
    <SuperAdminLayout>
        <Head :title="t('superAdmin.companies.create.pageTitle')" />
        <SuperAdminPageHeader
            accent-group="operations"
            :eyebrow="t('superAdmin.layout.navGroups.operations')"
            :title="t('superAdmin.companies.create.heading')"
        />
        <form class="ui-panel mx-auto w-full max-w-xl space-y-4 p-5 sm:p-6" @submit.prevent="form.post('/companies')">
            <label class="block">
                <span class="text-sm text-ui-text-secondary">{{ t('superAdmin.companies.field.name') }}</span>
                <input v-model="form.name" class="ui-input mt-1" required />
            </label>
            <label class="block">
                <span class="text-sm text-ui-text-secondary">{{ t('superAdmin.companies.field.slug') }}</span>
                <input v-model="form.slug" class="ui-input mt-1" pattern="[a-z0-9-]+" required />
                <p v-if="form.errors.slug" class="mt-1 text-xs text-ui-danger">{{ form.errors.slug }}</p>
            </label>
            <label class="block">
                <span class="text-sm text-ui-text-secondary">{{ t('superAdmin.companies.field.phone') }}</span>
                <input
                    v-model="form.phone"
                    type="tel"
                    class="ui-input mt-1"
                    placeholder="+7 747 123 45 67"
                    autocomplete="tel"
                    required
                />
                <p v-if="form.errors.phone" class="mt-1 text-xs text-ui-danger">{{ form.errors.phone }}</p>
            </label>
            <label class="block">
                <span class="text-sm text-ui-text-secondary">{{ t('superAdmin.companies.field.plan') }}</span>
                <select v-model="form.plan_id" class="ui-select mt-1">
                    <option :value="null">{{ t('superAdmin.companies.planDefault') }}</option>
                    <option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
            </label>
            <label class="block">
                <span class="text-sm text-ui-text-secondary">{{ t('superAdmin.companies.field.ownerName') }}</span>
                <input v-model="form.owner_name" class="ui-input mt-1" required />
            </label>
            <label class="block">
                <span class="text-sm text-ui-text-secondary">{{ t('superAdmin.companies.field.ownerEmail') }}</span>
                <input v-model="form.owner_email" type="email" class="ui-input mt-1" required />
            </label>
            <button type="submit" class="ui-btn ui-btn--primary" :disabled="form.processing">{{ t('superAdmin.common.create') }}</button>
        </form>
    </SuperAdminLayout>
</template>
