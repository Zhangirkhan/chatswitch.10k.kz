import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '@/composables/useI18n';

export type RoleKey = 'administrator' | 'manager' | 'employee';

export function useRoleLabels() {
    const page = usePage();
    const { t } = useI18n();

    const fallback = computed<Record<RoleKey, string>>(() => ({
        administrator: t('settings.roles.administrator'),
        manager: t('settings.roles.manager'),
        employee: t('settings.roles.employee'),
    }));

    const labels = computed<Record<RoleKey, string>>(() => {
        const shared = page.props.roleLabels as Record<string, string> | undefined;

        return {
            administrator: shared?.administrator || fallback.value.administrator,
            manager: shared?.manager || fallback.value.manager,
            employee: shared?.employee || fallback.value.employee,
        };
    });

    function label(role: string | null | undefined): string {
        if (!role) {
            return '—';
        }

        const key = role as RoleKey;
        if (key in labels.value) {
            return labels.value[key];
        }

        return role;
    }

    return {
        labels,
        label,
    };
}
