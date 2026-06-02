<script setup lang="ts">
import FunnelStageSimPreview, { type StagePreviewPayload } from '@/Components/Ai/FunnelStageSimPreview.vue';
import { useI18n } from '@/composables/useI18n';
import { computed } from 'vue';

export type SimulationResult = {
    customer_reply: string;
    funnel_name: string | null;
    stage_name: string | null;
    confidence: number;
    actions: string[];
    manager_needed: boolean;
    reason: string;
    risks: string[];
    missing_data: string[];
    context?: {
        chat_id?: number;
        chat_name?: string | null;
        current_funnel?: string | null;
        current_stage?: string | null;
    };
    stage_preview?: StagePreviewPayload | null;
};

const { t } = useI18n();

const props = defineProps<{
    result: SimulationResult;
}>();

const confidencePercent = computed(() => Math.round((props.result.confidence ?? 0) * 100));
</script>

<template>
    <div class="space-y-4">
        <FunnelStageSimPreview
            v-if="result.stage_preview?.stages?.length"
            :preview="result.stage_preview"
            :target-stage-name="result.stage_name"
        />

        <div
            v-if="result.context?.current_funnel || result.context?.current_stage"
            class="rounded-lg border px-3 py-2 text-xs"
            :style="{ borderColor: 'var(--wa-border)', color: 'var(--wa-text-secondary)' }"
        >
            <span v-if="result.context?.current_funnel">{{ t('misc.components.aiSimulation.chatFunnel', { name: result.context.current_funnel }) }}</span>
            <span v-if="result.context?.current_stage" class="ml-2">{{ t('misc.components.aiSimulation.chatStage', { name: result.context.current_stage }) }}</span>
        </div>

        <div>
            <div class="mb-1 text-xs font-semibold uppercase tracking-wide" :style="{ color: 'var(--wa-accent)' }">
                {{ t('misc.components.aiSimulation.clientReply') }}
            </div>
            <div
                class="rounded-lg px-3 py-2 text-sm leading-relaxed whitespace-pre-wrap"
                :style="{ background: 'var(--wa-panel)', color: 'var(--wa-text)' }"
            >
                {{ result.customer_reply }}
            </div>
        </div>

        <div class="grid gap-2 text-xs sm:grid-cols-2" :style="{ color: 'var(--wa-text-secondary)' }">
            <div><span :style="{ color: 'var(--wa-text)' }">{{ t('misc.components.aiSimulation.funnel') }}</span> {{ result.funnel_name || '—' }}</div>
            <div><span :style="{ color: 'var(--wa-text)' }">{{ t('misc.components.aiSimulation.stage') }}</span> {{ result.stage_name || '—' }}</div>
            <div><span :style="{ color: 'var(--wa-text)' }">{{ t('misc.components.aiSimulation.confidence') }}</span> {{ confidencePercent }}%</div>
            <div><span :style="{ color: 'var(--wa-text)' }">{{ t('misc.components.aiSimulation.manager') }}</span> {{ result.manager_needed ? t('misc.components.aiSimulation.managerNeeded') : t('misc.components.aiSimulation.managerNotNeeded') }}</div>
        </div>

        <div>
            <div class="mb-1 text-xs font-semibold" :style="{ color: 'var(--wa-text)' }">{{ t('misc.components.aiSimulation.whyTitle') }}</div>
            <p class="text-sm leading-relaxed" :style="{ color: 'var(--wa-text-secondary)' }">{{ result.reason }}</p>
        </div>

        <div v-if="result.actions.length" class="flex flex-wrap gap-2">
            <span
                v-for="action in result.actions"
                :key="action"
                class="rounded-full px-2.5 py-1 text-xs"
                :style="{ background: 'color-mix(in srgb, var(--wa-accent) 12%, transparent)', color: 'var(--wa-text)' }"
            >
                {{ action }}
            </span>
        </div>

        <div v-if="result.missing_data.length || result.risks.length" class="grid gap-3 md:grid-cols-2">
            <div v-if="result.missing_data.length">
                <div class="mb-1 text-xs font-semibold" :style="{ color: 'var(--wa-text)' }">{{ t('misc.components.aiSimulation.missingDataTitle') }}</div>
                <ul class="space-y-1 text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                    <li v-for="item in result.missing_data" :key="item">• {{ item }}</li>
                </ul>
            </div>
            <div v-if="result.risks.length">
                <div class="mb-1 text-xs font-semibold" :style="{ color: 'var(--wa-text)' }">{{ t('misc.components.aiSimulation.risksTitle') }}</div>
                <ul class="space-y-1 text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                    <li v-for="item in result.risks" :key="item">• {{ item }}</li>
                </ul>
            </div>
        </div>
    </div>
</template>
