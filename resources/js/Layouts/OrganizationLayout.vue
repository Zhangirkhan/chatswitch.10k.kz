<script setup lang="ts">
import AuthenticatedLayout from './AuthenticatedLayout.vue';
import OrganizationSidebar, { type OrgDepartment } from '@/Pages/Organization/Partials/OrganizationSidebar.vue';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    departments: OrgDepartment[];
    selectedDepartmentId?: number | null;
    archiveActive?: boolean;
}>();

const page = usePage();
const isTeamChatUrl = computed(() => typeof page.url === 'string' && page.url.startsWith('/organization/chat'));
</script>

<template>
    <AuthenticatedLayout>
        <div class="flex h-full min-h-0 w-full bg-[var(--wa-bg)]">
            <OrganizationSidebar
                :departments="departments"
                :selected-department-id="selectedDepartmentId"
                :archive-active="archiveActive"
                class="shrink-0"
                :class="{ 'hidden md:flex': (selectedDepartmentId || archiveActive) && !isTeamChatUrl }"
            />
            <div
                class="flex min-h-0 min-w-0 flex-1 flex-col border-l border-[var(--wa-border)]"
                :class="{ 'hidden md:flex': !selectedDepartmentId && !archiveActive && !isTeamChatUrl }"
            >
                <slot />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
