<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

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

const props = defineProps<{
    readiness: Readiness;
    steps: OnboardingStep[];
    completed_steps: number;
    total_steps: number;
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
</script>

<template>
    <Head title="Онбординг" />

    <SettingsLayout title="Онбординг" subtitle="Пошаговая настройка компании для запуска AI-воронки">
        <div class="w-full space-y-8 px-6 py-6">
            <section
                class="rounded-xl border p-5"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide" :style="{ color: readinessColor(readiness.status) }">
                            Прогресс онбординга · {{ progressPercent }}%
                        </div>
                        <h2 class="mt-1 text-lg font-semibold" :style="{ color: 'var(--wa-text)' }">
                            {{ completed_steps }} из {{ total_steps }} шагов
                        </h2>
                        <p class="mt-1 max-w-2xl text-sm" :style="{ color: 'var(--wa-text-secondary)' }">
                            {{ readiness.summary }}
                        </p>
                    </div>
                    <div
                        class="h-20 w-20 rounded-full p-1"
                        :style="{ background: `conic-gradient(${readinessColor(readiness.status)} ${progressPercent}%, var(--wa-panel-header) 0)` }"
                    >
                        <div
                            class="flex h-full w-full items-center justify-center rounded-full text-lg font-semibold"
                            :style="{ background: 'var(--wa-panel)', color: 'var(--wa-text)' }"
                        >
                            {{ progressPercent }}
                        </div>
                    </div>
                </div>

                <div class="mt-6 h-2 overflow-hidden rounded-full" :style="{ background: 'var(--wa-panel-header)' }">
                    <div
                        class="h-full rounded-full transition-all"
                        :style="{ width: `${progressPercent}%`, background: readinessColor(readiness.status) }"
                    />
                </div>
            </section>

            <section class="space-y-3">
                <article
                    v-for="(step, index) in steps"
                    :key="step.key"
                    class="rounded-xl border px-4 py-4"
                    :style="{
                        borderColor: step.ok ? 'rgba(22, 163, 74, .35)' : 'var(--wa-border)',
                        background: 'var(--wa-panel)',
                    }"
                >
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span
                                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                                    :style="{
                                        background: step.ok ? 'rgba(22, 163, 74, .15)' : 'var(--wa-panel-header)',
                                        color: step.ok ? '#15803d' : 'var(--wa-text-secondary)',
                                    }"
                                >
                                    {{ index + 1 }}
                                </span>
                                <h3 class="text-sm font-semibold" :style="{ color: 'var(--wa-text)' }">{{ step.title }}</h3>
                                <span
                                    class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase"
                                    :style="{
                                        color: step.ok ? '#15803d' : '#b91c1c',
                                        background: step.ok ? 'rgba(22, 163, 74, .12)' : 'rgba(220, 38, 38, .12)',
                                    }"
                                >
                                    {{ step.ok ? 'Готово' : 'Нужно' }}
                                </span>
                            </div>
                            <p class="mt-2 text-sm leading-relaxed" :style="{ color: 'var(--wa-text-secondary)' }">
                                {{ step.description }}
                            </p>
                        </div>
                        <Link
                            :href="route(step.route)"
                            class="shrink-0 rounded-xl border px-3 py-2 text-sm font-medium transition hover:brightness-95"
                            :style="{ color: 'var(--wa-accent)', borderColor: 'var(--wa-border)' }"
                        >
                            {{ step.ok ? 'Открыть' : 'Настроить' }}
                        </Link>
                    </div>
                </article>
            </section>

            <section
                v-if="readiness.next_actions.length"
                class="rounded-xl border p-5"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <h2 class="text-sm font-semibold" :style="{ color: 'var(--wa-text)' }">Рекомендации AI Quality</h2>
                <ul class="mt-3 space-y-1.5 text-sm" :style="{ color: 'var(--wa-text-secondary)' }">
                    <li v-for="action in readiness.next_actions" :key="action">• {{ action }}</li>
                </ul>
                <Link
                    :href="route('settings.ai-quality')"
                    class="mt-4 inline-flex rounded-xl px-4 py-2 text-sm font-semibold"
                    :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                >
                    Открыть AI и качество
                </Link>
            </section>
        </div>
    </SettingsLayout>
</template>
