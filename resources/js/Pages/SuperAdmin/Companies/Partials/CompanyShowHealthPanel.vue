<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface HealthCheck {
    key: string;
    ok: boolean;
    severity: string;
    message: string;
    fixable?: boolean;
}

interface HealthGroup {
    ok: boolean;
    checks: HealthCheck[];
}

const props = defineProps<{
    companyId: number;
    tenantHealth: {
        ok: boolean;
        slug: string;
        groups: Record<string, HealthGroup>;
    };
    provisioningVerify?: {
        status: 'pass' | 'fail';
        failures?: string[];
        checked_at?: string;
    } | null;
}>();

const page = usePage();
const fixing = ref(false);

const provisioningPending = computed(() => page.props.flash?.provisioning_pending === true);
const verifyStatus = computed(() => props.provisioningVerify?.status ?? null);

const groupLabels: Record<string, string> = {
    data: 'Данные',
    permissions: 'Права',
    dns_ssl: 'DNS / SSL',
    whatsapp: 'WhatsApp',
    readiness: 'AI readiness',
    queues: 'Очереди',
};

function runFix(): void {
    fixing.value = true;
    router.post(
        `/companies/${props.companyId}/doctor-fix`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                fixing.value = false;
            },
        },
    );
}
</script>

<template>
    <section class="ui-settings-section">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold">Здоровье тенанта</h2>
                <p class="mt-1 text-sm text-ui-text-secondary">
                    Диагностика provisioning, прав, SSL и WhatsApp
                </p>
            </div>
            <button
                type="button"
                class="ui-btn ui-btn-secondary"
                :disabled="fixing"
                @click="runFix"
            >
                {{ fixing ? 'Починка…' : 'Починить' }}
            </button>
        </div>

        <div
            v-if="provisioningPending && verifyStatus === null"
            class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
            Проверка provisioning… Job выполняется в фоне.
        </div>
        <div
            v-else-if="verifyStatus === 'fail'"
            class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900"
        >
            Provisioning verification failed:
            {{ (provisioningVerify?.failures ?? []).join(', ') }}
        </div>
        <div
            v-else-if="verifyStatus === 'pass'"
            class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
        >
            Provisioning verification passed
            <span v-if="provisioningVerify?.checked_at" class="text-ui-text-secondary">
                ({{ provisioningVerify.checked_at }})
            </span>
        </div>

        <div
            class="mb-4 inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-medium"
            :class="tenantHealth.ok ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800'"
        >
            {{ tenantHealth.ok ? 'OK' : 'Есть critical-проблемы' }}
        </div>

        <div class="space-y-4">
            <div
                v-for="(group, key) in tenantHealth.groups"
                :key="key"
                class="rounded-lg border border-ui-border p-4"
            >
                <h3 class="mb-2 text-sm font-semibold text-ui-text">
                    {{ groupLabels[key] ?? key }}
                    <span
                        class="ml-2 text-xs font-normal"
                        :class="group.ok ? 'text-emerald-600' : 'text-amber-600'"
                    >
                        {{ group.ok ? 'OK' : 'issues' }}
                    </span>
                </h3>
                <ul class="space-y-1 text-sm">
                    <li
                        v-for="check in group.checks"
                        :key="check.key"
                        class="flex gap-2"
                        :class="check.ok ? 'text-ui-text-secondary' : (check.severity === 'critical' ? 'text-red-700' : 'text-amber-700')"
                    >
                        <span>{{ check.ok ? '✓' : '✗' }}</span>
                        <span>{{ check.message }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
