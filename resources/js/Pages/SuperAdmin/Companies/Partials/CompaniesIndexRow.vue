<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { subscriptionStatusBadgeClass } from '@/utils/superAdminSubscriptionBadge';
import { Link, router } from '@inertiajs/vue3';

export interface CompanyIndexRow {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
    subscription_status: string;
    trial_ends_at: string | null;
    plan?: { name: string } | null;
    can_impersonate?: boolean;
    impersonate_blocked_reason?: string | null;
    can_delete?: boolean;
}

defineProps<{
    company: CompanyIndexRow;
    rootDomain: string;
    isDemo?: boolean;
}>();

const emit = defineEmits<{
    (e: 'toggle', company: CompanyIndexRow): void;
    (e: 'delete', company: CompanyIndexRow): void;
}>();

const { t } = useI18n();

function statusLabel(s: string): string {
    const map: Record<string, string> = {
        trial: t('superAdmin.companies.row.statusTrial'),
        active: t('superAdmin.companies.row.statusActive'),
        past_due: t('superAdmin.companies.row.statusPastDue'),
        suspended: t('superAdmin.companies.row.statusSuspended'),
        canceled: t('superAdmin.companies.row.statusCanceled'),
    };
    return map[s] ?? s;
}

function impersonate(c: CompanyIndexRow, event: Event): void {
    event.preventDefault();
    event.stopPropagation();
    if (!c.can_impersonate) return;
    router.post(`/companies/${c.id}/impersonate`, {}, {
        preserveState: false,
        preserveScroll: false,
        onError: () => {},
    });
}
</script>

<template>
    <tr :class="isDemo ? 'bg-ui-accent-soft/40' : ''">
        <td>
            <div class="flex items-center gap-2">
                <Link :href="`/companies/${company.id}`" class="font-medium text-ui-accent hover:text-ui-accent-hover hover:underline">
                    {{ company.name }}
                </Link>
                <span
                    v-if="isDemo"
                    class="ui-badge ui-badge--neutral text-[10px] uppercase tracking-wide"
                >
                    Demo
                </span>
            </div>
        </td>
        <td>
            <a
                :href="`https://${company.slug}.${rootDomain}/`"
                target="_blank"
                rel="noopener"
                class="font-mono text-ui-text-secondary hover:text-ui-accent"
            >
                {{ company.slug }}.{{ rootDomain }}
            </a>
        </td>
        <td>
            <span class="inline-flex" :class="subscriptionStatusBadgeClass(company.subscription_status)">
                {{ statusLabel(company.subscription_status) }}
            </span>
        </td>
        <td class="text-ui-text">{{ company.plan?.name ?? t('superAdmin.common.emDash') }}</td>
        <td class="text-ui-text-muted text-xs">
            <template v-if="company.subscription_status === 'trial' && company.trial_ends_at">
                {{ new Date(company.trial_ends_at).toLocaleDateString('ru-RU') }}
            </template>
            <template v-else-if="company.subscription_status === 'past_due'">
                <span class="text-ui-accent">{{ t('superAdmin.companies.row.trialOverdue') }}</span>
            </template>
            <template v-else>{{ t('superAdmin.common.emDash') }}</template>
        </td>
        <td class="text-right">
            <div class="inline-flex items-center gap-1">
                <button
                    v-if="company.is_active"
                    type="button"
                    class="ui-btn ui-btn--ghost ui-btn--sm"
                    :disabled="!company.can_impersonate"
                    :title="company.impersonate_blocked_reason ?? t('superAdmin.companies.row.impersonateTitle')"
                    @click="impersonate(company, $event)"
                >
                    {{ t('superAdmin.companies.row.enter') }}
                </button>
                <button
                    v-if="company.can_delete"
                    type="button"
                    class="ui-btn ui-btn--danger-ghost ui-btn--sm"
                    :title="t('superAdmin.companies.row.deleteTitle')"
                    @click="emit('delete', company)"
                >
                    {{ t('superAdmin.companies.row.delete') }}
                </button>
            </div>
        </td>
        <td class="text-right">
            <button
                type="button"
                :title="company.is_active ? t('superAdmin.companies.row.disableTitle') : t('superAdmin.companies.row.enableTitle')"
                class="group inline-flex items-center"
                :aria-pressed="company.is_active"
                @click="emit('toggle', company)"
            >
                <span
                    class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors"
                    :class="company.is_active ? 'bg-ui-accent group-hover:opacity-90' : 'bg-ui-surface-muted group-hover:bg-ui-surface-hover'"
                >
                    <span
                        class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                        :class="company.is_active ? 'translate-x-4' : 'translate-x-1'"
                    ></span>
                </span>
            </button>
        </td>
    </tr>
</template>
