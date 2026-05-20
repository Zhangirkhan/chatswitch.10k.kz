<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Главная" />

    <AuthenticatedLayout>
        <div class="app-page">
            <div class="app-page__scroll">
                <div class="app-page__content max-w-3xl space-y-6">
                    <section class="ui-settings-section">
                        <p class="text-xs font-semibold uppercase tracking-wide text-[var(--ui-accent)]">ChatSwitch</p>
                        <h1 class="mt-1 text-xl font-semibold text-[var(--ui-text)]">Добро пожаловать</h1>
                        <p class="mt-2 text-sm text-[var(--ui-text-secondary)]">
                            Вы вошли в систему. Перейдите в чаты, настройки или другие разделы из бокового меню.
                        </p>
                        <div class="mt-5 flex flex-wrap gap-2">
                            <Link :href="route('chats.index')" class="ui-btn ui-btn--primary">
                                Открыть чаты
                            </Link>
                            <Link :href="route('settings.users')" class="ui-btn ui-btn--ghost">
                                Настройки
                            </Link>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
