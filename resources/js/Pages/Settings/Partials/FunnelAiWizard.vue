<script setup lang="ts">
import { computed, ref } from 'vue';
import axios from 'axios';

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

const INDUSTRY_PRESETS = [
    'Ритейл и e-commerce',
    'Услуги для частных клиентов',
    'B2B SaaS / IT',
    'Производство',
    'Строительство и ремонт',
    'Образование',
    'Медицина и красота',
    'Логистика',
];

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
        b2c: 'B2C — частные клиенты',
        b2b: 'B2B — бизнес-клиенты',
        mixed: 'Смешанный (B2C и B2B)',
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
                return 'Укажите тип аудитории или опишите её подробнее (минимум 10 символов).';
            }
            return null;
        case 1:
            if (form.value.industry.trim().length < 3) {
                return 'Укажите сферу деятельности.';
            }
            return null;
        case 2:
            if (form.value.business_description.trim().length < 10) {
                return 'Опишите бизнес подробнее — минимум 10 символов.';
            }
            return null;
        case 3:
            if (form.value.clients_description.trim().length < 10) {
                return 'Опишите клиентов подробнее — минимум 10 символов.';
            }
            return null;
        case 4:
            if (form.value.products_description.trim().length < 10) {
                return 'Опишите товары или услуги — минимум 10 символов.';
            }
            return null;
        case 5:
            if (form.value.sales_process.trim().length < 10) {
                return 'Опишите процесс продаж — минимум 10 символов.';
            }
            return null;
        default:
            return null;
    }
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
            error.value = 'AI вернул пустой результат. Попробуйте уточнить ответы.';
            return;
        }

        suggestions.value = items.map((item: AiFunnelSuggestion) => ({
            name: String(item.name ?? ''),
            description: String(item.description ?? ''),
            color: String(item.color ?? '#25d366'),
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
        error.value = e.response?.data?.message || 'Не удалось сгенерировать варианты. Попробуйте ещё раз.';
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
    <div class="space-y-4">
        <div v-if="!isVariantsStep" class="space-y-2">
            <div class="flex items-center justify-between text-xs text-[var(--wa-text-secondary)]">
                <span>Шаг {{ inputStepNumber }} из {{ TOTAL_INPUT_STEPS }}</span>
                <span>{{ progressPercent }}%</span>
            </div>
            <div
                class="h-1.5 rounded-full overflow-hidden"
                :style="{ background: 'var(--wa-border-strong)' }"
            >
                <div
                    class="h-full rounded-full transition-all duration-300"
                    :style="{ width: `${progressPercent}%`, background: 'var(--wa-accent)' }"
                />
            </div>
        </div>

        <div v-if="wizardStep === 0" class="space-y-3">
            <div>
                <label class="block text-sm text-[var(--wa-text-secondary)] mb-2">На кого направлен бизнес?</label>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="type in (['b2c', 'b2b', 'mixed'] as const)"
                        :key="type"
                        type="button"
                        class="px-3 py-1.5 text-xs rounded-lg border transition"
                        :style="form.target_audience_type === type
                            ? { background: 'var(--wa-accent)', color: '#fff', borderColor: 'var(--wa-accent)' }
                            : { color: 'var(--wa-text-secondary)', borderColor: 'var(--wa-border-strong)' }"
                        @click="selectAudienceType(type)"
                    >
                        {{ type === 'b2c' ? 'B2C' : type === 'b2b' ? 'B2B' : 'Смешанный' }}
                    </button>
                </div>
            </div>
            <div>
                <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Уточнение (необязательно)</label>
                <textarea
                    v-model="form.target_audience"
                    class="settings-input min-h-[80px]"
                    rows="3"
                    placeholder="Например: семьи со средним доходом, владельцы квартир 30–55 лет"
                />
            </div>
        </div>

        <div v-else-if="wizardStep === 1" class="space-y-3">
            <div>
                <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Сфера деятельности</label>
                <input
                    v-model="form.industry"
                    type="text"
                    class="settings-input"
                    placeholder="Например: установка пластиковых окон"
                />
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button
                    v-for="preset in INDUSTRY_PRESETS"
                    :key="preset"
                    type="button"
                    class="px-2.5 py-1 text-[11px] rounded-md border transition hover:brightness-95"
                    :style="{ color: 'var(--wa-text-secondary)', borderColor: 'var(--wa-border-strong)' }"
                    @click="applyIndustryPreset(preset)"
                >
                    {{ preset }}
                </button>
            </div>
        </div>

        <div v-else-if="wizardStep === 2">
            <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Опишите свой бизнес</label>
            <textarea
                v-model="form.business_description"
                class="settings-input min-h-[120px]"
                rows="5"
                placeholder="Чем занимаетесь, в чём отличие от конкурентов, география работы"
            />
        </div>

        <div v-else-if="wizardStep === 3">
            <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Кто ваши клиенты?</label>
            <textarea
                v-model="form.clients_description"
                class="settings-input min-h-[100px]"
                rows="4"
                placeholder="Портрет клиента, типичные запросы, откуда приходят"
            />
        </div>

        <div v-else-if="wizardStep === 4">
            <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Товары и услуги</label>
            <textarea
                v-model="form.products_description"
                class="settings-input min-h-[100px]"
                rows="4"
                placeholder="Что продаёте, средний чек, пакеты и варианты"
            />
        </div>

        <div v-else-if="wizardStep === 5">
            <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Как проходят продажи?</label>
            <textarea
                v-model="form.sales_process"
                class="settings-input min-h-[120px]"
                rows="5"
                placeholder="От первого контакта до оплаты: каналы, этапы, средняя длительность сделки"
            />
        </div>

        <div v-else-if="isVariantsStep" class="space-y-3">
            <div class="text-sm text-[var(--wa-text-secondary)]">
                AI предложил {{ suggestions.length }} вариант(а). Выберите подходящий — его можно отредактировать перед сохранением.
            </div>

            <div
                v-for="(suggestion, idx) in suggestions"
                :key="idx"
                class="rounded-lg border p-4 space-y-3 transition hover:brightness-[1.02]"
                :style="{ background: 'var(--wa-bg)', borderColor: 'var(--wa-border-strong)' }"
            >
                <div class="flex items-start gap-3">
                    <span
                        class="w-3 h-3 rounded-full shrink-0 mt-1"
                        :style="{ background: suggestion.color }"
                    />
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium text-[var(--wa-text)]">{{ suggestion.name }}</div>
                        <div v-if="suggestion.rationale" class="text-xs text-[var(--wa-accent)] mt-1">
                            {{ suggestion.rationale }}
                        </div>
                        <div v-if="suggestion.description" class="text-xs text-[var(--wa-text-secondary)] mt-1">
                            {{ suggestion.description }}
                        </div>
                    </div>
                </div>

                <ol class="flex flex-wrap gap-1.5">
                    <li
                        v-for="(stage, sIdx) in suggestion.stages"
                        :key="sIdx"
                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] border"
                        :style="{ borderColor: 'var(--wa-border)', color: 'var(--wa-text-secondary)' }"
                    >
                        <span class="w-2 h-2 rounded-full shrink-0" :style="{ background: stage.color }" />
                        {{ stage.name }}
                    </li>
                </ol>

                <button
                    type="button"
                    class="w-full px-3 py-2 text-xs rounded-lg transition hover:brightness-95"
                    :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                    @click="pickSuggestion(suggestion)"
                >
                    Выбрать этот вариант
                </button>
            </div>
        </div>

        <div v-if="error" class="text-xs text-red-400 whitespace-pre-line">
            {{ error }}
        </div>

        <div v-if="!isVariantsStep" class="flex items-center justify-between gap-2 pt-1">
            <button
                type="button"
                class="px-3 py-1.5 text-xs rounded-md text-[var(--wa-text-secondary)] hover:bg-[var(--wa-panel-hover)] disabled:opacity-40"
                :disabled="wizardStep === 0 || generating"
                @click="goBack"
            >
                Назад
            </button>
            <button
                type="button"
                class="px-4 py-1.5 text-xs rounded-md transition hover:brightness-95 disabled:opacity-50 flex items-center gap-1.5"
                :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                :disabled="generating"
                @click="goNext"
            >
                <svg
                    v-if="generating"
                    class="w-3.5 h-3.5 animate-spin"
                    fill="none"
                    viewBox="0 0 24 24"
                >
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                </svg>
                <template v-if="generating">Генерация…</template>
                <template v-else-if="wizardStep === 5">Сгенерировать варианты</template>
                <template v-else>Далее</template>
            </button>
        </div>

        <div v-else class="flex justify-end pt-1">
            <button
                type="button"
                class="px-3 py-1.5 text-xs rounded-md border transition hover:brightness-95 disabled:opacity-50"
                :style="{ color: 'var(--wa-text-secondary)', borderColor: 'var(--wa-border-strong)' }"
                :disabled="generating"
                @click="generateVariants"
            >
                Сгенерировать заново
            </button>
        </div>
    </div>
</template>

<style scoped>
.settings-input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border-radius: 0.5rem;
    font-size: 0.875rem;
    background: var(--wa-bg);
    color: var(--wa-text);
    border: 1px solid var(--wa-border-strong);
    transition: border-color 0.15s ease;
}
.settings-input:focus {
    outline: none;
    border-color: var(--wa-accent);
}
</style>