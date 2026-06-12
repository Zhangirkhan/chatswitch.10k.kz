<script setup lang="ts">
import SuperAdminPageHeader from '@/Components/SuperAdmin/SuperAdminPageHeader.vue';
import UiFilterField from '@/Components/Ui/UiFilterField.vue';
import UiFilterPanel from '@/Components/Ui/UiFilterPanel.vue';
import UiPagination from '@/Components/Ui/UiPagination.vue';
import { useI18n } from '@/composables/useI18n';
import { invoiceStatusBadgeClass, invoiceStatusLabel } from '@/utils/superAdminInvoiceBadge';
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

const { t } = useI18n();

const filterForm = useForm({ ...props.filters });

function applyFilters(): void {
    filterForm.get('/invoices', { preserveState: true, preserveScroll: true });
}

function formatPrice(cents: number): string {
    return new Intl.NumberFormat('ru-RU').format(Math.round(cents / 100)) + ' ₸';
}

function formatDate(iso: string | null): string {
    if (!iso) return t('superAdmin.common.emDash');
    return new Date(iso).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}
</script>

<template>
    
        <Head :title="t('superAdmin.invoices.pageTitle')" />
        <SuperAdminPageHeader
            accent-group="billing"
            :eyebrow="t('superAdmin.layout.navGroups.billing')"
            :title="t('superAdmin.invoices.heading')"
        />

        <UiFilterPanel class="mb-4" @submit="applyFilters">
            <UiFilterField :label="t('superAdmin.invoices.filterSearch')" wide>
                <input v-model="filterForm.q" type="search" :placeholder="t('superAdmin.invoices.filterSearchPlaceholder')" class="ui-input" />
            </UiFilterField>
            <UiFilterField :label="t('superAdmin.invoices.filterStatus')">
                <select v-model="filterForm.status" class="ui-select">
                    <option value="">{{ t('superAdmin.common.filterAll') }}</option>
                    <option value="issued">{{ t('superAdmin.invoice.status.issued') }}</option>
                    <option value="paid">{{ t('superAdmin.invoice.status.paid') }}</option>
                    <option value="void">{{ t('superAdmin.invoice.status.void') }}</option>
                </select>
            </UiFilterField>
            <UiFilterField :label="t('superAdmin.invoices.filterCompany')">
                <select v-model="filterForm.company_id" class="ui-select">
                    <option value="">{{ t('superAdmin.common.filterAll') }}</option>
                    <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                </select>
            </UiFilterField>
            <UiFilterField :label="t('superAdmin.invoices.filterFrom')">
                <input v-model="filterForm.from" type="date" class="ui-input" />
            </UiFilterField>
            <UiFilterField :label="t('superAdmin.invoices.filterTo')">
                <input v-model="filterForm.to" type="date" class="ui-input" />
            </UiFilterField>
            <template #actions>
                <button type="submit" class="ui-btn ui-btn--secondary ui-btn--sm">{{ t('superAdmin.common.apply') }}</button>
            </template>
        </UiFilterPanel>

        <div class="ui-panel ui-table-panel overflow-hidden p-0">
            <table class="min-w-[720px] w-full text-left text-sm">
                <thead>
                    <tr>
                        <th>{{ t('superAdmin.invoices.tableNumber') }}</th>
                        <th>{{ t('superAdmin.invoices.tableCompany') }}</th>
                        <th>{{ t('superAdmin.invoices.tableAmount') }}</th>
                        <th>{{ t('superAdmin.invoices.tableStatus') }}</th>
                        <th>{{ t('superAdmin.invoices.tableIssued') }}</th>
                        <th class="text-right">{{ t('superAdmin.common.actions') }}</th>
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
                            <span v-else>{{ t('superAdmin.common.emDash') }}</span>
                        </td>
                        <td>{{ formatPrice(inv.amount_cents) }}</td>
                        <td>
                            <span :class="invoiceStatusBadgeClass(inv.status)">
                                {{ invoiceStatusLabel(inv.status, t) }}
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
                                {{ t('superAdmin.invoices.openPdf') }}
                            </a>
                        </td>
                    </tr>
                    <tr v-if="invoices.data.length === 0">
                        <td colspan="6" class="py-8 text-center text-ui-text-muted">{{ t('superAdmin.invoices.empty') }}</td>
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
    
</template>
