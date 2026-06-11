<script setup lang="ts">
import {
    calculateTokenUsage,
    formatExchangeRate,
    formatKzt,
    formatNumber,
    formatUsd,
    normalizeInputs,
    type BenchmarkMeta,
    type CalculatorInputs,
    type CalculatorResult,
    type ExchangeRate,
    type PricingConfig,
    type ScenarioConfig,
} from '@/utils/aiTokenCalculator';
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import LandingHead from '@/Components/Landing/LandingHead.vue';
import LandingHeader from '@/Components/Landing/LandingHeader.vue';
import { useLandingLocale } from '@/composables/useLandingLocale';
import { computed, reactive, ref, watch } from 'vue';

const { t } = useLandingLocale();

function presetLabel(key: string, fallback: string): string {
    const translated = t(`landing.calcPresets.${key}.label` as 'landing.calcPresets.start.label');
    return translated === `landing.calcPresets.${key}.label` ? fallback : translated;
}

function scenarioLabel(id: string, fallback: string): string {
    const translated = t(`landing.calcScenarios.${id}.label` as 'landing.calcScenarios.ai_reply.label');
    return translated === `landing.calcScenarios.${id}.label` ? fallback : translated;
}

type Preset = Partial<CalculatorInputs> & { label: string; hint?: string };

const PRESET_ICONS: Record<string, string> = {
    start: '🏪',
    growth: '📈',
    active: '👥',
    callcenter: '📞',
};

function presetIcon(key: string): string {
    return PRESET_ICONS[key] ?? '⚙️';
}

type FieldDef = {
    key: keyof CalculatorInputs;
    label: string;
    hint: string;
    min?: number;
    max?: number;
    step?: number;
    suffix?: string;
    type?: 'range' | 'toggle';
    format?: 'percent' | 'voice_duration' | 'plain';
};


function calcField(
    id: string,
    key: keyof CalculatorInputs,
    extra: Omit<FieldDef, 'key' | 'label' | 'hint'>,
): FieldDef {
    return {
        key,
        label: t(`landing.calc.${id}.label` as 'landing.calc.leadsPerDay.label'),
        hint: t(`landing.calc.${id}.hint` as 'landing.calc.leadsPerDay.hint'),
        ...extra,
    };
}

const heroField = computed<FieldDef>(() =>
    calcField('leadsPerDay', 'leads_per_day', { min: 1, max: 500, step: 1 }),
);

const funnelField = computed<FieldDef>(() =>
    calcField('funnelEnabled', 'funnel_enabled', { type: 'toggle' }),
);

const props = defineProps<{
    rootDomain?: string;
    calculator: {
        model: string;
        defaults: CalculatorInputs;
        presets: Record<string, Preset>;
        scenarios: ScenarioConfig[];
        pricing: PricingConfig;
        background_monthly_usd: number;
        subscription_kzt: number;
        exchange_rate: ExchangeRate;
        benchmarks: BenchmarkMeta;
        initial: CalculatorResult;
    };
}>();

const inputs = reactive<CalculatorInputs>({ ...props.calculator.defaults });
const showAdvanced = ref(false);
const showTechnical = ref(false);
const activePreset = ref<string | null>('growth');
const pricePulse = ref(false);
let pricePulseTimer: ReturnType<typeof setTimeout> | null = null;

const result = computed(() =>
    calculateTokenUsage(
        normalizeInputs(inputs, props.calculator.defaults),
        props.calculator.scenarios,
        props.calculator.pricing,
        props.calculator.background_monthly_usd,
        props.calculator.subscription_kzt,
        props.calculator.exchange_rate,
        props.calculator.benchmarks.trigger_rates,
    ),
);

const exchangeRateLabel = computed(() => formatExchangeRate(props.calculator.exchange_rate));

const aiSharePercent = computed(() => {
    const inbound = result.value.inbound_per_month;
    if (inbound <= 0) {
        return 0;
    }

    return Math.min(100, Math.round((result.value.ai_inbound_per_month / inbound) * 100));
});

watch(
    () => result.value.totals.api_cost_kzt,
    () => {
        pricePulse.value = true;
        if (pricePulseTimer) {
            clearTimeout(pricePulseTimer);
        }
        pricePulseTimer = setTimeout(() => {
            pricePulse.value = false;
        }, 500);
    },
);

const activePresetLabel = computed(() => {
    if (!activePreset.value) {
        return t('landing.calcPresetCustom');
    }

    return presetLabel(activePreset.value, props.calculator.presets[activePreset.value]?.label ?? t('landing.calcPresetCustom'));
});

const presetEntries = computed(() => Object.entries(props.calculator.presets));

function applyPreset(key: string): void {
    const preset = props.calculator.presets[key];
    if (!preset) {
        return;
    }
    activePreset.value = key;
    Object.assign(inputs, props.calculator.defaults, preset);
}

const coreFields = computed<FieldDef[]>(() => [
    calcField('inboundMsgs', 'inbound_msgs_per_lead', { min: 1, max: 50, step: 1 }),
    calcField('aiReplyRate', 'ai_reply_rate', { min: 0, max: 100, step: 5, suffix: '%', format: 'percent' }),
    calcField('operators', 'operators', { min: 1, max: 50, step: 1 }),
    calcField('voiceMsgRate', 'voice_msg_rate', { min: 0, max: 50, step: 5, suffix: '%', format: 'percent' }),
]);

const extraFields = computed<FieldDef[]>(() => [
    calcField('silentLeads', 'silent_leads_per_day', { min: 0, max: 100, step: 1 }),
    calcField('operatorAiUses', 'operator_ai_uses_per_day', { min: 0, max: 50, step: 1 }),
    calcField('orchestratorRate', 'orchestrator_rate', { min: 0, max: 100, step: 5, suffix: '%', format: 'percent' }),
    calcField('avgVoiceDuration', 'avg_voice_duration_sec', { min: 5, max: 120, step: 5, format: 'voice_duration' }),
    calcField('translations', 'translations_per_day', { min: 0, max: 100, step: 1 }),
    calcField('workspaceQueries', 'workspace_queries_per_day', { min: 0, max: 50, step: 1 }),
    calcField('workDays', 'work_days_per_month', { min: 20, max: 31, step: 1 }),
]);

function sliderFillPercent(field: FieldDef): number {
    const value = sliderValue(field.key);
    const min = field.min ?? 0;
    const max = field.max ?? 100;
    if (max <= min) {
        return 0;
    }

    return ((value - min) / (max - min)) * 100;
}

function sliderBounds(field: FieldDef): { min: string; max: string } {
    return {
        min: formatNumber(field.min ?? 0),
        max: formatNumber(field.max ?? 100),
    };
}

function displayValue(field: FieldDef): string {
    const value = sliderValue(field.key);
    if (field.format === 'percent') {
        if (value === 0) {
            return t('landing.calcModeOff');
        }
        if (value === 100) {
            return t('landing.calcModeAlways');
        }
        return `~${value}%`;
    }
    if (field.format === 'voice_duration') {
        if (value <= 15) {
            return t('landing.calcVoiceShort');
        }
        if (value <= 35) {
            return t('landing.calcVoiceMedium');
        }
        return t('landing.calcVoiceLong');
    }
    return `${value}${field.suffix ?? ''}`;
}

function onInputChange(): void {
    activePreset.value = null;
}

function sliderValue(key: keyof CalculatorInputs): number {
    const value = inputs[key];
    return typeof value === 'number' ? value : 0;
}

function updateSlider(key: keyof CalculatorInputs, raw: string): void {
    onInputChange();
    if (key === 'funnel_enabled') {
        inputs.funnel_enabled = raw === 'true';
        return;
    }
    const num = Number(raw);
    if (key === 'leads_per_day') inputs.leads_per_day = num;
    else if (key === 'inbound_msgs_per_lead') inputs.inbound_msgs_per_lead = num;
    else if (key === 'ai_reply_rate') inputs.ai_reply_rate = num;
    else if (key === 'orchestrator_rate') inputs.orchestrator_rate = num;
    else if (key === 'voice_msg_rate') inputs.voice_msg_rate = num;
    else if (key === 'avg_voice_duration_sec') inputs.avg_voice_duration_sec = num;
    else if (key === 'silent_leads_per_day') inputs.silent_leads_per_day = num;
    else if (key === 'operators') inputs.operators = num;
    else if (key === 'operator_ai_uses_per_day') inputs.operator_ai_uses_per_day = num;
    else if (key === 'translations_per_day') inputs.translations_per_day = num;
    else if (key === 'workspace_queries_per_day') inputs.workspace_queries_per_day = num;
    else if (key === 'work_days_per_month') inputs.work_days_per_month = num;
}

function setBooleanInput(key: keyof CalculatorInputs, value: boolean): void {
    if (key === 'funnel_enabled') {
        inputs.funnel_enabled = value;
        onInputChange();
    }
}

const visibleScenarios = computed(() =>
    result.value.scenarios
        .filter((s) => s.cost_usd > 0 || s.calls > 0)
        .map((row) => ({
            ...row,
            label: scenarioLabel(String(row.id), row.label),
        })),
);
</script>

<template>
    <div class="landing">
        <LandingHead page="calculator" />

        <LandingHeader mode="minimal" />

        <main class="landing__main landing__main--wide">
            <section class="calc-intro">
                <h1 class="calc-intro__title">{{ t('landing.calculatorIntroTitle') }}</h1>
                <p class="calc-intro__text">
                    {{ t('landing.calcIntro') }}
                </p>
            </section>

            <div class="calc-layout">
                <div class="calc-sheet">
                    <div class="calc-sheet__controls">
                    <header class="calc-sheet__header">
                        <h2 class="calc-sheet__heading">{{ t('landing.calculatorSheetTitle') }}</h2>
                        <p class="calc-sheet__sub">{{ t('landing.calculatorSheetSub') }}</p>
                    </header>

                    <div class="ui-section-tabs calc-segments" role="tablist" :aria-label="t('landing.calcProfileAria')">
                        <button
                            v-for="[key, preset] in presetEntries"
                            :key="key"
                            type="button"
                            role="tab"
                            class="ui-section-tab calc-segment"
                            :class="{ 'is-active': activePreset === key }"
                            :aria-selected="activePreset === key"
                            @click="applyPreset(key)"
                        >
                            <span class="ui-section-tab__inner calc-segment__inner">
                                <span class="calc-segment__emoji" aria-hidden="true">{{ presetIcon(key) }}</span>
                                <span class="calc-segment__label">{{ presetLabel(key, preset.label) }}</span>
                            </span>
                        </button>
                    </div>

                    <div class="calc-feature">
                        <div class="calc-feature__top">
                            <div>
                                <div class="calc-field__label-wrap">
                                    <span class="calc-feature__kicker">{{ heroField.label }}</span>
                                    <span
                                        class="calc-info"
                                        tabindex="0"
                                        role="button"
                                        :aria-label="heroField.hint"
                                        :title="heroField.hint"
                                    >
                                        <svg class="calc-info__icon" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                            <circle cx="8" cy="8" r="6.75" stroke="currentColor" stroke-width="1.2" />
                                            <path d="M8 7.1V11M8 5.1h.01" stroke="currentColor" stroke-width="1.35" stroke-linecap="round" />
                                        </svg>
                                        <span class="calc-info__tip" role="tooltip">{{ heroField.hint }}</span>
                                    </span>
                                </div>
                                <strong class="calc-feature__value">{{ inputs.leads_per_day }}</strong>
                            </div>
                            <div class="calc-feature__aside">
                                <span class="calc-feature__kicker">{{ t('landing.calcProfileKicker') }}</span>
                                <strong class="calc-feature__tier">{{ activePresetLabel }}</strong>
                            </div>
                        </div>
                        <div class="calc-range calc-range--hero" :style="{ '--fill': `${sliderFillPercent(heroField)}%` }">
                            <div class="calc-range__track" aria-hidden="true">
                                <div class="calc-range__fill" />
                            </div>
                            <input
                                id="leads_per_day"
                                type="range"
                                class="calc-range__input"
                                :min="heroField.min"
                                :max="heroField.max"
                                :step="heroField.step"
                                :value="inputs.leads_per_day"
                                @input="updateSlider('leads_per_day', ($event.target as HTMLInputElement).value)"
                            />
                        </div>
                        <div class="calc-feature__bounds">
                            <span>{{ sliderBounds(heroField).min }}</span>
                            <span>{{ sliderBounds(heroField).max }}</span>
                        </div>
                    </div>

                    <div class="calc-fields">
                        <div v-for="field in coreFields" :key="field.key" class="calc-field">
                            <div class="calc-field__row">
                                <div class="calc-field__label-wrap">
                                    <label :for="field.key" class="calc-field__label">{{ field.label }}</label>
                                    <span
                                        class="calc-info"
                                        tabindex="0"
                                        role="button"
                                        :aria-label="field.hint"
                                        :title="field.hint"
                                    >
                                        <svg class="calc-info__icon" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                            <circle cx="8" cy="8" r="6.75" stroke="currentColor" stroke-width="1.2" />
                                            <path d="M8 7.1V11M8 5.1h.01" stroke="currentColor" stroke-width="1.35" stroke-linecap="round" />
                                        </svg>
                                        <span class="calc-info__tip" role="tooltip">{{ field.hint }}</span>
                                    </span>
                                </div>
                                <span class="calc-field__value">{{ displayValue(field) }}</span>
                            </div>
                            <div class="calc-range" :style="{ '--fill': `${sliderFillPercent(field)}%` }">
                                <div class="calc-range__track" aria-hidden="true">
                                    <div class="calc-range__fill" />
                                </div>
                                <input
                                    :id="field.key"
                                    type="range"
                                    class="calc-range__input"
                                    :min="field.min"
                                    :max="field.max"
                                    :step="field.step ?? 1"
                                    :value="sliderValue(field.key)"
                                    @input="updateSlider(field.key, ($event.target as HTMLInputElement).value)"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="ui-check-row calc-toggle-row">
                        <div>
                            <div class="calc-field__label-wrap">
                                <span class="calc-field__label">{{ funnelField.label }}</span>
                                <span
                                    class="calc-info"
                                    tabindex="0"
                                    role="button"
                                    :aria-label="funnelField.hint"
                                    :title="funnelField.hint"
                                >
                                    <svg class="calc-info__icon" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                        <circle cx="8" cy="8" r="6.75" stroke="currentColor" stroke-width="1.2" />
                                        <path d="M8 7.1V11M8 5.1h.01" stroke="currentColor" stroke-width="1.35" stroke-linecap="round" />
                                    </svg>
                                    <span class="calc-info__tip" role="tooltip">{{ funnelField.hint }}</span>
                                </span>
                            </div>
                        </div>
                        <UiCheckbox
                            :model-value="inputs.funnel_enabled"
                            size="sm"
                            :aria-label="t('landing.calcFunnelAria')"
                            @update:model-value="(v) => setBooleanInput('funnel_enabled', v)"
                        />
                    </div>

                    <button
                        type="button"
                        class="calc-more"
                        @click="showAdvanced = !showAdvanced"
                    >
                        {{ showAdvanced ? t('landing.hideAdvanced') : t('landing.showAdvanced') }}
                    </button>

                    <div v-if="showAdvanced" class="calc-fields calc-fields--extra">
                        <div v-for="field in extraFields" :key="field.key" class="calc-field">
                            <div class="calc-field__row">
                                <div class="calc-field__label-wrap">
                                    <label :for="`extra-${field.key}`" class="calc-field__label">{{ field.label }}</label>
                                    <span
                                        class="calc-info"
                                        tabindex="0"
                                        role="button"
                                        :aria-label="field.hint"
                                        :title="field.hint"
                                    >
                                        <svg class="calc-info__icon" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                            <circle cx="8" cy="8" r="6.75" stroke="currentColor" stroke-width="1.2" />
                                            <path d="M8 7.1V11M8 5.1h.01" stroke="currentColor" stroke-width="1.35" stroke-linecap="round" />
                                        </svg>
                                        <span class="calc-info__tip" role="tooltip">{{ field.hint }}</span>
                                    </span>
                                </div>
                                <span class="calc-field__value">{{ displayValue(field) }}</span>
                            </div>
                            <div class="calc-range" :style="{ '--fill': `${sliderFillPercent(field)}%` }">
                                <div class="calc-range__track" aria-hidden="true">
                                    <div class="calc-range__fill" />
                                </div>
                                <input
                                    :id="`extra-${field.key}`"
                                    type="range"
                                    class="calc-range__input"
                                    :min="field.min"
                                    :max="field.max"
                                    :step="field.step ?? 1"
                                    :value="sliderValue(field.key)"
                                    @input="updateSlider(field.key, ($event.target as HTMLInputElement).value)"
                                />
                            </div>
                        </div>
                    </div>
                </div>
                </div>

                <div class="calc-layout__aside">
                <aside class="calc-sheet__receipt">
                    <div class="calc-receipt__panel">
                        <div class="calc-receipt__head">
                            <div class="calc-receipt__total" :class="{ 'calc-receipt__total--pulse': pricePulse }">
                                <span class="calc-receipt__kicker">{{ t('landing.calculatorTotal') }}</span>
                                <div class="calc-receipt__price-line">
                                    <strong class="calc-receipt__price">{{ formatKzt(result.totals.api_cost_kzt) }}</strong>
                                    <span class="calc-receipt__badge ui-badge ui-badge--neutral">{{ activePresetLabel }}</span>
                                </div>
                                <span class="calc-receipt__meta">{{ exchangeRateLabel }}</span>
                            </div>
                        </div>

                        <div class="calc-receipt__scroll">
                            <dl class="calc-receipt__lines">
                                <div class="calc-receipt__line">
                                    <dt>{{ t('landing.calculatorInbound') }}</dt>
                                    <dd>{{ formatNumber(result.inbound_per_month) }}</dd>
                                </div>
                                <div class="calc-receipt__line">
                                    <dt>{{ t('landing.calculatorAiHandled') }}</dt>
                                    <dd>{{ formatNumber(result.ai_inbound_per_month) }} <span class="calc-receipt__pct">({{ aiSharePercent }}%)</span></dd>
                                </div>
                                <div class="calc-receipt__line">
                                    <dt>{{ t('landing.calculatorVoice') }}</dt>
                                    <dd>{{ result.totals.whisper_minutes }}</dd>
                                </div>
                                <div class="calc-receipt__line">
                                    <dt>{{ t('landing.calculatorApiCost') }}</dt>
                                    <dd>{{ formatUsd(result.totals.api_cost_usd) }}</dd>
                                </div>
                                <div class="calc-receipt__line calc-receipt__line--sep">
                                    <dt>{{ t('landing.calculatorSubscription') }}</dt>
                                    <dd>{{ formatKzt(result.totals.subscription_kzt) }}</dd>
                                </div>
                            </dl>

                            <p class="calc-receipt__note">
                                {{ t('landing.calculatorSubscriptionNote') }}
                            </p>

                            <button type="button" class="calc-more calc-more--ghost" @click="showTechnical = !showTechnical">
                                {{ showTechnical ? t('landing.hideTechnical') : t('landing.showTechnical') }}
                            </button>

                            <div v-if="showTechnical" class="calc-receipt__details">
                                <table class="calc-table">
                                    <thead>
                                        <tr>
                                            <th>{{ t('landing.scenarioCol') }}</th>
                                            <th>{{ t('landing.countCol') }}</th>
                                            <th>$</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="row in visibleScenarios" :key="row.id">
                                            <td>{{ row.label }}</td>
                                            <td>{{ formatNumber(Math.round(row.calls)) }}</td>
                                            <td>{{ formatUsd(row.cost_usd) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <a href="/#request" class="ui-btn ui-btn--primary calc-receipt__cta">{{ t('landing.calcReceiptCta') }}</a>
                    </div>
                </aside>
                </div>
            </div>
        </main>
    </div>
</template>

<style scoped>
.landing {
    position: relative;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    min-height: 100dvh;
    background: var(--wa-bg);
    color: var(--wa-text);
    font-family: Figtree, ui-sans-serif, system-ui, sans-serif;
    -webkit-font-smoothing: antialiased;
}

@media (min-width: 961px) {
    .landing {
        height: 100dvh;
        max-height: 100dvh;
        overflow: hidden;
    }
}

.landing::before {
    content: '';
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 0;
    opacity: 0.45;
    background-image: radial-gradient(color-mix(in srgb, var(--wa-text) 5%, transparent) 1px, transparent 1px);
    background-size: 22px 22px;
}

.landing__header,
.landing__main {
    position: relative;
    z-index: 1;
}

.landing__main {
    flex: 1;
    width: 100%;
    max-width: min(92rem, 100%);
    margin: 0 auto;
    padding: 0.75rem clamp(1.25rem, 2.5vw, 2.5rem) 3rem;
    --calc-page-pad: clamp(1.25rem, 2.5vw, 2.5rem);
    --calc-max-w: 92rem;
    --calc-receipt-w: 22rem;
}

@media (max-width: 767px) {
    .landing__main {
        padding: 0.75rem 1rem 2rem;
        --calc-page-pad: 1rem;
    }
}

@media (min-width: 961px) {
    .landing__main {
        display: flex;
        flex-direction: column;
        min-height: 0;
        overflow: hidden;
        padding-top: 0.875rem;
        padding-bottom: 1.25rem;
    }
}

.landing__main--wide {
    max-width: min(92rem, 100%);
}

.calc-layout {
    position: relative;
}

@media (min-width: 961px) {
    .calc-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) var(--calc-receipt-w);
        gap: 1.25rem;
        align-items: stretch;
        flex: 1;
        min-height: 0;
        overflow: hidden;
    }

    .calc-sheet {
        grid-column: 1;
        grid-row: 1;
        min-height: 0;
        max-height: 100%;
        display: flex;
        flex-direction: column;
    }

    .calc-layout__aside {
        grid-column: 2;
        grid-row: 1;
        position: relative;
        min-height: 0;
        height: 100%;
    }
}

.calc-intro {
    margin: 0 0 1.25rem;
    padding: 0;
}

@media (min-width: 961px) {
    .calc-intro {
        flex-shrink: 0;
        margin-bottom: 1rem;
    }
}

.calc-intro__title {
    margin: 0 0 0.375rem;
    font-size: clamp(1.875rem, 3.5vw, 2.375rem);
    font-weight: 700;
    letter-spacing: -0.03em;
    line-height: 1.15;
}

.calc-intro__text {
    margin: 0;
    max-width: 42rem;
    font-size: 1rem;
    line-height: 1.55;
    color: var(--wa-text-secondary);
}

.calc-sheet {
    --thumb-size: 1.25rem;
    --slider-accent: var(--ui-accent);

    border-radius: var(--primitive-radius-lg);
    border: 1px solid var(--ui-border);
    background: var(--ui-surface);
    color: var(--ui-text);
    box-shadow: var(--ui-shadow-card);
}

.calc-sheet__controls {
    padding: 2rem 2.25rem 1.75rem;
    border-radius: var(--primitive-radius-lg);
}

@media (min-width: 961px) {
    .calc-sheet__controls {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        overscroll-behavior: contain;
        -webkit-overflow-scrolling: touch;
    }
}

@media (max-width: 960px) {
    .calc-layout {
        display: flex;
        flex-direction: column;
        gap: 0;
    }

    .calc-sheet__controls {
        border-radius: var(--primitive-radius-lg) var(--primitive-radius-lg) 0 0;
    }
}

@media (min-width: 1100px) {
    .calc-sheet__controls {
        padding: 2.25rem 2.75rem 2rem;
    }
}

.calc-sheet__header {
    margin-bottom: 1.25rem;
}

.calc-sheet__heading {
    margin: 0 0 0.2rem;
    font-size: 1.125rem;
    font-weight: 700;
    letter-spacing: -0.02em;
}

.calc-sheet__sub {
    margin: 0;
    font-size: 0.8125rem;
    color: var(--ui-text-secondary);
}

.calc-segments {
    margin-bottom: 1.75rem;
    padding: 6px;
    gap: 6px;
    align-items: stretch;
}

.calc-segments :deep(.ui-section-tab) {
    display: flex;
    flex: 1 1 0;
    align-items: center;
    justify-content: center;
    min-height: 5.5rem;
    height: auto;
    padding: 0.75rem 0.5rem;
    font-size: 0.8125rem;
    line-height: 1.2;
}

.calc-segment__inner {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.375rem;
    width: 100%;
    height: 100%;
    min-height: 4.5rem;
    text-align: center;
}

.calc-segment__emoji {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 2rem;
    width: 2rem;
    height: 2rem;
    font-size: 1.375rem;
    line-height: 1;
}

.calc-segment__label {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 2.5em;
    width: 100%;
    font-weight: 600;
    letter-spacing: -0.01em;
    text-align: center;
    line-height: 1.2;
}

@media (min-width: 900px) {
    .calc-segments :deep(.ui-section-tab) {
        min-height: 5.75rem;
        padding: 0.85rem 0.65rem;
        font-size: 0.875rem;
    }

    .calc-segment__emoji {
        flex-basis: 2.125rem;
        width: 2.125rem;
        height: 2.125rem;
        font-size: 1.5rem;
    }
}

@media (max-width: 640px) {
    .calc-segments {
        overflow-x: auto;
        flex-wrap: nowrap;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
    }

    .calc-segments::-webkit-scrollbar {
        display: none;
    }

    .calc-segments :deep(.ui-section-tab) {
        flex: 0 0 auto;
        min-width: 7.5rem;
    }
}

.calc-feature {
    margin-bottom: 1.5rem;
    padding-bottom: 1.25rem;
    border-bottom: 1px solid var(--ui-border);
}

.calc-feature__top {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: end;
    gap: var(--primitive-gap-lg);
    margin-bottom: 0.85rem;
}

.calc-feature__kicker {
    display: block;
    font-size: 0.75rem;
    color: var(--ui-text-secondary);
    margin-bottom: 0.15rem;
}

.calc-feature__value {
    font-size: clamp(2rem, 3vw, 2.5rem);
    font-weight: 800;
    letter-spacing: -0.04em;
    font-variant-numeric: tabular-nums;
    line-height: 1;
}

.calc-feature__tier {
    font-size: 0.9375rem;
    font-weight: 700;
    text-align: right;
    line-height: 1.2;
}

.calc-feature__aside {
    text-align: right;
    min-width: 7rem;
}

.calc-feature__bounds {
    display: flex;
    justify-content: space-between;
    margin-top: 0.35rem;
    font-size: 0.6875rem;
    color: var(--ui-text-muted);
    font-variant-numeric: tabular-nums;
}

.calc-fields {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.25rem;
}

@media (min-width: 900px) {
    .calc-fields {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        column-gap: 2rem;
        row-gap: 1.35rem;
    }
}

.calc-fields--extra {
    margin-top: var(--primitive-gap-lg);
    padding-top: var(--primitive-gap-lg);
    border-top: 1px solid var(--ui-border);
}

.calc-field__row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 0.75rem;
    margin-bottom: 0.45rem;
}

.calc-field__label-wrap {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    min-width: 0;
}

.calc-field__label-wrap .calc-feature__kicker {
    display: inline;
    margin-bottom: 0;
}

.calc-field__label {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--ui-text);
}

.calc-info {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 1rem;
    height: 1rem;
    color: var(--ui-text-muted);
    cursor: help;
    outline: none;
}

.calc-info__icon {
    width: 0.875rem;
    height: 0.875rem;
}

.calc-info:hover,
.calc-info:focus-visible {
    color: var(--ui-accent);
}

.calc-info__tip {
    position: absolute;
    left: 0;
    top: calc(100% + 0.45rem);
    z-index: 20;
    width: max-content;
    max-width: min(16rem, 70vw);
    padding: 0.55rem 0.7rem;
    border-radius: var(--primitive-radius-sm);
    border: 1px solid var(--ui-border);
    background: var(--ui-surface-raised);
    box-shadow: var(--ui-shadow-soft);
    font-size: 0.75rem;
    font-weight: 500;
    line-height: 1.45;
    color: var(--ui-text-secondary);
    text-align: left;
    pointer-events: none;
    opacity: 0;
    visibility: hidden;
    transform: translateY(-4px);
    transition: opacity 0.15s ease, transform 0.15s ease, visibility 0.15s ease;
}

.calc-info__tip::after {
    content: '';
    position: absolute;
    bottom: 100%;
    left: 0.55rem;
    width: 0.5rem;
    height: 0.5rem;
    margin-bottom: -0.28rem;
    background: var(--ui-surface-raised);
    border-left: 1px solid var(--ui-border);
    border-top: 1px solid var(--ui-border);
    transform: rotate(45deg);
}

.calc-info:hover .calc-info__tip,
.calc-info:focus-visible .calc-info__tip,
.calc-info:focus-within .calc-info__tip {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.calc-field__value {
    font-size: 0.8125rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    color: var(--ui-text);
}

.calc-toggle-row {
    margin-top: 1.25rem;
}

.calc-more {
    display: block;
    width: 100%;
    margin-top: var(--primitive-gap-lg);
    padding: 0;
    font-family: inherit;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--ui-text-secondary);
    background: none;
    border: none;
    cursor: pointer;
    text-align: left;
    text-decoration: underline;
    text-underline-offset: 3px;
}

.calc-more:hover {
    color: var(--ui-text);
}

.calc-more--ghost {
    width: auto;
    margin-bottom: 0.75rem;
}

.calc-range {
    position: relative;
    height: 1.5rem;
    --fill: 0%;
}

.calc-range--hero {
    height: 2rem;
    --thumb-size: 1.375rem;
}

.calc-range__track {
    position: absolute;
    left: calc(var(--thumb-size) / 2);
    right: calc(var(--thumb-size) / 2);
    top: 50%;
    height: 0.4375rem;
    transform: translateY(-50%);
    border-radius: var(--primitive-radius-pill);
    background: color-mix(in srgb, var(--slider-accent) 14%, var(--ui-surface-inset));
    overflow: hidden;
    pointer-events: none;
}

.calc-range--hero .calc-range__track {
    height: 0.5625rem;
}

.calc-range__fill {
    height: 100%;
    width: var(--fill);
    border-radius: inherit;
    background: var(--slider-accent);
}

.calc-range__input {
    position: relative;
    z-index: 1;
    width: 100%;
    height: 100%;
    margin: 0;
    background: transparent;
    cursor: pointer;
    -webkit-appearance: none;
    appearance: none;
}

.calc-range__input::-webkit-slider-runnable-track {
    height: 0.4375rem;
    background: transparent;
}

.calc-range--hero .calc-range__input::-webkit-slider-runnable-track {
    height: 0.5625rem;
}

.calc-range__input::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: var(--thumb-size);
    height: var(--thumb-size);
    margin-top: calc((0.4375rem - var(--thumb-size)) / 2);
    border-radius: 50%;
    background: var(--slider-accent);
    border: 2px solid var(--ui-surface-raised);
    box-shadow:
        0 0 0 1px color-mix(in srgb, var(--slider-accent) 40%, transparent),
        var(--ui-shadow-soft);
}

.calc-range--hero .calc-range__input::-webkit-slider-thumb {
    margin-top: calc((0.5625rem - var(--thumb-size)) / 2);
    border-width: 3px;
}

.calc-range__input::-moz-range-track {
    height: 0.4375rem;
    background: transparent;
    border: none;
}

.calc-range__input::-moz-range-thumb {
    width: var(--thumb-size);
    height: var(--thumb-size);
    border-radius: 50%;
    background: var(--slider-accent);
    border: 2px solid var(--ui-surface-raised);
    box-shadow:
        0 0 0 1px color-mix(in srgb, var(--slider-accent) 40%, transparent),
        var(--ui-shadow-soft);
}

.calc-sheet__receipt {
    padding: 2rem 2.25rem;
    background: var(--ui-surface-muted);
    border: 1px solid var(--ui-border);
    border-radius: var(--primitive-radius-lg);
    box-shadow: var(--ui-shadow-card);
}

@media (min-width: 961px) {
    .calc-sheet__receipt {
        position: absolute;
        inset: 0;
        width: 100%;
        max-height: 100%;
        z-index: 40;
        overflow: hidden;
    }
}

.calc-receipt__panel {
    display: flex;
    flex-direction: column;
    gap: 0;
    max-height: inherit;
}

@media (min-width: 961px) {
    .calc-receipt__panel {
        max-height: 100%;
    }
}

.calc-receipt__head {
    flex-shrink: 0;
    margin-bottom: 1.25rem;
}

.calc-receipt__scroll {
    flex: 1 1 auto;
    min-height: 0;
    margin-bottom: 1rem;
}

@media (min-width: 961px) {
    .calc-receipt__scroll {
        overflow-y: auto;
        padding-right: 0.15rem;
    }
}

@media (min-width: 1100px) {
    .calc-sheet__receipt {
        padding: 2.25rem 2.75rem;
    }
}

@media (max-width: 960px) {
    .calc-sheet__receipt {
        border-top: none;
        border-radius: 0 0 var(--primitive-radius-lg) var(--primitive-radius-lg);
        margin-top: -1px;
    }
}

.calc-receipt__total {
    margin-bottom: 0;
}

.calc-receipt__total--pulse {
    animation: calc-price-pulse 0.45s ease;
}

.calc-receipt__kicker {
    display: block;
    font-size: 0.8125rem;
    color: var(--ui-text-secondary);
    margin-bottom: 0.35rem;
}

.calc-receipt__price-line {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem 0.75rem;
    margin-bottom: 0.35rem;
}

.calc-receipt__price {
    font-size: clamp(2rem, 3.5vw, 2.75rem);
    font-weight: 800;
    letter-spacing: -0.035em;
    font-variant-numeric: tabular-nums;
    line-height: 1.05;
}

.calc-receipt__badge {
    margin: 0;
}

.calc-receipt__meta {
    font-size: 0.75rem;
    color: var(--ui-text-muted);
}

.calc-receipt__lines {
    margin: 0 0 1rem;
    padding: 0;
}

.calc-receipt__line {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: var(--primitive-gap-lg);
    padding: 0.55rem 0;
    border-bottom: 1px solid var(--ui-border);
    font-size: 0.8125rem;
}

.calc-receipt__line--sep {
    margin-top: 0.25rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--ui-border);
    border-bottom: none;
    font-weight: 600;
}

.calc-receipt__line dt {
    margin: 0;
    color: var(--ui-text-secondary);
    font-weight: 500;
}

.calc-receipt__line dd {
    margin: 0;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    text-align: right;
}

.calc-receipt__pct {
    font-weight: 500;
    color: var(--ui-text-muted);
}

.calc-receipt__note {
    margin: 0 0 1rem;
    padding: 0.75rem;
    font-size: 0.75rem;
    line-height: 1.5;
    color: var(--ui-text-secondary);
    background: var(--ui-surface);
    border-radius: var(--primitive-radius-sm);
    border: 1px solid var(--ui-border);
}

.calc-receipt__details {
    margin-bottom: 1rem;
    overflow-x: auto;
}

.calc-receipt__cta {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 100%;
    margin-top: 0;
    text-align: center;
    text-decoration: none;
    box-sizing: border-box;
}

.calc-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.75rem;
}

.calc-table th,
.calc-table td {
    padding: 0.45rem 0.35rem;
    text-align: left;
    border-bottom: 1px solid var(--ui-border);
}

.calc-table th {
    color: var(--ui-text-secondary);
    font-weight: 500;
}

.calc-table td:not(:first-child) {
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

@keyframes calc-price-pulse {
    0% { transform: scale(1); }
    45% { transform: scale(1.012); }
    100% { transform: scale(1); }
}

@media (prefers-reduced-motion: reduce) {
    .calc-receipt__total--pulse {
        animation: none;
    }
}
</style>
