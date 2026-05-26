<script setup lang="ts">
import UiFilterField from '@/Components/Ui/UiFilterField.vue';
import UiFilterPanel from '@/Components/Ui/UiFilterPanel.vue';
import UiPagination from '@/Components/Ui/UiPagination.vue';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { auditActionLabel, auditMetaSummary } from '@/utils/superAdminAuditLabels';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface AuditRow {
    id: number;
    action: string;
    meta: Record<string, unknown> | null;
    created_at: string;
    company?: { id: number; name: string; slug: string } | null;
    actor?: { name: string; email: string } | null;
}

interface Paginated<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

const props = defineProps<{
    auditLogs: Paginated<AuditRow>;
    filters: { action: string; company_id: string; q: string };
    companies: Array<{ id: number; name: string; slug: string }>;
    actions: string[];
}>();

const filterForm = useForm({ ...props.filters });

function applyFilters(): void {
    filterForm.get('/audit-logs', { preserveState: true, preserveScroll: true });
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}
</script>

<template>
    <SuperAdminLayout>
        <Head title="Журнал действий" />
        <h1 class="mb-6 text-2xl font-bold">Глобальный журнал</h1>

        <UiFilterPanel class="mb-4" @submit="applyFilters">
            <UiFilterField label="Поиск" wide>
                <input
                    v-model="filterForm.q"
                    type="search"
                    class="ui-input"
                    placeholder="Компания, действие, email"
                />
            </UiFilterField>
            <UiFilterField label="Действие">
                <select v-model="filterForm.action" class="ui-select">
                    <option value="">Все</option>
                    <option v-for="action in actions" :key="action" :value="action">
                        {{ auditActionLabel(action) }}
                    </option>
                </select>
            </UiFilterField>
            <UiFilterField label="Компания">
                <select v-model="filterForm.company_id" class="ui-select">
                    <option value="">Все</option>
                    <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                </select>
            </UiFilterField>
            <template #actions>
                <button type="submit" class="ui-btn ui-btn--secondary ui-btn--sm">Применить</button>
            </template>
        </UiFilterPanel>

        <div class="ui-panel overflow-hidden p-0">
            <ul class="divide-y divide-ui-border">
                <li v-for="log in auditLogs.data" :key="log.id" class="px-4 py-3 text-sm">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <span class="font-medium">{{ auditActionLabel(log.action) }}</span>
                        <span class="text-xs text-ui-text-muted">{{ formatDate(log.created_at) }}</span>
                    </div>
                    <p v-if="log.company" class="mt-0.5 text-ui-text-secondary">
                        <Link :href="`/companies/${log.company.id}?tab=audit`" class="text-ui-accent hover:underline">
                            {{ log.company.name }}
                        </Link>
                    </p>
                    <p v-if="log.actor" class="mt-0.5 text-ui-text-secondary">
                        {{ log.actor.name }} · {{ log.actor.email }}
                    </p>
                    <p v-if="auditMetaSummary(log.meta)" class="mt-0.5 text-xs text-ui-text-muted">
                        {{ auditMetaSummary(log.meta) }}
                    </p>
                </li>
                <li v-if="auditLogs.data.length === 0" class="px-4 py-8 text-center text-ui-text-muted">
                    Записей не найдено
                </li>
            </ul>
            <UiPagination
                :links="auditLogs.links"
                :from="auditLogs.from"
                :to="auditLogs.to"
                :total="auditLogs.total"
            />
        </div>
    </SuperAdminLayout>
</template>
