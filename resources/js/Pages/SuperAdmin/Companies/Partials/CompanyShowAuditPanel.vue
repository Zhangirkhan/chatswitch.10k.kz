<script setup lang="ts">
import { auditActionLabel, auditMetaSummary } from '@/utils/superAdminAuditLabels';

interface AuditRow {
    id: number;
    action: string;
    meta: Record<string, unknown> | null;
    created_at: string;
    actor?: { name: string; email: string } | null;
}

defineProps<{
    auditLogs: AuditRow[];
}>();

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}
</script>

<template>
    <div class="ui-panel overflow-hidden p-0">
        <div class="border-b border-ui-border px-4 py-3">
            <h2 class="font-medium">Журнал действий</h2>
            <p class="mt-0.5 text-sm text-ui-text-secondary">Биллинг, подписки, пользователи, вход в тенант</p>
        </div>
        <ul class="divide-y divide-ui-border">
            <li v-for="log in auditLogs" :key="log.id" class="px-4 py-3 text-sm">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <span class="font-medium text-ui-text">{{ auditActionLabel(log.action) }}</span>
                    <span class="text-xs text-ui-text-muted">{{ formatDate(log.created_at) }}</span>
                </div>
                <p v-if="log.actor" class="mt-0.5 text-ui-text-secondary">
                    {{ log.actor.name }} · {{ log.actor.email }}
                </p>
                <p v-if="auditMetaSummary(log.meta)" class="mt-0.5 text-xs text-ui-text-muted">
                    {{ auditMetaSummary(log.meta) }}
                </p>
            </li>
            <li v-if="auditLogs.length === 0" class="px-4 py-8 text-center text-ui-text-muted">
                Записей пока нет
            </li>
        </ul>
    </div>
</template>
