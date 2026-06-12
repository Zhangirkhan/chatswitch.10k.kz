<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { computed, ref, watch } from 'vue';

export type FunnelTemplateOption = {
    key: string;
    industry: string;
    name: string;
    description: string;
    color: string;
    stages: Array<{ name: string; color: string }>;
};

const props = defineProps<{
    open: boolean;
    templates: FunnelTemplateOption[];
    creatingTemplateKey: string | null;
}>();

const emit = defineEmits<{
    close: [];
    manual: [];
    ai: [];
    template: [template: FunnelTemplateOption];
}>();

const { t } = useI18n();
const step = ref<'choose' | 'templates'>('choose');

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            step.value = 'choose';
        }
    },
);

const templateCountLabel = computed(() =>
    t('settings.funnels.onboarding.templatesCount', { count: props.templates.length }),
);

function close(): void {
    emit('close');
}

function openTemplates(): void {
    step.value = 'templates';
}

function backToChoose(): void {
    step.value = 'choose';
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="funnel-onboarding-backdrop fixed inset-0 z-[2100] flex items-center justify-center p-4 sm:p-6"
            role="dialog"
            aria-modal="true"
            :aria-label="t('settings.funnels.onboarding.title')"
            @click.self="close"
        >
            <div
                class="funnel-onboarding-modal relative w-full max-w-3xl overflow-hidden rounded-2xl border shadow-2xl"
                @click.stop
            >
                <div class="funnel-onboarding-modal__glow" aria-hidden="true" />

                <header class="relative border-b px-6 py-5 sm:px-8">
                    <button
                        type="button"
                        class="absolute right-4 top-4 rounded-lg p-2 text-[var(--ui-text-secondary)] transition hover:bg-[var(--ui-surface-hover)] hover:text-[var(--ui-text)]"
                        :aria-label="t('common.close')"
                        @click="close"
                    >
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>

                    <div v-if="step === 'templates'" class="mb-3">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 text-sm text-[var(--ui-text-secondary)] transition hover:text-[var(--ui-text)]"
                            @click="backToChoose"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                            </svg>
                            {{ t('settings.funnels.onboarding.back') }}
                        </button>
                    </div>

                    <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-[var(--ui-accent)]">
                        {{ t('settings.funnels.onboarding.eyebrow') }}
                    </p>
                    <h2 class="mt-1 text-xl font-semibold tracking-tight text-[var(--ui-text)] sm:text-2xl">
                        {{ step === 'choose' ? t('settings.funnels.onboarding.title') : t('settings.funnels.onboarding.templatesTitle') }}
                    </h2>
                    <p class="mt-2 max-w-2xl text-sm leading-relaxed text-[var(--ui-text-secondary)]">
                        {{ step === 'choose' ? t('settings.funnels.onboarding.subtitle') : t('settings.funnels.onboarding.templatesSubtitle') }}
                    </p>
                </header>

                <div class="relative max-h-[min(70vh,640px)] overflow-y-auto wa-scrollbar px-6 py-6 sm:px-8">
                    <div v-if="step === 'choose'" class="grid gap-3 sm:grid-cols-3">
                        <button
                            type="button"
                            class="funnel-onboarding-card group text-left"
                            @click="emit('manual')"
                        >
                            <span class="funnel-onboarding-card__icon funnel-onboarding-card__icon--manual" aria-hidden="true">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                                </svg>
                            </span>
                            <span class="mt-4 block text-sm font-semibold text-[var(--ui-text)]">
                                {{ t('settings.funnels.onboarding.manualTitle') }}
                            </span>
                            <span class="mt-1 block text-xs leading-relaxed text-[var(--ui-text-secondary)]">
                                {{ t('settings.funnels.onboarding.manualDesc') }}
                            </span>
                            <span class="funnel-onboarding-card__cta">
                                {{ t('settings.funnels.onboarding.manualCta') }}
                            </span>
                        </button>

                        <button
                            type="button"
                            class="funnel-onboarding-card funnel-onboarding-card--featured group text-left"
                            @click="emit('ai')"
                        >
                            <span class="funnel-onboarding-card__badge">{{ t('settings.funnels.onboarding.recommended') }}</span>
                            <span class="funnel-onboarding-card__icon funnel-onboarding-card__icon--ai" aria-hidden="true">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.5 6.5L22 12l-6.5 2.5L13 21l-2.5-6.5L4 12l6.5-2.5L13 3z" />
                                </svg>
                            </span>
                            <span class="mt-4 block text-sm font-semibold text-[var(--ui-text)]">
                                {{ t('settings.funnels.onboarding.aiTitle') }}
                            </span>
                            <span class="mt-1 block text-xs leading-relaxed text-[var(--ui-text-secondary)]">
                                {{ t('settings.funnels.onboarding.aiDesc') }}
                            </span>
                            <span class="funnel-onboarding-card__cta">
                                {{ t('settings.funnels.onboarding.aiCta') }}
                            </span>
                        </button>

                        <button
                            type="button"
                            class="funnel-onboarding-card group text-left"
                            :disabled="templates.length === 0"
                            @click="openTemplates"
                        >
                            <span class="funnel-onboarding-card__icon funnel-onboarding-card__icon--templates" aria-hidden="true">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z" />
                                </svg>
                            </span>
                            <span class="mt-4 block text-sm font-semibold text-[var(--ui-text)]">
                                {{ t('settings.funnels.onboarding.templatesCardTitle') }}
                            </span>
                            <span class="mt-1 block text-xs leading-relaxed text-[var(--ui-text-secondary)]">
                                {{ t('settings.funnels.onboarding.templatesCardDesc') }}
                            </span>
                            <span class="funnel-onboarding-card__cta">
                                {{ templateCountLabel }}
                            </span>
                        </button>
                    </div>

                    <div v-else class="space-y-3">
                        <article
                            v-for="template in templates"
                            :key="template.key"
                            class="funnel-onboarding-template rounded-xl border p-4 transition hover:border-[var(--ui-accent-border)]"
                        >
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ background: template.color }" />
                                        <h3 class="truncate text-sm font-semibold text-[var(--ui-text)]">{{ template.industry }}</h3>
                                    </div>
                                    <p class="mt-1 text-xs leading-relaxed text-[var(--ui-text-secondary)]">{{ template.description }}</p>
                                    <div class="mt-3 flex flex-wrap gap-1.5">
                                        <span
                                            v-for="stage in template.stages.slice(0, 5)"
                                            :key="`${template.key}-${stage.name}`"
                                            class="rounded-full px-2 py-0.5 text-[11px]"
                                            :style="{ background: 'var(--ui-surface)', color: 'var(--ui-text-secondary)' }"
                                        >
                                            {{ stage.name }}
                                        </span>
                                        <span
                                            v-if="template.stages.length > 5"
                                            class="rounded-full px-2 py-0.5 text-[11px]"
                                            :style="{ background: 'var(--ui-surface)', color: 'var(--ui-text-secondary)' }"
                                        >
                                            +{{ template.stages.length - 5 }}
                                        </span>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="ui-btn ui-btn--primary ui-btn--sm shrink-0"
                                    :disabled="creatingTemplateKey !== null"
                                    @click="emit('template', template)"
                                >
                                    {{ creatingTemplateKey === template.key ? t('settings.funnels.creating') : t('settings.funnels.create') }}
                                </button>
                            </div>
                        </article>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.funnel-onboarding-backdrop {
    background: color-mix(in srgb, var(--ui-bg) 55%, rgb(0 0 0 / 45%));
    backdrop-filter: blur(6px);
}

.funnel-onboarding-modal {
    background: var(--ui-surface);
    border-color: var(--ui-border-strong);
    animation: funnel-onboarding-in 0.28s cubic-bezier(0.22, 1, 0.36, 1);
}

.funnel-onboarding-modal__glow {
    position: absolute;
    inset: -30% auto auto 50%;
    width: 320px;
    height: 220px;
    transform: translateX(-50%);
    background: radial-gradient(circle, color-mix(in srgb, var(--ui-accent) 8%, transparent) 0%, transparent 65%);
    pointer-events: none;
    opacity: 0.7;
}

.funnel-onboarding-card {
    position: relative;
    display: flex;
    flex-direction: column;
    min-height: 11.5rem;
    padding: 1.1rem;
    border-radius: 1rem;
    border: 1px solid var(--ui-border);
    background: color-mix(in srgb, var(--ui-surface-muted) 70%, var(--ui-surface));
    transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
}

.funnel-onboarding-card:hover:not(:disabled) {
    transform: translateY(-2px);
    border-color: var(--ui-accent-border);
    box-shadow: var(--ui-shadow-card);
}

.funnel-onboarding-card:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.funnel-onboarding-card--featured {
    --funnel-ai-accent: #eab308;
    border-color: color-mix(in srgb, var(--funnel-ai-accent) 28%, var(--ui-border));
    background: linear-gradient(
        160deg,
        color-mix(in srgb, var(--funnel-ai-accent) 7%, var(--ui-surface)) 0%,
        var(--ui-surface-muted) 100%
    );
}

.funnel-onboarding-card__badge {
    position: absolute;
    top: 0.85rem;
    right: 0.85rem;
    padding: 0.15rem 0.45rem;
    border-radius: 999px;
    font-size: 0.625rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--funnel-ai-accent, var(--ui-accent));
    background: color-mix(in srgb, var(--funnel-ai-accent, var(--ui-accent)) 10%, transparent);
}

.funnel-onboarding-card__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 0.75rem;
}

.funnel-onboarding-card__icon--manual {
    color: var(--ui-text);
    background: var(--ui-surface);
    border: 1px solid var(--ui-border);
}

.funnel-onboarding-card__icon--ai {
    color: #eab308;
    background: color-mix(in srgb, #eab308 12%, var(--ui-surface));
    border: 1px solid color-mix(in srgb, #eab308 32%, var(--ui-border));
}

.funnel-onboarding-card--featured .funnel-onboarding-card__cta {
    color: #eab308;
}

.funnel-onboarding-card__icon--templates {
    color: #0891b2;
    background: color-mix(in srgb, #0891b2 12%, var(--ui-surface));
    border: 1px solid color-mix(in srgb, #0891b2 24%, var(--ui-border));
}

.funnel-onboarding-card__cta {
    margin-top: auto;
    padding-top: 0.85rem;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--ui-accent);
}

.funnel-onboarding-template {
    background: var(--ui-surface-muted);
    border-color: var(--ui-border);
}

@keyframes funnel-onboarding-in {
    from {
        opacity: 0;
        transform: translateY(12px) scale(0.98);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}
</style>
