<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    canResetPassword?: boolean;
    status?: string;
}>();

const page = usePage();
const flashError = computed(() => {
    const flash = page.props.flash as { error?: string } | undefined;
    return flash?.error ?? null;
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Вход" />

        <div v-if="status" class="mb-4 text-sm font-medium text-[var(--wa-accent)]">
            {{ status }}
        </div>

        <div v-if="flashError" class="mb-4 text-sm font-medium text-red-400">
            {{ flashError }}
        </div>

        <form @submit.prevent="submit" class="space-y-5">
            <div>
                <label for="email" class="block text-sm text-[var(--wa-text-secondary)] mb-1">Электронная почта</label>
                <input
                    id="email"
                    type="email"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                    class="w-full px-3 py-2.5 rounded-lg text-sm border transition focus:outline-none"
                    :style="{
                        background: 'var(--wa-panel-input)',
                        color: 'var(--wa-text)',
                        borderColor: 'var(--wa-border)'
                    }"
                    placeholder="admin@chatswitch.10k.kz"
                />
                <p v-if="form.errors.email" class="mt-1 text-xs text-red-400">{{ form.errors.email }}</p>
            </div>

            <div>
                <label for="password" class="block text-sm text-[var(--wa-text-secondary)] mb-1">Пароль</label>
                <input
                    id="password"
                    type="password"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                    class="w-full px-3 py-2.5 rounded-lg text-sm border transition focus:outline-none"
                    :style="{
                        background: 'var(--wa-panel-input)',
                        color: 'var(--wa-text)',
                        borderColor: 'var(--wa-border)'
                    }"
                    placeholder="Введите пароль"
                />
                <p v-if="form.errors.password" class="mt-1 text-xs text-red-400">{{ form.errors.password }}</p>
            </div>

            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input
                        type="checkbox"
                        v-model="form.remember"
                        class="w-4 h-4 rounded text-[var(--wa-accent)] focus:ring-[var(--wa-accent)]"
                    />
                    <span class="text-sm text-[var(--wa-text-secondary)]">Запомнить</span>
                </label>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full py-2.5 text-white rounded-lg text-sm font-medium transition disabled:opacity-50"
                :style="{ background: 'var(--wa-accent)' }"
            >
                <span v-if="form.processing">Загрузка...</span>
                <span v-else>Войти</span>
            </button>
        </form>
    </GuestLayout>
</template>
