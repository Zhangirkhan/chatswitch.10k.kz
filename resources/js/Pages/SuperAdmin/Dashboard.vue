<script setup lang="ts">
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    stats: {
        active_companies: number;
        inactive_companies: number;
        pending_signups: number;
        overdue_invoices: number;
        issued_invoices: number;
        mrr_kzt: number;
    };
    recentCompanies: Array<{
        id: number;
        name: string;
        slug: string;
        subscription_status: string;
        plan?: { name: string } | null;
        created_at: string;
    }>;
}>();

const page = usePage();
const rootDomain = computed(() => (page.props.rootDomain as string | undefined) ?? 'accel.kz');

const attentionItems = computed(() => {
    const items: Array<{ label: string; href: string; count: number }> = [];
    if (props.stats.pending_signups > 0) {
        items.push({
            label: 'Заявки на рассмотрении',
            href: '/signup-requests?status=pending',
            count: props.stats.pending_signups,
        });
    }
    if (props.stats.overdue_invoices > 0) {
        items.push({
            label: 'Просроченные счета',
            href: '/invoices?status=issued',
            count: props.stats.overdue_invoices,
        });
    }
    if (props.stats.inactive_companies > 0) {
        items.push({
            label: 'Отключённые тенанты',
            href: '/companies?is_active=0',
            count: props.stats.inactive_companies,
        });
    }
    return items;
});
</script>

<template>
    <SuperAdminLayout>
        <Head title="Super Admin" />
        <h1 class="mb-6 text-2xl font-bold">Дашборд</h1>

        <div v-if="attentionItems.length > 0" class="ui-alert mb-6 border-ui-accent-border bg-ui-accent-soft">
            <p class="mb-2 text-sm font-medium text-ui-text">Требуют внимания</p>
            <ul class="space-y-1 text-sm">
                <li v-for="item in attentionItems" :key="item.href">
                    <Link :href="item.href" class="text-ui-accent hover:underline">
                        {{ item.label }}: {{ item.count }}
                    </Link>
                </li>
            </ul>
        </div>

        <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Link href="/companies?is_active=1" class="ui-panel p-4 transition-colors hover:bg-ui-surface-hover">
                <div class="text-sm text-ui-text-secondary">Активные компании</div>
                <div class="mt-1 text-3xl font-semibold">{{ stats.active_companies }}</div>
            </Link>
            <Link href="/signup-requests?status=pending" class="ui-panel p-4 transition-colors hover:bg-ui-surface-hover">
                <div class="text-sm text-ui-text-secondary">Заявки с лендинга</div>
                <div class="mt-1 text-3xl font-semibold">{{ stats.pending_signups }}</div>
            </Link>
            <Link href="/invoices?status=issued" class="ui-panel p-4 transition-colors hover:bg-ui-surface-hover">
                <div class="text-sm text-ui-text-secondary">Просроченные счета</div>
                <div class="mt-1 text-3xl font-semibold">{{ stats.overdue_invoices }}</div>
                <p v-if="stats.issued_invoices > stats.overdue_invoices" class="mt-1 text-xs text-ui-text-muted">
                    Всего выставлено: {{ stats.issued_invoices }}
                </p>
            </Link>
            <div class="ui-panel p-4">
                <div class="text-sm text-ui-text-secondary">MRR (KZT)</div>
                <div class="mt-1 text-3xl font-semibold">{{ stats.mrr_kzt }}</div>
                <p class="mt-1 text-xs text-ui-text-muted">По компаниям со статусом active</p>
            </div>
        </div>

        <div class="mb-6 flex flex-wrap gap-2">
            <Link href="/companies/create" class="ui-btn ui-btn--primary ui-btn--sm">Создать компанию</Link>
            <Link
                v-if="stats.pending_signups > 0"
                href="/signup-requests?status=pending"
                class="ui-btn ui-btn--secondary ui-btn--sm"
            >
                Заявки ({{ stats.pending_signups }})
            </Link>
            <Link href="/invoices" class="ui-btn ui-btn--ghost ui-btn--sm">Все счета</Link>
        </div>

        <div class="ui-panel overflow-hidden p-0">
            <div class="border-b border-ui-border px-4 py-3 font-medium">Последние компании</div>
            <div class="divide-y divide-ui-border">
                <Link
                    v-for="c in recentCompanies"
                    :key="c.id"
                    :href="`/companies/${c.id}`"
                    class="flex items-center justify-between px-4 py-3 transition-colors hover:bg-ui-surface-hover"
                >
                    <div>
                        <div class="font-medium">{{ c.name }}</div>
                        <div class="text-sm text-ui-text-secondary">{{ c.slug }}.{{ rootDomain }}</div>
                    </div>
                    <div class="text-sm text-ui-text-secondary">{{ c.subscription_status }}</div>
                </Link>
            </div>
        </div>
    </SuperAdminLayout>
</template>
