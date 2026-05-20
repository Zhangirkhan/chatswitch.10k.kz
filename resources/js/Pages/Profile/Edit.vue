<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SettingsSidebar from '@/Pages/Settings/Partials/SettingsSidebar.vue';
import ProfileSection from './Partials/ProfileSection.vue';
import ChatsSection from './Partials/ChatsSection.vue';
import NotificationsSection from './Partials/NotificationsSection.vue';
import ModulesSection from './Partials/ModulesSection.vue';
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
    | 'chats'
    | 'notifications'
    | 'modules'
    | 'shortcuts';

const VALID_SECTIONS: readonly Section[] = [
    'profile', 'chats', 'notifications', 'modules', 'shortcuts',
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
        <div class="app-page flex-row">
            <!-- Left panel: either the settings list or an active sub-section. -->
            <template v-if="isPanelSection">
                <aside class="w-[400px] h-full flex flex-col bg-[var(--wa-panel)] shrink-0">
                    <ProfileSection v-if="activeSection === 'profile'" />
                    <ChatsSection v-else-if="activeSection === 'chats'" />
                    <NotificationsSection v-else-if="activeSection === 'notifications'" />
                    <ModulesSection v-else-if="activeSection === 'modules'" />
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
