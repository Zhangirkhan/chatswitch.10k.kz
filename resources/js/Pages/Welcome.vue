<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    canLogin?: boolean;
    loginUrl?: string;
}>();

const page = usePage();
const flashSuccess = computed(() => {
    const flash = page.props.flash as { success?: string } | undefined;
    return flash?.success ?? null;
});

const form = useForm({
    company_name: '',
    contact_name: '',
    email: '',
    phone: '',
    message: '',
});

function submit() {
    form.post('/signup-request', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <GuestLayout>
        <Head title="Accel" />

        <div class="py-2 text-center">
            <h2 class="mb-2 text-lg font-normal text-[var(--wa-text)]">Добро пожаловать</h2>
            <p class="mb-6 text-sm leading-relaxed text-[var(--wa-text-secondary)]">
                Единая платформа для WhatsApp-переписки, AI-ассистента и задач команды.
            </p>

            <div class="flex flex-col gap-2">
                <Link
                    v-if="$page.props.auth?.user"
                    :href="route('chats.index')"
                    class="w-full rounded-lg py-2.5 text-sm font-medium text-white transition disabled:opacity-50"
                    :style="{ background: 'var(--wa-accent)' }"
                >
                    Открыть чаты
                </Link>

                <template v-else-if="canLogin">
                    <a
                        v-if="loginUrl"
                        :href="loginUrl"
                        class="w-full rounded-lg py-2.5 text-sm font-medium text-white transition"
                        :style="{ background: 'var(--wa-accent)' }"
                    >
                        Войти
                    </a>
                    <Link
                        v-else
                        :href="route('login')"
                        class="w-full rounded-lg py-2.5 text-sm font-medium text-white transition"
                        :style="{ background: 'var(--wa-accent)' }"
                    >
                        Войти
                    </Link>
                </template>
            </div>
        </div>

        <div id="request" class="mt-8 border-t pt-8 text-left" :style="{ borderColor: 'var(--wa-border)' }">
            <h3 class="mb-1 text-base font-medium text-[var(--wa-text)]">Оставить заявку</h3>
            <p class="mb-4 text-sm text-[var(--wa-text-secondary)]">
                Расскажите о компании — мы свяжемся и поможем с подключением.
            </p>

            <p
                v-if="flashSuccess"
                class="mb-4 rounded-lg px-3 py-2 text-sm"
                :style="{ color: 'var(--wa-accent)', background: 'var(--wa-accent-soft)' }"
                role="status"
            >
                {{ flashSuccess }}
            </p>

            <form class="flex flex-col gap-3" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-xs font-medium text-[var(--wa-text-secondary)]" for="company_name">
                        Компания
                    </label>
                    <input
                        id="company_name"
                        v-model="form.company_name"
                        type="text"
                        required
                        maxlength="160"
                        class="w-full rounded-lg border px-3 py-2 text-sm text-[var(--wa-text)] outline-none focus:ring-2"
                        :style="{
                            background: 'var(--wa-panel-input)',
                            borderColor: 'var(--wa-border-strong)',
                        }"
                        autocomplete="organization"
                    />
                    <InputError class="mt-1" :message="form.errors.company_name" />
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-[var(--wa-text-secondary)]" for="contact_name">
                        Контактное лицо
                    </label>
                    <input
                        id="contact_name"
                        v-model="form.contact_name"
                        type="text"
                        required
                        maxlength="120"
                        class="w-full rounded-lg border px-3 py-2 text-sm text-[var(--wa-text)] outline-none focus:ring-2"
                        :style="{
                            background: 'var(--wa-panel-input)',
                            borderColor: 'var(--wa-border-strong)',
                        }"
                        autocomplete="name"
                    />
                    <InputError class="mt-1" :message="form.errors.contact_name" />
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-[var(--wa-text-secondary)]" for="email">
                        Email
                    </label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
                        maxlength="160"
                        class="w-full rounded-lg border px-3 py-2 text-sm text-[var(--wa-text)] outline-none focus:ring-2"
                        :style="{
                            background: 'var(--wa-panel-input)',
                            borderColor: 'var(--wa-border-strong)',
                        }"
                        autocomplete="email"
                    />
                    <InputError class="mt-1" :message="form.errors.email" />
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-[var(--wa-text-secondary)]" for="phone">
                        Телефон
                    </label>
                    <input
                        id="phone"
                        v-model="form.phone"
                        type="tel"
                        maxlength="40"
                        class="w-full rounded-lg border px-3 py-2 text-sm text-[var(--wa-text)] outline-none focus:ring-2"
                        :style="{
                            background: 'var(--wa-panel-input)',
                            borderColor: 'var(--wa-border-strong)',
                        }"
                        autocomplete="tel"
                    />
                    <InputError class="mt-1" :message="form.errors.phone" />
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-[var(--wa-text-secondary)]" for="message">
                        Комментарий
                    </label>
                    <textarea
                        id="message"
                        v-model="form.message"
                        rows="3"
                        maxlength="2000"
                        class="w-full resize-y rounded-lg border px-3 py-2 text-sm text-[var(--wa-text)] outline-none focus:ring-2"
                        :style="{
                            background: 'var(--wa-panel-input)',
                            borderColor: 'var(--wa-border-strong)',
                        }"
                    />
                    <InputError class="mt-1" :message="form.errors.message" />
                </div>

                <button
                    type="submit"
                    class="w-full rounded-lg py-2.5 text-sm font-medium text-white transition disabled:opacity-50"
                    :style="{ background: 'var(--wa-accent)' }"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Отправка…' : 'Отправить заявку' }}
                </button>
            </form>
        </div>
    </GuestLayout>
</template>
