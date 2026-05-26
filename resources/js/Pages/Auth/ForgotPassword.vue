<script setup lang="ts">
import AuthRecaptcha from '@/Components/Recaptcha/AuthRecaptcha.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useRecaptcha } from '@/composables/useRecaptcha';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    status?: string;
}>();

const { enabled: recaptchaEnabled } = useRecaptcha();
const recaptchaRef = ref<InstanceType<typeof AuthRecaptcha> | null>(null);

const form = useForm({
    email: '',
    recaptcha_token: '',
});

const submit = async () => {
    if (recaptchaEnabled.value) {
        try {
            form.recaptcha_token = (await recaptchaRef.value?.resolveToken('forgot_password')) ?? '';
        } catch {
            form.setError('recaptcha_token', 'Не удалось загрузить reCAPTCHA. Обновите страницу.');
            return;
        }

        if (!form.recaptcha_token) {
            form.setError('recaptcha_token', 'Подтвердите, что вы не робот.');
            return;
        }
    }

    form.post(route('password.email'), {
        onFinish: () => recaptchaRef.value?.reset(),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Forgot Password" />

        <div class="mb-4 text-sm text-gray-600">
            Forgot your password? No problem. Just let us know your email
            address and we will email you a password reset link that will allow
            you to choose a new one.
        </div>

        <div
            v-if="status"
            class="mb-4 text-sm font-medium text-[var(--wa-accent)]"
        >
            {{ status }}
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="email" value="Email" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="mt-4">
                <AuthRecaptcha ref="recaptchaRef" />
                <InputError class="mt-2" :message="form.errors.recaptcha_token" />
            </div>

            <div class="mt-4 flex items-center justify-end">
                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Email Password Reset Link
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
