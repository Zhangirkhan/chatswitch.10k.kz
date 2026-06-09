<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const { t } = useI18n();

type ReadinessCheck = {
    key: string;
    label: string;
    ok: boolean;
    value: string;
    hint: string;
};

type Readiness = {
    score: number;
    status: 'ready' | 'partial' | 'risk';
    label: string;
    summary: string;
    checks: ReadinessCheck[];
    next_actions: string[];
};

type OnboardingStep = {
    key: string;
    title: string;
    description: string;
    route: string;
    ok: boolean;
};

type RoleKey = 'administrator' | 'manager' | 'employee';

const props = defineProps<{
    readiness: Readiness;
    steps: OnboardingStep[];
    completed_steps: number;
    total_steps: number;
    roleLabels: Record<RoleKey, string>;
    roleLabelsConfigured: boolean;
    defaultRoleLabels: Record<RoleKey, string>;
}>();

const progressPercent = computed(() => {
    if (props.total_steps <= 0) {
        return 0;
    }
    return Math.round((props.completed_steps / props.total_steps) * 100);
});

function readinessColor(status: Readiness['status']): string {
    if (status === 'ready') {
        return '#16a34a';
    }
    if (status === 'partial') {
        return '#d97706';
    }
    return '#dc2626';
}

const allStepsDone = computed(() => props.completed_steps >= props.total_steps && props.readiness.status === 'ready');

const completeForm = useForm({});

const roleForm = useForm({
    administrator: props.roleLabels.administrator,
    manager: props.roleLabels.manager,
    employee: props.roleLabels.employee,
});

watch(
    () => props.roleLabels,
    (next) => {
        roleForm.administrator = next.administrator;
        roleForm.manager = next.manager;
        roleForm.employee = next.employee;
    },
);

const rolesExpanded = ref(!props.roleLabelsConfigured);

function submitRoleLabels(): void {
    roleForm.post(route('settings.onboarding.roles'), {
        preserveScroll: true,
    });
}

function submitComplete(): void {
    if (!allStepsDone.value || completeForm.processing) {
        return;
    }
    completeForm.post(route('settings.onboarding.complete'));
}

const roleFields: Array<{ key: RoleKey; hintKey: string }> = [
    { key: 'administrator', hintKey: 'settings.onboarding.roles.administratorHint' },
    { key: 'manager', hintKey: 'settings.onboarding.roles.managerHint' },
    { key: 'employee', hintKey: 'settings.onboarding.roles.employeeHint' },
];
</script>

<template>
    <Head :title="t('settings.onboarding.title')" />

    <SettingsLayout :title="t('settings.onboarding.title')" :subtitle="t('settings.onboarding.subtitle')">
        <div class="w-full space-y-8 px-6 py-6">
            <section class="ui-settings-section">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide" :style="{ color: readinessColor(readiness.status) }">
                            {{ t('settings.onboarding.progressLabel', { percent: progressPercent }) }}
                        </div>
                        <h2 class="mt-1 text-lg font-semibold" :style="{ color: 'var(--ui-text)' }">
                            {{ t('settings.onboarding.stepsProgress', { completed: completed_steps, total: total_steps }) }}
                        </h2>
                        <p class="mt-1 max-w-2xl text-sm" :style="{ color: 'var(--ui-text-secondary)' }">
                            {{ readiness.summary }}
                        </p>
                    </div>
                    <div
                        class="h-20 w-20 rounded-full p-1"
                        :style="{ background: `conic-gradient(${readinessColor(readiness.status)} ${progressPercent}%, var(--ui-surface-muted) 0)` }"
                    >
                        <div
                            class="flex h-full w-full items-center justify-center rounded-full text-lg font-semibold"
                            :style="{ background: 'var(--ui-surface)', color: 'var(--ui-text)' }"
                        >
                            {{ progressPercent }}
                        </div>
                    </div>
                </div>

                <div class="mt-6 h-2 overflow-hidden rounded-full" :style="{ background: 'var(--ui-surface-muted)' }">
                    <div
                        class="h-full rounded-full transition-all"
                        :style="{ width: `${progressPercent}%`, background: readinessColor(readiness.status) }"
                    />
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="ui-btn"
                        :class="allStepsDone ? 'ui-btn--primary' : 'ui-btn--secondary'"
                        :disabled="!allStepsDone || completeForm.processing"
                        @click="submitComplete"
                    >
                        {{ completeForm.processing ? t('settings.onboarding.completeProcessing') : t('settings.onboarding.completeButton') }}
                    </button>
                    <p v-if="!allStepsDone" class="text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                        {{ t('settings.onboarding.completeHint') }}
                    </p>
                </div>
            </section>

            <section
                id="onboarding-roles"
                class="ui-panel px-4 py-4"
                :style="{ borderColor: roleLabelsConfigured ? 'rgba(22, 163, 74, .35)' : undefined }"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold" :style="{ color: 'var(--ui-text)' }">
                            {{ t('settings.onboarding.roles.title') }}
                        </h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed" :style="{ color: 'var(--ui-text-secondary)' }">
                            {{ t('settings.onboarding.roles.description') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="ui-btn ui-btn--ghost ui-btn--sm"
                        @click="rolesExpanded = !rolesExpanded"
                    >
                        {{ rolesExpanded ? t('settings.onboarding.roles.hide') : t('settings.onboarding.roles.edit') }}
                    </button>
                </div>

                <form
                    v-if="rolesExpanded"
                    class="mt-4 grid gap-4 md:grid-cols-3"
                    @submit.prevent="submitRoleLabels"
                >
                    <label
                        v-for="field in roleFields"
                        :key="field.key"
                        class="block"
                    >
                        <span class="mb-1 block text-xs font-medium uppercase tracking-wide" :style="{ color: 'var(--ui-text-secondary)' }">
                            {{ t(`settings.onboarding.roles.${field.key}Label`) }}
                        </span>
                        <input
                            v-model="roleForm[field.key]"
                            type="text"
                            maxlength="64"
                            class="ui-input w-full"
                            :placeholder="defaultRoleLabels[field.key]"
                            required
                        >
                        <span class="mt-1 block text-xs leading-relaxed" :style="{ color: 'var(--ui-text-muted)' }">
                            {{ t(field.hintKey) }}
                        </span>
                        <p v-if="roleForm.errors[field.key]" class="mt-1 text-xs text-red-500">
                            {{ roleForm.errors[field.key] }}
                        </p>
                    </label>

                    <div class="md:col-span-3 flex flex-wrap items-center gap-2">
                        <button
                            type="submit"
                            class="ui-btn ui-btn--primary"
                            :disabled="roleForm.processing"
                        >
                            {{ roleForm.processing ? t('settings.onboarding.roles.saving') : t('settings.onboarding.roles.save') }}
                        </button>
                        <span
                            v-if="roleLabelsConfigured"
                            class="text-xs font-medium"
                            :style="{ color: '#15803d' }"
                        >
                            {{ t('settings.onboarding.roles.saved') }}
                        </span>
                    </div>
                </form>
            </section>

            <section class="space-y-3">
                <article
                    v-for="(step, index) in steps"
                    :key="step.key"
                    class="ui-panel px-4 py-4"
                    :style="{
                        borderColor: step.ok ? 'rgba(22, 163, 74, .35)' : undefined,
                    }"
                >
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span
                                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                                    :style="{
                                        background: step.ok ? 'rgba(22, 163, 74, .15)' : 'var(--ui-surface-muted)',
                                        color: step.ok ? '#15803d' : 'var(--ui-text-secondary)',
                                    }"
                                >
                                    {{ index + 1 }}
                                </span>
                                <h3 class="text-sm font-semibold" :style="{ color: 'var(--ui-text)' }">{{ step.title }}</h3>
                                <span
                                    class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase"
                                    :style="{
                                        color: step.ok ? '#15803d' : '#b91c1c',
                                        background: step.ok ? 'rgba(22, 163, 74, .12)' : 'rgba(220, 38, 38, .12)',
                                    }"
                                >
                                    {{ step.ok ? t('settings.onboarding.stepDone') : t('settings.onboarding.stepNeeded') }}
                                </span>
                            </div>
                            <p class="mt-2 text-sm leading-relaxed" :style="{ color: 'var(--ui-text-secondary)' }">
                                {{ step.description }}
                            </p>
                        </div>
                        <Link
                            v-if="step.key !== 'roles'"
                            :href="route(step.route)"
                            class="ui-btn ui-btn--ghost ui-btn--sm shrink-0"
                        >
                            {{ step.ok ? t('settings.onboarding.open') : t('settings.onboarding.configure') }}
                        </Link>
                        <a
                            v-else
                            href="#onboarding-roles"
                            class="ui-btn ui-btn--ghost ui-btn--sm shrink-0"
                            @click="rolesExpanded = true"
                        >
                            {{ step.ok ? t('settings.onboarding.open') : t('settings.onboarding.configure') }}
                        </a>
                    </div>
                </article>
            </section>

            <section
                v-if="readiness.next_actions.length"
                class="ui-settings-section"
            >
                <h2 class="text-sm font-semibold" :style="{ color: 'var(--ui-text)' }">{{ t('settings.onboarding.recommendationsTitle') }}</h2>
                <ul class="mt-3 space-y-1.5 text-sm" :style="{ color: 'var(--ui-text-secondary)' }">
                    <li v-for="action in readiness.next_actions" :key="action">• {{ action }}</li>
                </ul>
                <Link
                    :href="route('settings.ai-quality')"
                    class="ui-btn ui-btn--primary mt-4"
                >
                    {{ t('settings.onboarding.openAiQuality') }}
                </Link>
            </section>
        </div>
    </SettingsLayout>
</template>
