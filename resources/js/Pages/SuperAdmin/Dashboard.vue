<script setup lang="ts">
import SuperAdminAttentionBanner from '@/Components/SuperAdmin/SuperAdminAttentionBanner.vue';
import SuperAdminKpiGrid from '@/Components/SuperAdmin/SuperAdminKpiGrid.vue';
import SuperAdminPageHeader from '@/Components/SuperAdmin/SuperAdminPageHeader.vue';
import SuperAdminSection from '@/Components/SuperAdmin/SuperAdminSection.vue';
import { subscriptionStatusBadgeClass } from '@/utils/superAdminSubscriptionBadge';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '@/composables/useI18n';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';

const { t } = useI18n();

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
    topFeedback?: Array<{
        id: number;
        type: 'complaint' | 'suggestion';
        message: string;
        likes_count: number;
        created_at: string | null;
    }>;
}>();

const page = usePage();
const rootDomain = computed(() => (page.props.rootDomain as string | undefined) ?? 'accel.kz');

const attentionItems = computed(() => {
    const items: Array<{ label: string; href: string; count: number }> = [];
    if (props.stats.pending_signups > 0) {
        items.push({
            label: t('superAdmin.dashboard.attentionPendingSignups'),
            href: '/signup-requests?status=pending',
            count: props.stats.pending_signups,
        });
    }
    if (props.stats.overdue_invoices > 0) {
        items.push({
            label: t('superAdmin.dashboard.attentionOverdueInvoices'),
            href: '/invoices?status=issued',
            count: props.stats.overdue_invoices,
        });
    }
    if (props.stats.inactive_companies > 0) {
        items.push({
            label: t('superAdmin.dashboard.attentionInactiveTenants'),
            href: '/companies?is_active=0',
            count: props.stats.inactive_companies,
        });
    }
    return items;
});

const kpiItems = computed(() => [
    {
        label: t('superAdmin.dashboard.statsActiveCompanies'),
        value: props.stats.active_companies,
        href: '/companies?is_active=1',
        tone: 'accent' as const,
    },
    {
        label: t('superAdmin.dashboard.statsLandingSignups'),
        value: props.stats.pending_signups,
        href: '/signup-requests?status=pending',
        tone: props.stats.pending_signups > 0 ? 'info' as const : 'default' as const,
    },
    {
        label: t('superAdmin.dashboard.statsOverdueInvoices'),
        value: props.stats.overdue_invoices,
        href: '/invoices?status=issued',
        hint: props.stats.issued_invoices > props.stats.overdue_invoices
            ? t('superAdmin.dashboard.statsIssuedTotal', { count: props.stats.issued_invoices })
            : undefined,
        tone: props.stats.overdue_invoices > 0 ? 'danger' as const : 'default' as const,
    },
    {
        label: 'MRR (KZT)',
        value: props.stats.mrr_kzt,
        hint: t('superAdmin.dashboard.statsMrrHint'),
        tone: 'billing' as const,
    },
]);

function formatDate(iso: string): string {
    try {
        return new Date(iso).toLocaleDateString('ru-RU', { dateStyle: 'medium' });
    } catch {
        return iso;
    }
}

function subscriptionLabel(status: string): string {
    const statusMap: Record<string, string> = {
        trial: t('superAdmin.subscriptionStatus.trial'),
        active: t('superAdmin.subscriptionStatus.active'),
        past_due: t('superAdmin.subscriptionStatus.pastDue'),
        suspended: t('superAdmin.subscriptionStatus.suspended'),
        canceled: t('superAdmin.subscriptionStatus.canceled'),
    };
    return statusMap[status] ?? status;
}
</script>

<template>
    <SuperAdminLayout>
        <Head :title="t('superAdmin.dashboard.title')" />

        <SuperAdminPageHeader
            accent-group="overview"
            :eyebrow="t('superAdmin.layout.navGroups.overview')"
            :title="t('superAdmin.dashboard.title')"
            :subtitle="t('superAdmin.dashboard.subtitle')"
        >
            <template #actions>
                <Link href="/companies/create" class="ui-btn ui-btn--primary ui-btn--sm">
                    {{ t('superAdmin.dashboard.actionsCreateCompany') }}
                </Link>
                <Link
                    v-if="stats.pending_signups > 0"
                    href="/signup-requests?status=pending"
                    class="ui-btn ui-btn--secondary ui-btn--sm"
                >
                    {{ t('superAdmin.dashboard.actionsSignupRequests', { count: stats.pending_signups }) }}
                </Link>
            </template>
        </SuperAdminPageHeader>

        <SuperAdminAttentionBanner
            :title="t('superAdmin.dashboard.attentionTitle')"
            :items="attentionItems"
        />

        <SuperAdminKpiGrid :items="kpiItems" />

        <div class="ui-super-admin-actions">
            <Link href="/invoices" class="ui-btn ui-btn--ghost ui-btn--sm">
                {{ t('superAdmin.dashboard.actionsAllInvoices') }}
            </Link>
            <Link href="/ai-sales" class="ui-btn ui-btn--ghost ui-btn--sm">
                {{ t('superAdmin.layout.nav.aiSales') }}
            </Link>
        </div>

        <SuperAdminSection
            :title="t('superAdmin.dashboard.recentCompaniesTitle')"
            flush
        >
            <div>
                <Link
                    v-for="c in recentCompanies"
                    :key="c.id"
                    :href="`/companies/${c.id}`"
                    class="ui-super-admin-list-row"
                >
                    <div class="min-w-0">
                        <div class="font-medium truncate">{{ c.name }}</div>
                        <div class="text-sm text-ui-text-secondary truncate">
                            {{ c.slug }}.{{ rootDomain }}
                            <span v-if="c.plan?.name" class="text-ui-text-muted"> · {{ c.plan.name }}</span>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1">
                        <span :class="subscriptionStatusBadgeClass(c.subscription_status)">
                            {{ subscriptionLabel(c.subscription_status) }}
                        </span>
                        <span class="text-xs text-ui-text-muted">{{ formatDate(c.created_at) }}</span>
                    </div>
                </Link>
            </div>
        </SuperAdminSection>

        <SuperAdminSection
            v-if="props.topFeedback && props.topFeedback.length > 0"
            :title="t('superAdmin.dashboard.topFeedbackTitle')"
            flush
        >
            <template #actions>
                <Link href="/contact-messages/ranking" class="text-sm text-ui-accent hover:underline">
                    {{ t('superAdmin.dashboard.topFeedbackLink') }}
                </Link>
            </template>
            <div>
                <div
                    v-for="item in props.topFeedback"
                    :key="item.id"
                    class="ui-super-admin-list-row"
                >
                    <div class="min-w-0">
                        <div class="text-sm font-medium truncate">{{ item.message }}</div>
                        <div class="mt-1 text-xs text-ui-text-muted">{{ item.type }}</div>
                    </div>
                    <div class="shrink-0 text-sm font-semibold">{{ item.likes_count }}</div>
                </div>
            </div>
        </SuperAdminSection>
    
    </SuperAdminLayout>
</template>
