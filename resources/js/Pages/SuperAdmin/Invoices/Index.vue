<script setup lang="ts">
import UiFilterField from '@/Components/Ui/UiFilterField.vue';
import UiFilterPanel from '@/Components/Ui/UiFilterPanel.vue';
import UiPagination from '@/Components/Ui/UiPagination.vue';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { invoiceStatusBadgeClass, invoiceStatusLabels } from '@/utils/superAdminInvoiceBadge';
import { Head, Link, useForm } from '@inertiajs/vue3';

interface InvoiceRow {
    id: number;
    number: string;
    amount_cents: number;
    status: string;
    issued_at: string | null;
    company?: { id: number; name: string; slug: string } | null;
}

interface Paginated<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

const props = defineProps<{
    invoices: Paginated<InvoiceRow>;
    filters: { status: string; company_id: string; from: string; to: string; q: string };
    companies: Array<{ id: number; name: string; slug: string }>;
}>();

const filterForm = useForm({ ...props.filters });

function applyFilters(): void {
    filterForm.get('/invoices', { preserveState: true, preserveScroll: true });
}

function formatPrice(cents: number): string {
    return new Intl.NumberFormat('ru-RU').format(Math.round(cents / 100)) + ' ₸';
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}
</script>

<template>
    <SuperAdminLayout>
        <Head title="Все счета" />
        <h1 class="mb-6 text-2xl font-bold">Все счета</h1>

        <UiFilterPanel class="mb-4" @submit="applyFilters">
            <UiFilterField label="Поиск" wide>
                <input v-model="filterForm.q" type="search" placeholder="Номер, компания" class="ui-input" />
            </UiFilterField>
            <UiFilterField label="Статус">
                <select v-model="filterForm.status" class="ui-select">
                    <option value="">Все</option>
                    <option value="issued">Выставлен</option>
                    <option value="paid">Оплачен</option>
                    <option value="void">Аннулирован</option>
                </select>
            </UiFilterField>
            <UiFilterField label="Компания">
                <select v-model="filterForm.company_id" class="ui-select">
                    <option value="">Все</option>
                    <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                </select>
            </UiFilterField>
            <UiFilterField label="С">
                <input v-model="filterForm.from" type="date" class="ui-input" />
            </UiFilterField>
            <UiFilterField label="По">
                <input v-model="filterForm.to" type="date" class="ui-input" />
            </UiFilterField>
            <template #actions>
                <button type="submit" class="ui-btn ui-btn--secondary ui-btn--sm">Применить</button>
            </template>
        </UiFilterPanel>

        <div class="ui-panel ui-table-panel overflow-hidden p-0">
            <table class="min-w-[720px] w-full text-left text-sm">
                <thead>
                    <tr>
                        <th>Номер</th>
                        <th>Компания</th>
                        <th>Сумма</th>
                        <th>Статус</th>
                        <th>Выставлен</th>
                        <th class="text-right">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="inv in invoices.data" :key="inv.id">
                        <td class="font-medium">{{ inv.number }}</td>
                        <td>
                            <Link
                                v-if="inv.company"
                                :href="`/companies/${inv.company.id}?tab=invoices`"
                                class="text-ui-accent hover:underline"
                            >
                                {{ inv.company.name }}
                            </Link>
                            <span v-else>—</span>
                        </td>
                        <td>{{ formatPrice(inv.amount_cents) }}</td>
                        <td>
                            <span :class="invoiceStatusBadgeClass(inv.status)">
                                {{ invoiceStatusLabels[inv.status] ?? inv.status }}
                            </span>
                        </td>
                        <td class="text-ui-text-muted">{{ formatDate(inv.issued_at) }}</td>
                        <td class="text-right">
                            <a
                                :href="`/invoices/${inv.id}/print`"
                                target="_blank"
                                rel="noopener"
                                class="ui-btn ui-btn--ghost ui-btn--sm"
                            >
                                Открыть PDF/печать
                            </a>
                        </td>
                    </tr>
                    <tr v-if="invoices.data.length === 0">
                        <td colspan="6" class="py-8 text-center text-ui-text-muted">Счета не найдены</td>
                    </tr>
                </tbody>
            </table>
            <UiPagination
                :links="invoices.links"
                :from="invoices.from"
                :to="invoices.to"
                :total="invoices.total"
            />
        </div>
    </SuperAdminLayout>
</template>
