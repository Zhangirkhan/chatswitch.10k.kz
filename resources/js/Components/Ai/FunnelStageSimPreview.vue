<script setup lang="ts">
import FunnelStageIcon from '@/Components/Funnel/FunnelStageIcon.vue';
import { useI18n } from '@/composables/useI18n';
import { computed, ref, watch } from 'vue';

const { t } = useI18n();

export type StagePreviewStage = {
    id: number;
    name: string;
    color: string | null;
    stage_type: string | null;
};

export type StagePreviewPayload = {
    stages: StagePreviewStage[];
    from_index: number;
    to_index: number | null;
    funnel_color: string | null;
};

const props = defineProps<{
    preview: StagePreviewPayload | null | undefined;
    targetStageName?: string | null;
}>();

const highlightIndex = ref(-1);
const animating = ref(false);

const stages = computed(() => props.preview?.stages ?? []);
const fromIndex = computed(() => props.preview?.from_index ?? -1);
const toIndex = computed(() => props.preview?.to_index ?? -1);
const barColor = computed(() => props.preview?.funnel_color || 'var(--wa-accent)');
const hasBar = computed(() => stages.value.length > 0);

const caption = computed(() => {
    if (!hasBar.value) {
        return null;
    }
    const from = fromIndex.value >= 0 ? stages.value[fromIndex.value]?.name : null;
    const to =
        toIndex.value >= 0
            ? stages.value[toIndex.value]?.name
            : (props.targetStageName?.trim() || null);
    if (from && to && from !== to) {
        return `${from} → ${to}`;
    }
    if (to) {
        return t('misc.components.funnelStageSim.targetStage', { name: to });
    }
    if (from) {
        return t('misc.components.funnelStageSim.currentStage', { name: from });
    }
    return null;
});

watch(
    () => [props.preview?.from_index, props.preview?.to_index, stages.value.length] as const,
    () => {
        highlightIndex.value = fromIndex.value >= 0 ? fromIndex.value : -1;
        animating.value = false;
        const target = toIndex.value;
        if (target < 0 || target === highlightIndex.value || stages.value.length === 0) {
            return;
        }
        animating.value = true;
        window.setTimeout(() => {
            highlightIndex.value = target;
            window.setTimeout(() => {
                animating.value = false;
            }, 480);
        }, 120);
    },
    { immediate: true },
);

function cellStyle(index: number): Record<string, string> {
    const active = highlightIndex.value >= 0 && index <= highlightIndex.value;
    if (active) {
        const stage = stages.value[index];
        const color = stage?.color || barColor.value;
        return {
            backgroundColor: color,
            transform: animating.value && index === highlightIndex.value ? 'scaleY(1.35)' : 'scaleY(1)',
            transition: 'background-color 0.45s ease, transform 0.35s ease',
        };
    }
    return {
        backgroundColor: 'color-mix(in srgb, var(--wa-text-secondary) 22%, transparent)',
        transition: 'background-color 0.45s ease, transform 0.35s ease',
    };
}
</script>

<template>
    <div v-if="hasBar" class="space-y-2">
        <p
            v-if="caption"
            class="text-xs m-0"
            :style="{ color: 'var(--wa-text-secondary)' }"
        >
            {{ caption }}
        </p>
        <div class="flex gap-0.5 h-2 rounded-full overflow-hidden" role="presentation" aria-hidden="true">
            <div
                v-for="(stage, index) in stages"
                :key="stage.id"
                class="min-w-[6px] flex-1 rounded-sm origin-center"
                :title="stage.name"
                :style="cellStyle(index)"
            />
        </div>
        <ul class="flex flex-wrap gap-1.5 text-[10px]" :style="{ color: 'var(--wa-text-secondary)' }">
            <li
                v-for="(stage, index) in stages"
                :key="`label-${stage.id}`"
                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 transition-colors duration-300"
                :style="
                    index === highlightIndex
                        ? { background: 'var(--wa-accent-soft)', color: 'var(--wa-accent)' }
                        : { background: 'transparent' }
                "
            >
                <FunnelStageIcon :type="stage.stage_type" :size="10" />
                <span class="truncate max-w-[8rem]">{{ stage.name }}</span>
            </li>
        </ul>
    </div>
</template>
