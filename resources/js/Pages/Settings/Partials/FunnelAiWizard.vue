<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { computed, ref } from 'vue';
import axios from 'axios';

const { t } = useI18n();

export interface AiStageDraft {
    name: string;
    color: string;
}

export interface AiFunnelSuggestion {
    name: string;
    description: string;
    color: string;
    rationale: string;
    stages: AiStageDraft[];
}

interface OnboardingForm {
    target_audience: string;
    target_audience_type: 'b2c' | 'b2b' | 'mixed' | '';
    industry: string;
    business_description: string;
    clients_description: string;
    products_description: string;
    sales_process: string;
}

const emit = defineEmits<{
    (e: 'select', suggestion: AiFunnelSuggestion): void;
}>();

const STEP_META = [
    { titleKey: 'stepAudienceTitle', hintKey: 'stepAudienceHint', tipKey: 'audienceTip' },
    { titleKey: 'stepIndustryTitle', hintKey: 'stepIndustryHint', tipKey: 'industryTip' },
    { titleKey: 'stepBusinessTitle', hintKey: 'stepBusinessHint', tipKey: null },
    { titleKey: 'stepClientsTitle', hintKey: 'stepClientsHint', tipKey: null },
    { titleKey: 'stepProductsTitle', hintKey: 'stepProductsHint', tipKey: null },
    { titleKey: 'stepSalesTitle', hintKey: 'stepSalesHint', tipKey: 'generateTip' },
] as const;

const INDUSTRY_PRESET_KEYS = [
    'industryRetail',
    'industryServices',
    'industryB2bSaas',
    'industryManufacturing',
    'industryConstruction',
    'industryEducation',
    'industryBeauty',
    'industryLogistics',
] as const;

const industryPresets = computed(() =>
    INDUSTRY_PRESET_KEYS.map((key) => t(`settings.funnelAiWizard.${key}`)),
);

const TOTAL_INPUT_STEPS = 6;

const wizardStep = ref(0);
const generating = ref(false);
const error = ref<string | null>(null);
const suggestions = ref<AiFunnelSuggestion[]>([]);

const form = ref<OnboardingForm>({
    target_audience_type: '',
    target_audience: '',
    industry: '',
    business_description: '',
    clients_description: '',
    products_description: '',
    sales_process: '',
});

const inputStepNumber = computed(() => wizardStep.value + 1);
const progressPercent = computed(() => {
    if (wizardStep.value >= TOTAL_INPUT_STEPS) {
        return 100;
    }
    return Math.round((wizardStep.value / TOTAL_INPUT_STEPS) * 100);
});

const isVariantsStep = computed(() => wizardStep.value === TOTAL_INPUT_STEPS);

const currentStepMeta = computed(() => {
    if (isVariantsStep.value) {
        return {
            title: t('settings.funnelAiWizard.stepVariantsTitle'),
            hint: t('settings.funnelAiWizard.stepVariantsHint'),
            tip: null,
        };
    }
    const meta = STEP_META[wizardStep.value];
    return {
        title: t(`settings.funnelAiWizard.${meta.titleKey}`),
        hint: t(`settings.funnelAiWizard.${meta.hintKey}`),
        tip: meta.tipKey ? t(`settings.funnelAiWizard.${meta.tipKey}`) : null,
    };
});

function stepLabel(index: number): string {
    return t(`settings.funnelAiWizard.${STEP_META[index].titleKey}`);
}

function resetWizard(): void {
    wizardStep.value = 0;
    generating.value = false;
    error.value = null;
    suggestions.value = [];
    form.value = {
        target_audience_type: '',
        target_audience: '',
        industry: '',
        business_description: '',
        clients_description: '',
        products_description: '',
        sales_process: '',
    };
}

function selectAudienceType(type: 'b2c' | 'b2b' | 'mixed'): void {
    form.value.target_audience_type = type;
}

function applyIndustryPreset(preset: string): void {
    form.value.industry = preset;
}

function buildTargetAudience(): string {
    const typeLabels: Record<string, string> = {
        b2c: t('settings.funnelAiWizard.audienceB2cLabel'),
        b2b: t('settings.funnelAiWizard.audienceB2bLabel'),
        mixed: t('settings.funnelAiWizard.audienceMixedLabel'),
    };
    const typePart = form.value.target_audience_type
        ? typeLabels[form.value.target_audience_type] ?? form.value.target_audience_type
        : '';
    const extra = form.value.target_audience.trim();
    if (typePart && extra) {
        return `${typePart}. ${extra}`;
    }
    return typePart || extra;
}

function validateCurrentStep(): string | null {
    switch (wizardStep.value) {
        case 0:
            if (!form.value.target_audience_type && form.value.target_audience.trim().length < 10) {
                return t('settings.funnelAiWizard.errorAudience');
            }
            return null;
        case 1:
            if (form.value.industry.trim().length < 3) {
                return t('settings.funnelAiWizard.errorIndustry');
            }
            return null;
        case 2:
            if (form.value.business_description.trim().length < 10) {
                return t('settings.funnelAiWizard.errorBusiness');
            }
            return null;
        case 3:
            if (form.value.clients_description.trim().length < 10) {
                return t('settings.funnelAiWizard.errorClients');
            }
            return null;
        case 4:
            if (form.value.products_description.trim().length < 10) {
                return t('settings.funnelAiWizard.errorProducts');
            }
            return null;
        case 5:
            if (form.value.sales_process.trim().length < 10) {
                return t('settings.funnelAiWizard.errorSales');
            }
            return null;
        default:
            return null;
    }
}

function goToStep(index: number): void {
    if (index > wizardStep.value || generating.value) {
        return;
    }
    error.value = null;
    wizardStep.value = index;
}

function goBack(): void {
    error.value = null;
    if (wizardStep.value > 0) {
        wizardStep.value -= 1;
    }
}

function goNext(): void {
    error.value = null;
    const validationError = validateCurrentStep();
    if (validationError) {
        error.value = validationError;
        return;
    }

    if (wizardStep.value >= TOTAL_INPUT_STEPS - 1) {
        void generateVariants();
        return;
    }

    wizardStep.value += 1;
}

async function generateVariants(): Promise<void> {
    if (generating.value) {
        return;
    }

    generating.value = true;
    error.value = null;

    try {
        const { data } = await axios.post(route('settings.funnels.ai-onboarding-suggest'), {
            target_audience: buildTargetAudience(),
            industry: form.value.industry.trim(),
            business_description: form.value.business_description.trim(),
            clients_description: form.value.clients_description.trim(),
            products_description: form.value.products_description.trim(),
            sales_process: form.value.sales_process.trim(),
        });

        const items = data?.suggestions;
        if (!Array.isArray(items) || items.length === 0) {
            error.value = t('settings.funnelAiWizard.errorEmptyResult');
            return;
        }

        suggestions.value = items.map((item: AiFunnelSuggestion) => ({
            name: String(item.name ?? ''),
            description: String(item.description ?? ''),
            color: String(item.color ?? '#01b964'),
            rationale: String(item.rationale ?? ''),
            stages: Array.isArray(item.stages)
                ? item.stages
                    .filter((s) => typeof s?.name === 'string' && s.name.trim() !== '')
                    .map((s) => ({
                        name: s.name.trim(),
                        color: typeof s.color === 'string' && s.color.trim() !== '' ? s.color : '#9ca3af',
                    }))
                : [],
        }));

        wizardStep.value = TOTAL_INPUT_STEPS;
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string } } };
        error.value = e.response?.data?.message || t('settings.funnelAiWizard.errorGenerate');
    } finally {
        generating.value = false;
    }
}

function pickSuggestion(suggestion: AiFunnelSuggestion): void {
    emit('select', suggestion);
}

defineExpose({ resetWizard });
</script>

<template>
    <div class="funnel-ai-wizard">
        <div v-if="!isVariantsStep" class="mb-5 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                <span class="font-medium text-[var(--ui-text-secondary)]">
                    {{ t('settings.funnelAiWizard.stepOf', { current: inputStepNumber, total: TOTAL_INPUT_STEPS }) }}
                </span>
                <span class="funnel-ai-wizard__time-badge">
                    {{ t('settings.funnelAiWizard.timeEstimate') }}
                </span>
            </div>
            <div class="funnel-ai-wizard__progress-track">
                <div
                    class="funnel-ai-wizard__progress-fill"
                    :style="{ width: `${progressPercent}%` }"
                />
            </div>
        </div>

        <div class="flex flex-col gap-5 lg:flex-row lg:gap-6">
            <nav
                v-if="!isVariantsStep"
                class="funnel-ai-wizard__nav lg:w-44 shrink-0"
                aria-label="Wizard steps"
            >
                <ol class="space-y-1">
                    <li v-for="(_, idx) in STEP_META" :key="idx">
                        <button
                            type="button"
                            class="funnel-ai-wizard__nav-item w-full text-left"
                            :class="{
                                'is-active': wizardStep === idx,
                                'is-done': wizardStep > idx,
                            }"
                            @click="goToStep(idx)"
                        >
                            <span class="funnel-ai-wizard__nav-index">{{ idx + 1 }}</span>
                            <span class="min-w-0 truncate">{{ stepLabel(idx) }}</span>
                        </button>
                    </li>
                </ol>
            </nav>

            <div class="min-w-0 flex-1 space-y-4">
                <div class="funnel-ai-wizard__hero">
                    <h4 class="text-base font-semibold text-[var(--ui-text)]">
                        {{ currentStepMeta.title }}
                    </h4>
                    <p class="mt-1 text-sm leading-relaxed text-[var(--ui-text-secondary)]">
                        {{ currentStepMeta.hint }}
                    </p>
                </div>

                <div v-if="wizardStep === 0" class="space-y-4">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-[var(--ui-text)]">
                            {{ t('settings.funnelAiWizard.audienceQuestion') }}
                        </label>
                        <div class="grid gap-2 sm:grid-cols-3">
                            <button
                                v-for="type in (['b2c', 'b2b', 'mixed'] as const)"
                                :key="type"
                                type="button"
                                class="funnel-ai-wizard__choice"
                                :class="{ 'is-active': form.target_audience_type === type }"
                                @click="selectAudienceType(type)"
                            >
                                <span class="text-sm font-semibold text-[var(--ui-text)]">
                                    {{ type === 'b2c' ? t('settings.funnelAiWizard.audienceB2c') : type === 'b2b' ? t('settings.funnelAiWizard.audienceB2b') : t('settings.funnelAiWizard.audienceMixed') }}
                                </span>
                                <span class="mt-0.5 block text-[11px] leading-snug text-[var(--ui-text-secondary)]">
                                    {{ type === 'b2c' ? t('settings.funnelAiWizard.audienceB2cLabel') : type === 'b2b' ? t('settings.funnelAiWizard.audienceB2bLabel') : t('settings.funnelAiWizard.audienceMixedLabel') }}
                                </span>
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm text-[var(--ui-text-secondary)]">
                            {{ t('settings.funnelAiWizard.audienceDetail') }}
                        </label>
                        <textarea
                            v-model="form.target_audience"
                            class="settings-input min-h-[88px]"
                            rows="3"
                            :placeholder="t('settings.funnelAiWizard.audiencePlaceholder')"
                        />
                    </div>
                </div>

                <div v-else-if="wizardStep === 1" class="space-y-4">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-[var(--ui-text)]">
                            {{ t('settings.funnelAiWizard.industry') }}
                        </label>
                        <input
                            v-model="form.industry"
                            type="text"
                            class="settings-input"
                            :placeholder="t('settings.funnelAiWizard.industryPlaceholder')"
                        />
                    </div>
                    <div>
                        <p class="mb-2 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ui-text-secondary)]">
                            {{ t('settings.funnelAiWizard.presetsLabel') }}
                        </p>
                        <div class="ui-chip-row flex-wrap">
                            <button
                                v-for="preset in industryPresets"
                                :key="preset"
                                type="button"
                                class="ui-chip"
                                :class="{ 'is-active': form.industry === preset }"
                                @click="applyIndustryPreset(preset)"
                            >
                                {{ preset }}
                            </button>
                        </div>
                    </div>
                </div>

                <div v-else-if="wizardStep === 2">
                    <label class="mb-1.5 block text-sm font-medium text-[var(--ui-text)]">
                        {{ t('settings.funnelAiWizard.businessDescription') }}
                    </label>
                    <textarea
                        v-model="form.business_description"
                        class="settings-input min-h-[128px]"
                        rows="5"
                        :placeholder="t('settings.funnelAiWizard.businessPlaceholder')"
                    />
                </div>

                <div v-else-if="wizardStep === 3">
                    <label class="mb-1.5 block text-sm font-medium text-[var(--ui-text)]">
                        {{ t('settings.funnelAiWizard.clientsDescription') }}
                    </label>
                    <textarea
                        v-model="form.clients_description"
                        class="settings-input min-h-[108px]"
                        rows="4"
                        :placeholder="t('settings.funnelAiWizard.clientsPlaceholder')"
                    />
                </div>

                <div v-else-if="wizardStep === 4">
                    <label class="mb-1.5 block text-sm font-medium text-[var(--ui-text)]">
                        {{ t('settings.funnelAiWizard.productsDescription') }}
                    </label>
                    <textarea
                        v-model="form.products_description"
                        class="settings-input min-h-[108px]"
                        rows="4"
                        :placeholder="t('settings.funnelAiWizard.productsPlaceholder')"
                    />
                </div>

                <div v-else-if="wizardStep === 5">
                    <label class="mb-1.5 block text-sm font-medium text-[var(--ui-text)]">
                        {{ t('settings.funnelAiWizard.salesProcess') }}
                    </label>
                    <textarea
                        v-model="form.sales_process"
                        class="settings-input min-h-[128px]"
                        rows="5"
                        :placeholder="t('settings.funnelAiWizard.salesPlaceholder')"
                    />
                </div>

                <div v-else-if="isVariantsStep" class="space-y-3">
                    <p class="text-sm text-[var(--ui-text-secondary)]">
                        {{ t('settings.funnelAiWizard.variantsIntro', { count: suggestions.length }) }}
                    </p>

                    <article
                        v-for="(suggestion, idx) in suggestions"
                        :key="idx"
                        class="funnel-ai-wizard__variant"
                    >
                        <div class="flex items-start gap-3">
                            <span
                                class="mt-1 h-3 w-3 shrink-0 rounded-full ring-2 ring-white/10"
                                :style="{ background: suggestion.color }"
                            />
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-semibold text-[var(--ui-text)]">
                                    {{ suggestion.name }}
                                </div>
                                <div
                                    v-if="suggestion.rationale"
                                    class="mt-1 text-xs leading-relaxed"
                                    style="color: #eab308"
                                >
                                    {{ suggestion.rationale }}
                                </div>
                                <div
                                    v-if="suggestion.description"
                                    class="mt-1 text-xs leading-relaxed text-[var(--ui-text-secondary)]"
                                >
                                    {{ suggestion.description }}
                                </div>
                            </div>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold text-[var(--ui-text-secondary)]" :style="{ background: 'var(--ui-surface-muted)' }">
                                {{ suggestion.stages.length }}
                            </span>
                        </div>

                        <div class="funnel-ai-wizard__pipeline">
                            <span
                                v-for="(stage, sIdx) in suggestion.stages"
                                :key="sIdx"
                                class="funnel-ai-wizard__pipeline-stage"
                            >
                                <span class="h-2 w-2 shrink-0 rounded-full" :style="{ background: stage.color }" />
                                {{ stage.name }}
                            </span>
                        </div>

                        <button
                            type="button"
                            class="funnel-ai-wizard__pick-btn"
                            @click="pickSuggestion(suggestion)"
                        >
                            {{ t('settings.funnelAiWizard.pickVariant') }}
                        </button>
                    </article>
                </div>

                <div
                    v-if="currentStepMeta.tip && !isVariantsStep"
                    class="funnel-ai-wizard__tip"
                >
                    <span class="funnel-ai-wizard__tip-label">{{ t('settings.funnelAiWizard.tipLabel') }}</span>
                    <p>{{ currentStepMeta.tip }}</p>
                </div>

                <div v-if="error" class="ui-alert ui-alert--danger text-xs whitespace-pre-line">
                    {{ error }}
                </div>

                <div v-if="!isVariantsStep" class="flex items-center justify-between gap-3 border-t pt-4" :style="{ borderColor: 'var(--ui-border)' }">
                    <button
                        type="button"
                        class="ui-btn ui-btn--ghost ui-btn--sm"
                        :disabled="wizardStep === 0 || generating"
                        @click="goBack"
                    >
                        {{ t('settings.funnelAiWizard.back') }}
                    </button>
                    <button
                        type="button"
                        class="funnel-ai-wizard__next-btn"
                        :disabled="generating"
                        @click="goNext"
                    >
                        <svg
                            v-if="generating"
                            class="h-3.5 w-3.5 animate-spin"
                            fill="none"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                        </svg>
                        <template v-if="generating">{{ t('settings.funnelAiWizard.generating') }}</template>
                        <template v-else-if="wizardStep === 5">{{ t('settings.funnelAiWizard.generateVariants') }}</template>
                        <template v-else>{{ t('settings.funnelAiWizard.next') }}</template>
                    </button>
                </div>

                <div v-else class="flex justify-end border-t pt-4" :style="{ borderColor: 'var(--ui-border)' }">
                    <button
                        type="button"
                        class="ui-btn ui-btn--ghost ui-btn--sm"
                        :disabled="generating"
                        @click="generateVariants"
                    >
                        {{ t('settings.funnelAiWizard.regenerate') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.funnel-ai-wizard {
    --funnel-ai-accent: #eab308;
}

.funnel-ai-wizard__time-badge {
    padding: 0.15rem 0.55rem;
    border-radius: 999px;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--funnel-ai-accent);
    background: color-mix(in srgb, var(--funnel-ai-accent) 12%, transparent);
    border: 1px solid color-mix(in srgb, var(--funnel-ai-accent) 28%, var(--ui-border));
}

.funnel-ai-wizard__progress-track {
    height: 0.375rem;
    border-radius: 999px;
    overflow: hidden;
    background: var(--ui-border-strong);
}

.funnel-ai-wizard__progress-fill {
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, color-mix(in srgb, var(--funnel-ai-accent) 85%, #fbbf24), var(--funnel-ai-accent));
    transition: width 0.3s ease;
}

.funnel-ai-wizard__nav-item {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    padding: 0.45rem 0.55rem;
    border-radius: 0.65rem;
    font-size: 0.75rem;
    color: var(--ui-text-secondary);
    transition: background 0.15s ease, color 0.15s ease;
}

.funnel-ai-wizard__nav-item:hover {
    background: var(--ui-surface-hover);
    color: var(--ui-text);
}

.funnel-ai-wizard__nav-item.is-active {
    background: color-mix(in srgb, var(--funnel-ai-accent) 10%, var(--ui-surface-muted));
    color: var(--ui-text);
    font-weight: 600;
}

.funnel-ai-wizard__nav-item.is-done .funnel-ai-wizard__nav-index {
    background: color-mix(in srgb, var(--funnel-ai-accent) 18%, var(--ui-surface));
    color: var(--funnel-ai-accent);
    border-color: color-mix(in srgb, var(--funnel-ai-accent) 35%, var(--ui-border));
}

.funnel-ai-wizard__nav-index {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.35rem;
    height: 1.35rem;
    shrink: 0;
    border-radius: 999px;
    font-size: 0.625rem;
    font-weight: 700;
    border: 1px solid var(--ui-border);
    background: var(--ui-surface);
}

.funnel-ai-wizard__nav-item.is-active .funnel-ai-wizard__nav-index {
    background: var(--funnel-ai-accent);
    color: #1a1a1a;
    border-color: transparent;
}

.funnel-ai-wizard__hero {
    padding: 0.85rem 1rem;
    border-radius: 0.85rem;
    border: 1px solid var(--ui-border);
    background: color-mix(in srgb, var(--ui-surface-muted) 80%, var(--ui-surface));
}

.funnel-ai-wizard__choice {
    padding: 0.75rem 0.85rem;
    border-radius: 0.85rem;
    border: 1px solid var(--ui-border);
    background: var(--ui-surface-muted);
    text-align: left;
    transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}

.funnel-ai-wizard__choice:hover {
    border-color: color-mix(in srgb, var(--funnel-ai-accent) 35%, var(--ui-border));
}

.funnel-ai-wizard__choice.is-active {
    border-color: color-mix(in srgb, var(--funnel-ai-accent) 45%, var(--ui-border));
    background: color-mix(in srgb, var(--funnel-ai-accent) 8%, var(--ui-surface));
    box-shadow: 0 0 0 1px color-mix(in srgb, var(--funnel-ai-accent) 20%, transparent);
}

.funnel-ai-wizard__tip {
    padding: 0.75rem 0.9rem;
    border-radius: 0.75rem;
    border: 1px dashed color-mix(in srgb, var(--funnel-ai-accent) 35%, var(--ui-border));
    background: color-mix(in srgb, var(--funnel-ai-accent) 5%, var(--ui-surface));
    font-size: 0.75rem;
    line-height: 1.45;
    color: var(--ui-text-secondary);
}

.funnel-ai-wizard__tip-label {
    display: block;
    margin-bottom: 0.25rem;
    font-size: 0.625rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--funnel-ai-accent);
}

.funnel-ai-wizard__variant {
    padding: 1rem;
    border-radius: 1rem;
    border: 1px solid var(--ui-border);
    background: linear-gradient(
        160deg,
        color-mix(in srgb, var(--funnel-ai-accent) 4%, var(--ui-surface)) 0%,
        var(--ui-surface-muted) 100%
    );
    transition: border-color 0.18s ease, transform 0.18s ease;
}

.funnel-ai-wizard__variant:hover {
    border-color: color-mix(in srgb, var(--funnel-ai-accent) 30%, var(--ui-border));
    transform: translateY(-1px);
}

.funnel-ai-wizard__pipeline {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    margin-top: 0.85rem;
}

.funnel-ai-wizard__pipeline-stage {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.2rem 0.55rem;
    border-radius: 999px;
    font-size: 0.6875rem;
    color: var(--ui-text-secondary);
    background: var(--ui-surface);
    border: 1px solid var(--ui-border);
}

.funnel-ai-wizard__pick-btn {
    width: 100%;
    margin-top: 0.85rem;
    padding: 0.55rem 0.75rem;
    border-radius: 0.65rem;
    font-size: 0.75rem;
    font-weight: 600;
    color: #1a1a1a;
    background: var(--funnel-ai-accent);
    transition: filter 0.15s ease;
}

.funnel-ai-wizard__pick-btn:hover {
    filter: brightness(1.05);
}

.funnel-ai-wizard__next-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.45rem 1rem;
    border-radius: 0.65rem;
    font-size: 0.8125rem;
    font-weight: 600;
    color: #1a1a1a;
    background: var(--funnel-ai-accent);
    transition: filter 0.15s ease;
}

.funnel-ai-wizard__next-btn:hover:not(:disabled) {
    filter: brightness(1.05);
}

.funnel-ai-wizard__next-btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

@media (max-width: 1023px) {
    .funnel-ai-wizard__nav {
        display: flex;
        overflow-x: auto;
        padding-bottom: 0.25rem;
    }

    .funnel-ai-wizard__nav ol {
        display: flex;
        gap: 0.35rem;
    }

    .funnel-ai-wizard__nav-item {
        white-space: nowrap;
        min-width: max-content;
    }
}
</style>
