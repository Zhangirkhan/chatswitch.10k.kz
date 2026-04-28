<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { Head } from '@inertiajs/vue3';
import type { Department } from '@/types';

const props = defineProps<{
    departments: (Department & { users_count: number })[];
}>();
</script>

<template>
    <Head title="Отделы" />
    <SettingsLayout title="Отделы" subtitle="Структура компании и распределение операторов">
        <div class="w-full px-6 py-6">
            <div
                class="rounded-lg border overflow-hidden"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <div v-if="props.departments.length === 0" class="p-10 text-center text-[var(--wa-text-secondary)]">
                    Нет отделов.
                </div>
                <div
                    v-for="dept in props.departments"
                    :key="dept.id"
                    class="flex items-center justify-between px-5 py-4 border-b last:border-b-0"
                    :style="{ borderColor: 'var(--wa-border)' }"
                >
                    <div class="min-w-0">
                        <h3 class="font-medium text-[var(--wa-text)] truncate">{{ dept.name }}</h3>
                        <p v-if="dept.description" class="text-xs text-[var(--wa-text-secondary)] mt-0.5 truncate">
                            {{ dept.description }}
                        </p>
                        <span class="text-xs text-[var(--wa-text-secondary)]">{{ dept.users_count }} пользователей</span>
                    </div>
                </div>
            </div>
        </div>
    </SettingsLayout>
</template>
