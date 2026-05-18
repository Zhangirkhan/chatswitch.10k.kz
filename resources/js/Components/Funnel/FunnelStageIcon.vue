<script setup lang="ts">
import { computed } from 'vue';
import { normalizeStageType, type FunnelStageTypeValue } from '@/utils/funnelStageTypes';

const props = withDefaults(
    defineProps<{
        type?: string | null;
        size?: number;
        title?: string;
    }>(),
    {
        type: 'other',
        size: 16,
    },
);

const stageType = computed((): FunnelStageTypeValue => normalizeStageType(props.type));
const boxStyle = computed(() => ({
    width: `${props.size}px`,
    height: `${props.size}px`,
}));
</script>

<template>
    <svg
        class="shrink-0"
        :style="boxStyle"
        :width="size"
        :height="size"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
        :aria-hidden="title ? undefined : true"
        :aria-label="title"
        role="img"
    >
        <template v-if="stageType === 'lead'">
            <path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2" />
            <circle cx="9" cy="7" r="4" />
            <path d="M19 8v6M22 11h-6" />
        </template>
        <template v-else-if="stageType === 'qualification'">
            <path d="M9 11l3 3L22 4" />
            <path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11" />
        </template>
        <template v-else-if="stageType === 'offer'">
            <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" />
            <path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" />
        </template>
        <template v-else-if="stageType === 'payment'">
            <rect x="2" y="5" width="20" height="14" rx="2" />
            <path d="M2 10h20" />
        </template>
        <template v-else-if="stageType === 'production'">
            <path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z" />
        </template>
        <template v-else-if="stageType === 'delivery'">
            <path d="M1 3h15v13H1zM16 8h4l3 3v5h-7V8z" />
            <circle cx="5.5" cy="18.5" r="2.5" />
            <circle cx="18.5" cy="18.5" r="2.5" />
        </template>
        <template v-else-if="stageType === 'done'">
            <path d="M22 11.08V12a10 10 0 11-5.93-9.14" />
            <path d="M22 4L12 14.01l-3-3" />
        </template>
        <template v-else>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 8v4M12 16h.01" />
        </template>
    </svg>
</template>
