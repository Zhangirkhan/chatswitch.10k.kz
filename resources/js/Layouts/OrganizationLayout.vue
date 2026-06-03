<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import PanelResizeHandle from '@/Components/Ui/PanelResizeHandle.vue';
import OrganizationSidebar, { type OrgDepartment } from '@/Pages/Organization/Partials/OrganizationSidebar.vue';
import {
    LIST_SIDEBAR_WIDTH_DEFAULTS,
    LIST_SIDEBAR_WIDTH_STORAGE_KEY,
    useResizablePanelWidth,
} from '@/composables/useResizablePanelWidth';
import AuthenticatedLayout from './AuthenticatedLayout.vue';

const props = defineProps<{
    departments: OrgDepartment[];
    selectedDepartmentId?: number | null;
    archiveActive?: boolean;
}>();

const page = usePage();
const orgTasksEnabled = computed(() => Boolean(page.props.modules?.org_tasks ?? false));
const isTeamChatUrl = computed(
    () => !orgTasksEnabled.value
        || (typeof page.url === 'string' && page.url.startsWith('/organization/chat')),
);

const selectedConversationId = computed(() => {
    const v = page.props.selectedConversationId;
    return typeof v === 'number' ? v : null;
});

const hideSidebarOnMobile = computed(
    () =>
        ((props.selectedDepartmentId || props.archiveActive) && !isTeamChatUrl.value)
        || (isTeamChatUrl.value && selectedConversationId.value !== null),
);

const hideMainOnMobile = computed(
    () =>
        (!props.selectedDepartmentId && !props.archiveActive && !isTeamChatUrl.value)
        || (isTeamChatUrl.value && selectedConversationId.value === null),
);

const sidebarResize = useResizablePanelWidth({
    storageKey: LIST_SIDEBAR_WIDTH_STORAGE_KEY,
    ...LIST_SIDEBAR_WIDTH_DEFAULTS,
    edge: 'left',
});

const sidebarWidthStyle = computed(() => ({
    width: sidebarResize.widthPx.value,
}));

const sidebarResizing = computed(() => sidebarResize.isResizing.value);
</script>

<template>
    <AuthenticatedLayout>
        <div class="flex h-full min-h-0 w-full bg-[var(--wa-bg)]">
            <div
                class="flex h-full shrink-0 overflow-hidden"
                :class="{ 'hidden sm:flex': hideSidebarOnMobile }"
                :style="sidebarWidthStyle"
            >
                <OrganizationSidebar
                    :departments="departments"
                    :selected-department-id="selectedDepartmentId"
                    :archive-active="archiveActive"
                    class="h-full w-full min-w-0"
                />
            </div>
            <PanelResizeHandle
                class="hidden sm:block"
                :class="{ 'sm:hidden': hideSidebarOnMobile }"
                :active="sidebarResizing"
                @pointerdown="sidebarResize.onResizePointerDown"
            />
            <div
                class="flex min-h-0 min-w-0 flex-1 flex-col border-l"
                :style="{ borderColor: 'var(--wa-sidebar-divider)' }"
                :class="{ 'hidden sm:flex': hideMainOnMobile }"
            >
                <slot />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
