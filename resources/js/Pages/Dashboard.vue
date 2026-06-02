<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { Head, Link } from '@inertiajs/vue3';

const { t } = useI18n();
</script>

<template>
    <Head :title="t('misc.dashboardTitle')" />

    <AuthenticatedLayout>
        <div class="app-page">
            <div class="app-page__scroll">
                <div class="app-page__content max-w-3xl space-y-6">
                    <section class="ui-settings-section">
                        <p class="text-xs font-semibold uppercase tracking-wide text-[var(--ui-accent)]">Accel</p>
                        <h1 class="mt-1 text-xl font-semibold text-[var(--ui-text)]">{{ t('misc.dashboardWelcome') }}</h1>
                        <p class="mt-2 text-sm text-[var(--ui-text-secondary)]">
                            {{ t('misc.dashboardSubtitle') }}
                        </p>
                        <div class="mt-5 flex flex-wrap gap-2">
                            <Link :href="route('chats.index')" class="ui-btn ui-btn--primary">
                                {{ t('misc.openChats') }}
                            </Link>
                            <Link :href="route('settings.users')" class="ui-btn ui-btn--ghost">
                                {{ t('misc.settings') }}
                            </Link>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
