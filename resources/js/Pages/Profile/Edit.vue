<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SettingsSidebar from '@/Pages/Settings/Partials/SettingsSidebar.vue';
import ProfileSection from './Partials/ProfileSection.vue';
import AccountSection from './Partials/AccountSection.vue';
import PrivacySection from './Partials/PrivacySection.vue';
import ChatsSection from './Partials/ChatsSection.vue';
import NotificationsSection from './Partials/NotificationsSection.vue';
import HelpSection from './Partials/HelpSection.vue';
import ShortcutsModal from './Partials/ShortcutsModal.vue';
import SettingsEmpty from './Partials/SettingsEmpty.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    mustVerifyEmail?: boolean;
    status?: string;
}>();

type Section =
    | 'profile'
    | 'account'
    | 'privacy'
    | 'chats'
    | 'notifications'
    | 'shortcuts'
    | 'help';

const VALID_SECTIONS: readonly Section[] = [
    'profile', 'account', 'privacy', 'chats', 'notifications', 'shortcuts', 'help',
] as const;

const page = usePage();

// `page.url` is updated reactively by Inertia on every navigation, so the query
// string stays in sync without manual event listeners.
const activeSection = computed<Section | null>(() => {
    const url = page.url; // e.g. "/profile?section=privacy"
    const queryIndex = url.indexOf('?');
    const search = queryIndex >= 0 ? url.slice(queryIndex) : '';
    const raw = new URLSearchParams(search).get('section');
    return (VALID_SECTIONS as readonly string[]).includes(raw ?? '') ? (raw as Section) : null;
});

const isPanelSection = computed(() =>
    activeSection.value !== null && activeSection.value !== 'shortcuts',
);

function closeShortcuts() {
    router.visit(route('profile.edit'), { preserveScroll: false });
}
</script>

<template>
    <Head title="Настройки" />
    <AuthenticatedLayout>
        <div class="flex h-full w-full bg-[var(--wa-bg)]">
            <!-- Left panel: either the settings list or an active sub-section. -->
            <template v-if="isPanelSection">
                <aside class="w-[400px] h-full flex flex-col bg-[var(--wa-panel)] shrink-0">
                    <ProfileSection v-if="activeSection === 'profile'" />
                    <AccountSection v-else-if="activeSection === 'account'" />
                    <PrivacySection v-else-if="activeSection === 'privacy'" />
                    <ChatsSection v-else-if="activeSection === 'chats'" />
                    <NotificationsSection v-else-if="activeSection === 'notifications'" />
                    <HelpSection v-else-if="activeSection === 'help'" />
                </aside>
            </template>
            <template v-else>
                <SettingsSidebar :active-section="activeSection ?? undefined" />
            </template>

            <!-- Right side: always the generic placeholder. -->
            <SettingsEmpty />

            <!-- Keyboard shortcuts modal -->
            <ShortcutsModal
                v-if="activeSection === 'shortcuts'"
                @close="closeShortcuts"
            />
        </div>
    </AuthenticatedLayout>
</template>
