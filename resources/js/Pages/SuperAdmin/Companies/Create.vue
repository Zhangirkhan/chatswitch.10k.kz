<script setup lang="ts">
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps<{ plans: Array<{ id: number; name: string }>; reservedSlugs: string[] }>();

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
        <Head title="Новая компания" />
        <h1 class="mb-6 text-xl font-bold sm:text-2xl">Создать компанию</h1>
        <form class="ui-settings-section ui-settings-section--narrow mx-auto w-full space-y-4" @submit.prevent="form.post('/companies')">
            <label class="block">
                <span class="text-sm text-ui-text-secondary">Название</span>
                <input v-model="form.name" class="ui-input mt-1" required />
            </label>
            <label class="block">
                <span class="text-sm text-ui-text-secondary">Slug (поддомен)</span>
                <input v-model="form.slug" class="ui-input mt-1" pattern="[a-z0-9-]+" required />
                <p v-if="form.errors.slug" class="mt-1 text-xs text-ui-danger">{{ form.errors.slug }}</p>
            </label>
            <label class="block">
                <span class="text-sm text-ui-text-secondary">Телефон</span>
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
                <span class="text-sm text-ui-text-secondary">Тариф</span>
                <select v-model="form.plan_id" class="ui-select mt-1">
                    <option :value="null">По умолчанию</option>
                    <option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
            </label>
            <label class="block">
                <span class="text-sm text-ui-text-secondary">Владелец — имя</span>
                <input v-model="form.owner_name" class="ui-input mt-1" required />
            </label>
            <label class="block">
                <span class="text-sm text-ui-text-secondary">Владелец — email</span>
                <input v-model="form.owner_email" type="email" class="ui-input mt-1" required />
            </label>
            <button type="submit" class="ui-btn ui-btn--primary" :disabled="form.processing">Создать</button>
        </form>
    </SuperAdminLayout>
</template>
