<script setup lang="ts">
import AuthenticatedLayout from './AuthenticatedLayout.vue';
import OrganizationSidebar, { type OrgDepartment } from '@/Pages/Organization/Partials/OrganizationSidebar.vue';

defineProps<{
    departments: OrgDepartment[];
    selectedDepartmentId?: number | null;
    archiveActive?: boolean;
}>();
</script>

<template>
    <AuthenticatedLayout>
        <div class="flex h-full min-h-0 w-full bg-[var(--wa-bg)]">
            <OrganizationSidebar
                :departments="departments"
                :selected-department-id="selectedDepartmentId"
                :archive-active="archiveActive"
                class="shrink-0"
                :class="{ 'hidden md:flex': selectedDepartmentId || archiveActive }"
            />
            <div
                class="flex min-h-0 min-w-0 flex-1 flex-col border-l border-[var(--wa-border)]"
                :class="{ 'hidden md:flex': !selectedDepartmentId && !archiveActive }"
            >
                <slot />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
