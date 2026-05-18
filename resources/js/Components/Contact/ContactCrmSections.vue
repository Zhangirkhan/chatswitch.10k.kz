<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import FunnelStageIcon from '@/Components/Funnel/FunnelStageIcon.vue';

export type ContactCrmPayload = {
    deal: {
        chat_id: number;
        chat_name: string | null;
        open_url: string;
        funnel: { id: number; name: string; color: string | null } | null;
        stage: { id: number; name: string; color: string | null; stage_type?: string | null } | null;
        progress_percent: number;
        ai_enabled: boolean;
        ai_mode: string | null;
        ai_orchestrator_status: string | null;
        ai_orchestrator_summary: string | null;
        assignees: Array<{ id: number; name: string | null }>;
        attention: { needs_attention: boolean; reason: string; severity: string };
        is_archived: boolean;
    } | null;
    companies: Array<{ id: number; name: string; position: string | null }>;
    upcoming_events: Array<{
        id: number;
        title: string;
        starts_at: string | null;
        ends_at: string | null;
        assignee: string | null;
        source: string | null;
        chat_id: number | null;
        open_url: string | null;
    }>;
    open_tasks: Array<{
        id: number;
        title: string;
        status: string;
        department: string | null;
        assignees: string[];
        due_at: string | null;
        created_at: string | null;
    }>;
    facts: Array<{ label: string; value: string; source: string }>;
};

defineProps<{
    crm: ContactCrmPayload;
    currentChatId?: number | null;
}>();

function formatDateTime(value: string | null | undefined): string {
    if (!value) return '—';
    const d = new Date(value);
    return d.toLocaleString('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function taskStatusLabel(status: string): string {
    if (status === 'in_progress') return 'В работе';
    if (status === 'open') return 'Открыта';
    return status;
}

function aiModeLabel(mode: string | null, enabled: boolean): string {
    if (!enabled) return 'Выключен';
    if (mode === 'draft') return 'Черновик';
    return 'Автоответ';
}
</script>

<template>
    <div v-if="crm.deal || crm.companies.length || crm.upcoming_events.length || crm.open_tasks.length || crm.facts.length" class="space-y-3">
        <div
            v-if="crm.deal"
            class="rounded-xl border p-3"
            :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel)' }"
        >
            <div class="flex items-start justify-between gap-2">
                <div>
                    <div class="text-xs uppercase tracking-wide" :style="{ color: 'var(--wa-text-secondary)' }">Сделка</div>
                    <div class="text-sm font-medium mt-0.5 flex items-center gap-1.5" :style="{ color: 'var(--wa-text)' }">
                        <FunnelStageIcon v-if="crm.deal.stage" :type="crm.deal.stage.stage_type" :size="16" />
                        <span>
                            {{ crm.deal.funnel?.name || 'Воронка не выбрана' }}
                            <span v-if="crm.deal.stage"> · {{ crm.deal.stage.name }}</span>
                        </span>
                    </div>
                </div>
                <span
                    class="text-xs font-semibold tabular-nums rounded-full px-2 py-0.5"
                    :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-text)' }"
                >
                    {{ crm.deal.progress_percent }}%
                </span>
            </div>

            <div class="mt-2 h-1.5 rounded-full overflow-hidden" :style="{ background: 'var(--wa-panel-header)' }">
                <span
                    class="block h-full rounded-full transition-all"
                    :style="{ width: `${crm.deal.progress_percent}%`, background: crm.deal.stage?.color || crm.deal.funnel?.color || 'var(--wa-accent)' }"
                />
            </div>

            <div class="mt-3 grid grid-cols-2 gap-2 text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                <div>
                    <span :style="{ color: 'var(--wa-text)' }">AI</span>
                    <div>{{ aiModeLabel(crm.deal.ai_mode, crm.deal.ai_enabled) }}</div>
                </div>
                <div>
                    <span :style="{ color: 'var(--wa-text)' }">Ответственные</span>
                    <div>
                        {{
                            crm.deal.assignees.length
                                ? crm.deal.assignees.map((a) => a.name).filter(Boolean).join(', ')
                                : 'Не назначены'
                        }}
                    </div>
                </div>
            </div>

            <div
                v-if="crm.deal.attention.needs_attention"
                class="mt-2 rounded-lg px-2 py-1.5 text-xs"
                :style="{ background: 'rgba(234, 88, 12, 0.12)', color: 'var(--wa-text)' }"
            >
                {{ crm.deal.attention.reason }}
            </div>

            <div v-if="crm.deal.ai_orchestrator_summary" class="mt-2 text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                {{ crm.deal.ai_orchestrator_summary }}
            </div>

            <Link
                v-if="!currentChatId || currentChatId !== crm.deal.chat_id"
                :href="crm.deal.open_url"
                class="mt-3 inline-flex text-xs font-medium hover:underline"
                :style="{ color: 'var(--wa-accent)' }"
            >
                Открыть чат сделки
            </Link>
        </div>

        <div v-if="crm.companies.length" class="rounded-xl border p-3" :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel)' }">
            <div class="text-xs uppercase tracking-wide mb-2" :style="{ color: 'var(--wa-text-secondary)' }">Компании</div>
            <ul class="space-y-1.5 text-sm">
                <li v-for="company in crm.companies" :key="company.id" :style="{ color: 'var(--wa-text)' }">
                    <span class="font-medium">{{ company.name }}</span>
                    <span v-if="company.position" class="text-xs" :style="{ color: 'var(--wa-text-secondary)' }"> · {{ company.position }}</span>
                </li>
            </ul>
        </div>

        <div v-if="crm.upcoming_events.length" class="rounded-xl border p-3" :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel)' }">
            <div class="text-xs uppercase tracking-wide mb-2" :style="{ color: 'var(--wa-text-secondary)' }">Ближайшие события</div>
            <ul class="space-y-2 text-sm">
                <li v-for="event in crm.upcoming_events" :key="event.id">
                    <div class="font-medium" :style="{ color: 'var(--wa-text)' }">{{ event.title }}</div>
                    <div class="text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                        {{ formatDateTime(event.starts_at) }}
                        <span v-if="event.assignee"> · {{ event.assignee }}</span>
                        <span v-if="event.source === 'ai_auto'"> · AI</span>
                    </div>
                    <Link v-if="event.open_url" :href="event.open_url" class="text-xs hover:underline" :style="{ color: 'var(--wa-accent)' }">
                        К чату
                    </Link>
                </li>
            </ul>
        </div>

        <div v-if="crm.open_tasks.length" class="rounded-xl border p-3" :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel)' }">
            <div class="text-xs uppercase tracking-wide mb-2" :style="{ color: 'var(--wa-text-secondary)' }">Открытые задачи</div>
            <ul class="space-y-2 text-sm">
                <li v-for="task in crm.open_tasks" :key="task.id">
                    <div class="font-medium" :style="{ color: 'var(--wa-text)' }">{{ task.title }}</div>
                    <div class="text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                        {{ taskStatusLabel(task.status) }}
                        <span v-if="task.department"> · {{ task.department }}</span>
                        <span v-if="task.assignees.length"> · {{ task.assignees.join(', ') }}</span>
                    </div>
                </li>
            </ul>
        </div>

        <div v-if="crm.facts.length" class="rounded-xl border p-3" :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel)' }">
            <div class="text-xs uppercase tracking-wide mb-2" :style="{ color: 'var(--wa-text-secondary)' }">Важные факты</div>
            <ul class="space-y-2 text-xs">
                <li v-for="(fact, index) in crm.facts" :key="`${fact.label}-${index}`">
                    <div :style="{ color: 'var(--wa-text-secondary)' }">{{ fact.label }}</div>
                    <div class="mt-0.5" :style="{ color: 'var(--wa-text)' }">{{ fact.value }}</div>
                </li>
            </ul>
        </div>
    </div>
</template>
