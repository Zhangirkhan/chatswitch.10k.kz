<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SettingsSidebar from '@/Pages/Settings/Partials/SettingsSidebar.vue';
import ContactSection from './Partials/ContactSection.vue';
import ProfileSection from './Partials/ProfileSection.vue';
import AccountSection from './Partials/AccountSection.vue';
import ChatsSection from './Partials/ChatsSection.vue';
import NotificationsSection from './Partials/NotificationsSection.vue';
import ShortcutsModal from './Partials/ShortcutsModal.vue';
import SettingsEmpty from './Partials/SettingsEmpty.vue';
import { useI18n } from '@/composables/useI18n';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    mustVerifyEmail?: boolean;
    status?: string;
    feedbackItems?: Array<{
        id: number;
        type: 'complaint' | 'suggestion';
        message: string;
        status: 'new' | 'read' | 'resolved';
        source: 'web' | 'mobile';
        created_at: string | null;
    }>;
}>();

const { t } = useI18n();

type Section =
    | 'profile'
    | 'account'
    | 'chats'
    | 'notifications'
    | 'contact'
    | 'shortcuts';

const VALID_SECTIONS: readonly Section[] = [
    'profile', 'account', 'chats', 'notifications', 'contact', 'shortcuts',
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
    <Head :title="t('profile.pageTitle')" />
    <AuthenticatedLayout>
        <div class="app-page flex-row">
            <!-- Left panel: either the settings list or an active sub-section. -->
            <template v-if="isPanelSection">
                <aside class="w-[400px] h-full flex flex-col bg-[var(--wa-panel)] shrink-0">
                    <ProfileSection v-if="activeSection === 'profile'" />
                    <AccountSection v-else-if="activeSection === 'account'" />
                    <ChatsSection v-else-if="activeSection === 'chats'" />
                    <NotificationsSection v-else-if="activeSection === 'notifications'" />
                    <ContactSection
                        v-else-if="activeSection === 'contact'"
                        :items="props.feedbackItems ?? []"
                    />
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
