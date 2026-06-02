<script setup lang="ts">
import AuthRecaptcha from '@/Components/Recaptcha/AuthRecaptcha.vue';
import PinLoginPad from '@/Components/Auth/PinLoginPad.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import { useI18n } from '@/composables/useI18n';
import { useRecaptcha } from '@/composables/useRecaptcha';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    canResetPassword?: boolean;
    status?: string;
    pinLoginAvailable?: boolean;
}>();

const page = usePage();
const { t } = useI18n();
const flashError = computed(() => {
    const flash = page.props.flash as { error?: string } | undefined;
    return flash?.error ?? null;
});

const isSuperAdminHost = computed(
    () => (page.props as { isSuperAdminHost?: boolean }).isSuperAdminHost === true,
);

const loginMode = ref<'email' | 'pin'>('email');

const loginAction = computed(() => {
    if (isSuperAdminHost.value) {
        return route('super.login.attempt');
    }

    const slug = (page.props as { tenantSlug?: string | null }).tenantSlug;
    if (!slug) {
        throw new Error(t('auth.tenantSlugError'));
    }

    return route('login', { tenant: slug });
});

const pinLoginAction = computed(() => {
    const slug = (page.props as { tenantSlug?: string | null }).tenantSlug;
    if (!slug) {
        throw new Error(t('auth.tenantSlugError'));
    }

    return route('login.pin', { tenant: slug });
});

const { enabled: recaptchaEnabled } = useRecaptcha();
const recaptchaRef = ref<InstanceType<typeof AuthRecaptcha> | null>(null);

const form = useForm({
    email: '',
    password: '',
    remember: false,
    recaptcha_token: '',
});

const pinForm = useForm({
    pin: '',
    remember: false,
});

const submit = async () => {
    if (recaptchaEnabled.value) {
        try {
            form.recaptcha_token = (await recaptchaRef.value?.resolveToken('login')) ?? '';
        } catch {
            form.setError('recaptcha_token', t('auth.recaptchaLoadError'));
            return;
        }

        if (!form.recaptcha_token) {
            form.setError('recaptcha_token', t('auth.recaptchaRequired'));
            return;
        }
    }

    form.post(loginAction.value, {
        onFinish: () => {
            form.reset('password');
            recaptchaRef.value?.reset();
        },
    });
};

function submitPin(): void {
    if (pinForm.pin.length < 4) {
        return;
    }

    pinForm.post(pinLoginAction.value, {
        onFinish: () => {
            pinForm.reset('pin');
        },
    });
}

function switchMode(mode: 'email' | 'pin'): void {
    loginMode.value = mode;
    form.clearErrors();
    pinForm.clearErrors();
}
</script>

<template>
    <GuestLayout>
        <Head :title="t('auth.loginTitle')" />

        <div v-if="status" class="mb-4 text-sm font-medium text-[var(--wa-accent)]">
            {{ status }}
        </div>

        <div v-if="flashError" class="mb-4 text-sm font-medium text-red-400">
            {{ flashError }}
        </div>

        <div
            v-if="props.pinLoginAvailable && !isSuperAdminHost"
            class="mb-5 grid grid-cols-2 gap-1 rounded-lg p-1"
            :style="{ background: 'var(--wa-panel-input)' }"
            role="tablist"
        >
            <button
                type="button"
                class="rounded-md py-2 text-sm font-medium transition"
                :class="loginMode === 'email' ? 'text-[var(--wa-text)]' : 'text-[var(--wa-text-secondary)]'"
                :style="loginMode === 'email' ? { background: 'var(--wa-accent)', color: '#fff' } : undefined"
                role="tab"
                :aria-selected="loginMode === 'email'"
                @click="switchMode('email')"
            >
                {{ t('auth.emailTab') }}
            </button>
            <button
                type="button"
                class="rounded-md py-2 text-sm font-medium transition"
                :class="loginMode === 'pin' ? 'text-[var(--wa-text)]' : 'text-[var(--wa-text-secondary)]'"
                :style="loginMode === 'pin' ? { background: 'var(--wa-accent)', color: '#fff' } : undefined"
                role="tab"
                :aria-selected="loginMode === 'pin'"
                @click="switchMode('pin')"
            >
                {{ t('auth.pinTab') }}
            </button>
        </div>

        <form v-if="loginMode === 'email' || isSuperAdminHost || !props.pinLoginAvailable" class="space-y-5" @submit.prevent="submit">
            <div>
                <label for="email" class="block text-sm text-[var(--wa-text-secondary)] mb-1">{{ t('auth.emailLabel') }}</label>
                <input
                    id="email"
                    type="email"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                    class="w-full px-3 py-2.5 rounded-lg text-sm border transition focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wa-accent)]"
                    :style="{
                        background: 'var(--wa-panel-input)',
                        color: 'var(--wa-text)',
                        borderColor: 'var(--wa-border)',
                    }"
                    :aria-invalid="form.errors.email ? 'true' : undefined"
                    :aria-describedby="form.errors.email ? 'email-error' : undefined"
                    placeholder="admin@accel.kz"
                />
                <p v-if="form.errors.email" id="email-error" role="alert" class="mt-1 text-xs text-red-400">{{ form.errors.email }}</p>
            </div>

            <div>
                <label for="password" class="block text-sm text-[var(--wa-text-secondary)] mb-1">{{ t('auth.passwordLabel') }}</label>
                <input
                    id="password"
                    type="password"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                    class="w-full px-3 py-2.5 rounded-lg text-sm border transition focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wa-accent)]"
                    :style="{
                        background: 'var(--wa-panel-input)',
                        color: 'var(--wa-text)',
                        borderColor: 'var(--wa-border)',
                    }"
                    :aria-invalid="form.errors.password ? 'true' : undefined"
                    :aria-describedby="form.errors.password ? 'password-error' : undefined"
                    :placeholder="t('auth.passwordPlaceholder')"
                />
                <p v-if="form.errors.password" id="password-error" role="alert" class="mt-1 text-xs text-red-400">{{ form.errors.password }}</p>
            </div>

            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 cursor-pointer">
                    <UiCheckbox v-model="form.remember" size="sm" />
                    <span class="text-sm text-[var(--wa-text-secondary)]">{{ t('auth.remember') }}</span>
                </label>
            </div>

            <AuthRecaptcha ref="recaptchaRef" />
            <p v-if="form.errors.recaptcha_token" class="text-xs text-red-400 text-center">
                {{ form.errors.recaptcha_token }}
            </p>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full py-2.5 text-white rounded-lg text-sm font-medium transition disabled:opacity-50"
                :style="{ background: 'var(--wa-accent)' }"
            >
                <span v-if="form.processing">{{ t('auth.loading') }}</span>
                <span v-else>{{ t('auth.submit') }}</span>
            </button>
        </form>

        <div v-else class="space-y-4">
            <PinLoginPad
                v-model="pinForm.pin"
                :disabled="pinForm.processing"
                :error="pinForm.errors.pin"
                @submit="submitPin"
            />

            <label class="flex items-center justify-center gap-2 cursor-pointer">
                <UiCheckbox v-model="pinForm.remember" size="sm" />
                <span class="text-sm text-[var(--wa-text-secondary)]">{{ t('auth.remember') }}</span>
            </label>
        </div>
    </GuestLayout>
</template>
