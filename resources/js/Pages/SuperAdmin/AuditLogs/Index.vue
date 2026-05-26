<script setup lang="ts">
import UiFilterField from '@/Components/Ui/UiFilterField.vue';
import UiFilterPanel from '@/Components/Ui/UiFilterPanel.vue';
import UiPagination from '@/Components/Ui/UiPagination.vue';
import UiPillNav from '@/Components/Ui/UiPillNav.vue';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { auditActionLabel, auditMetaSummary } from '@/utils/superAdminAuditLabels';
import { paymentMethodLabels } from '@/utils/superAdminInvoiceBadge';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface AuditRow {
    id: number;
    action: string;
    meta: Record<string, unknown> | null;
    created_at: string;
    company?: { id: number; name: string; slug: string } | null;
    actor?: { name: string; email: string } | null;
}

interface TransactionRow {
    id: number;
    amount_cents: number;
    method: string;
    external_ref: string | null;
    paid_at: string;
    company?: { id: number; name: string; slug: string } | null;
    invoice?: { id: number; number: string } | null;
    recordedBy?: { name: string; email: string } | null;
}

interface Paginated<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

type JournalTab = 'actions' | 'transactions';

const props = defineProps<{
    tab: JournalTab;
    auditLogs: Paginated<AuditRow> | null;
    transactions: Paginated<TransactionRow> | null;
    filters: {
        action: string;
        company_id: string;
        method: string;
        from: string;
        to: string;
        q: string;
    };
    companies: Array<{ id: number; name: string; slug: string }>;
    actions: string[];
}>();

const filterForm = useForm({
    tab: props.tab,
    action: props.filters.action,
    company_id: props.filters.company_id,
    method: props.filters.method,
    from: props.filters.from,
    to: props.filters.to,
    q: props.filters.q,
});

const tabs: Array<{ id: JournalTab; label: string }> = [
    { id: 'actions', label: 'Действия' },
    { id: 'transactions', label: 'Транзакции' },
];

const isActionsTab = computed(() => props.tab === 'actions');

function setTab(tab: JournalTab): void {
    filterForm.tab = tab;
    if (tab === 'actions') {
        filterForm.method = '';
        filterForm.from = '';
        filterForm.to = '';
    } else {
        filterForm.action = '';
    }
    applyFilters();
}

function applyFilters(): void {
    filterForm.get('/audit-logs', { preserveState: true, preserveScroll: true });
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}

function formatPrice(cents: number): string {
    return new Intl.NumberFormat('ru-RU').format(Math.round(cents / 100)) + ' ₸';
}
</script>

<template>
    <SuperAdminLayout>
        <Head title="Журнал действий" />
        <h1 class="mb-6 text-2xl font-bold">Глобальный журнал</h1>

        <UiPillNav class="mb-4">
            <button
                v-for="t in tabs"
                :key="t.id"
                type="button"
                class="ui-pill-nav__item"
                :class="{ 'is-active': tab === t.id }"
                @click="setTab(t.id)"
            >
                {{ t.label }}
            </button>
        </UiPillNav>

        <UiFilterPanel class="mb-4" @submit="applyFilters">
            <UiFilterField label="Поиск" wide>
                <input
                    v-model="filterForm.q"
                    type="search"
                    class="ui-input"
                    :placeholder="isActionsTab ? 'Компания, действие, email' : 'Компания, счёт, ссылка, email'"
                />
            </UiFilterField>
            <UiFilterField v-if="isActionsTab" label="Действие">
                <select v-model="filterForm.action" class="ui-select">
                    <option value="">Все</option>
                    <option v-for="action in actions" :key="action" :value="action">
                        {{ auditActionLabel(action) }}
                    </option>
                </select>
            </UiFilterField>
            <template v-else>
                <UiFilterField label="Способ оплаты">
                    <select v-model="filterForm.method" class="ui-select">
                        <option value="">Все</option>
                        <option v-for="(label, key) in paymentMethodLabels" :key="key" :value="key">
                            {{ label }}
                        </option>
                    </select>
                </UiFilterField>
                <UiFilterField label="С">
                    <input v-model="filterForm.from" type="date" class="ui-input" />
                </UiFilterField>
                <UiFilterField label="По">
                    <input v-model="filterForm.to" type="date" class="ui-input" />
                </UiFilterField>
            </template>
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

        <div v-if="isActionsTab && auditLogs" class="ui-panel overflow-hidden p-0">
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

        <div v-else-if="transactions" class="ui-panel ui-table-panel overflow-hidden p-0">
            <table class="min-w-[880px] w-full text-left text-sm">
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Компания</th>
                        <th>Счёт</th>
                        <th>Сумма</th>
                        <th>Способ</th>
                        <th>Записал</th>
                        <th>Ссылка</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="payment in transactions.data" :key="payment.id">
                        <td class="text-ui-text-muted">{{ formatDate(payment.paid_at) }}</td>
                        <td>
                            <Link
                                v-if="payment.company"
                                :href="`/companies/${payment.company.id}?tab=invoices`"
                                class="text-ui-accent hover:underline"
                            >
                                {{ payment.company.name }}
                            </Link>
                            <span v-else>—</span>
                        </td>
                        <td class="font-medium">
                            <Link
                                v-if="payment.invoice && payment.company"
                                :href="`/companies/${payment.company.id}?tab=invoices`"
                                class="text-ui-accent hover:underline"
                            >
                                {{ payment.invoice.number }}
                            </Link>
                            <span v-else-if="payment.invoice">{{ payment.invoice.number }}</span>
                            <span v-else>—</span>
                        </td>
                        <td>{{ formatPrice(payment.amount_cents) }}</td>
                        <td>{{ paymentMethodLabels[payment.method] ?? payment.method }}</td>
                        <td class="text-ui-text-secondary">
                            <template v-if="payment.recordedBy">
                                {{ payment.recordedBy.name }} · {{ payment.recordedBy.email }}
                            </template>
                            <span v-else>—</span>
                        </td>
                        <td class="text-ui-text-muted">{{ payment.external_ref ?? '—' }}</td>
                    </tr>
                    <tr v-if="transactions.data.length === 0">
                        <td colspan="7" class="py-8 text-center text-ui-text-muted">Транзакций не найдено</td>
                    </tr>
                </tbody>
            </table>
            <UiPagination
                :links="transactions.links"
                :from="transactions.from"
                :to="transactions.to"
                :total="transactions.total"
            />
        </div>
    </SuperAdminLayout>
</template>
