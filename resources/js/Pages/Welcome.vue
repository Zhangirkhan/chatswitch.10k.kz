<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    canLogin?: boolean;
}>();
</script>

<template>
    <GuestLayout>
        <Head title="ChatSwitch" />

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
                    <Link
                        :href="route('login')"
                        class="w-full rounded-lg py-2.5 text-sm font-medium text-white transition"
                        :style="{ background: 'var(--wa-accent)' }"
                    >
                        Войти
                    </Link>
                </template>
            </div>
        </div>
    </GuestLayout>
</template>
