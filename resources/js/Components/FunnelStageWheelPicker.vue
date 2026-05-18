<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import FunnelStageIcon from '@/Components/Funnel/FunnelStageIcon.vue';

export type FunnelWheelStage = {
    id: number;
    name: string;
    color: string;
    stage_type?: string | null;
    position: number;
};

const props = withDefaults(
    defineProps<{
        stages: FunnelWheelStage[];
        modelValue: number | null;
        accentColor?: string;
    }>(),
    {
        accentColor: 'var(--wa-accent)',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: number | null];
}>();

const ITEM_H = 48;
const VISIBLE = 3;

const viewportRef = ref<HTMLElement | null>(null);
const paddingY = ((VISIBLE - 1) / 2) * ITEM_H;
const wheelHeight = VISIBLE * ITEM_H;

let snapTimer: ReturnType<typeof setTimeout> | null = null;
let rafId: number | null = null;
let syncing = false;

const selectedIndex = computed(() => {
    if (props.modelValue == null) {
        return -1;
    }
    return props.stages.findIndex((s) => s.id === props.modelValue);
});

function clampIndex(index: number): number {
    if (props.stages.length === 0) {
        return 0;
    }
    return Math.max(0, Math.min(props.stages.length - 1, index));
}

function indexFromScroll(scrollTop: number): number {
    return clampIndex(Math.round(scrollTop / ITEM_H));
}

function updateItemVisuals(): void {
    const viewport = viewportRef.value;
    if (!viewport) {
        return;
    }

    const centerY = viewport.scrollTop + viewport.clientHeight / 2;

    const items = viewport.querySelectorAll<HTMLElement>('.funnel-wheel__item');
    items.forEach((el, index) => {
        const itemCenter = paddingY + index * ITEM_H + ITEM_H / 2;
        const dist = Math.abs(itemCenter - centerY);
        const t = Math.min(1, dist / (ITEM_H * 1.35));
        const opacity = 1 - t * 0.62;
        const scale = 1 - t * 0.12;
        const tilt = ((itemCenter - centerY) / ITEM_H) * -14;
        el.style.opacity = String(opacity);
        el.style.transform = `scale(${scale}) rotateX(${tilt}deg)`;
    });
}

function scrollToIndex(index: number, smooth = false): void {
    const viewport = viewportRef.value;
    if (!viewport || props.stages.length === 0) {
        return;
    }

    syncing = true;
    viewport.scrollTo({
        top: clampIndex(index) * ITEM_H,
        behavior: smooth ? 'smooth' : 'auto',
    });

    window.setTimeout(() => {
        syncing = false;
        updateItemVisuals();
    }, smooth ? 280 : 0);
}

function selectIndex(index: number, smooth = true): void {
    const idx = clampIndex(index);
    const stage = props.stages[idx];
    if (!stage) {
        return;
    }

    const viewport = viewportRef.value;
    const atIndex = viewport ? indexFromScroll(viewport.scrollTop) === idx : false;
    if (!atIndex) {
        scrollToIndex(idx, smooth);
    } else {
        updateItemVisuals();
    }

    if (stage.id !== props.modelValue) {
        emit('update:modelValue', stage.id);
    }
}

function onScroll(): void {
    if (rafId != null) {
        cancelAnimationFrame(rafId);
    }
    rafId = requestAnimationFrame(() => {
        rafId = null;
        updateItemVisuals();
    });

    if (syncing) {
        return;
    }

    if (snapTimer != null) {
        clearTimeout(snapTimer);
    }
    snapTimer = setTimeout(() => {
        snapTimer = null;
        const viewport = viewportRef.value;
        if (!viewport) {
            return;
        }
        const idx = indexFromScroll(viewport.scrollTop);
        selectIndex(idx, true);
    }, 90);
}

function onItemClick(index: number): void {
    selectIndex(index, true);
}

function syncFromModel(): void {
    const idx = selectedIndex.value >= 0 ? selectedIndex.value : 0;
    void nextTick(() => scrollToIndex(idx, false));
}

watch(
    () => props.modelValue,
    () => {
        if (syncing) {
            return;
        }
        const idx = selectedIndex.value;
        if (idx < 0) {
            return;
        }
        const viewport = viewportRef.value;
        if (!viewport) {
            return;
        }
        const current = indexFromScroll(viewport.scrollTop);
        if (current !== idx) {
            scrollToIndex(idx, true);
        }
    },
);

watch(
    () => props.stages,
    () => {
        syncFromModel();
    },
    { deep: true },
);

onMounted(() => {
    syncFromModel();
});

onBeforeUnmount(() => {
    if (snapTimer != null) {
        clearTimeout(snapTimer);
    }
    if (rafId != null) {
        cancelAnimationFrame(rafId);
    }
});

defineExpose({
    scrollToStage(id: number | null): void {
        const idx = id == null ? 0 : props.stages.findIndex((s) => s.id === id);
        scrollToIndex(idx >= 0 ? idx : 0, true);
    },
    refresh(): void {
        syncFromModel();
    },
});
</script>

<template>
    <div class="funnel-wheel" :style="{ '--funnel-wheel-accent': accentColor }">
        <div class="funnel-wheel__frame" :style="{ height: `${wheelHeight}px` }">
            <div class="funnel-wheel__fade funnel-wheel__fade--top" aria-hidden="true" />
            <div class="funnel-wheel__fade funnel-wheel__fade--bottom" aria-hidden="true" />
            <div class="funnel-wheel__window" aria-hidden="true" />

            <div
                ref="viewportRef"
                class="funnel-wheel__viewport wa-scrollbar"
                @scroll.passive="onScroll"
            >
                <div class="funnel-wheel__pad" :style="{ height: `${paddingY}px` }" />
                <button
                    v-for="(stage, index) in stages"
                    :key="stage.id"
                    type="button"
                    class="funnel-wheel__item"
                    :class="{ 'funnel-wheel__item--active': stage.id === modelValue }"
                    :style="{ height: `${ITEM_H}px` }"
                    @click="onItemClick(index)"
                >
                    <span
                        class="funnel-wheel__dot flex items-center justify-center"
                        :style="{ backgroundColor: `${stage.color || accentColor}22`, color: stage.color || accentColor }"
                        aria-hidden="true"
                    >
                        <FunnelStageIcon :type="stage.stage_type" :size="18" />
                    </span>
                    <span class="funnel-wheel__label">{{ stage.name }}</span>
                    <span class="funnel-wheel__index">{{ index + 1 }}</span>
                </button>
                <div class="funnel-wheel__pad" :style="{ height: `${paddingY}px` }" />
            </div>
        </div>
    </div>
</template>


<style scoped>
.funnel-wheel {
    --funnel-wheel-accent: var(--wa-accent);
}

.funnel-wheel__frame {
    position: relative;
    overflow: hidden;
    border-radius: 1rem;
    border: 1px solid var(--wa-border);
    background: var(--wa-panel-header);
}

.funnel-wheel__viewport {
    position: relative;
    z-index: 2;
    height: 100%;
    overflow-y: auto;
    overflow-x: hidden;
    scroll-snap-type: y mandatory;
    scrollbar-width: none;
    perspective: 560px;
    perspective-origin: center center;
}

.funnel-wheel__viewport::-webkit-scrollbar {
    display: none;
}

.funnel-wheel__pad {
    flex-shrink: 0;
}

.funnel-wheel__window {
    pointer-events: none;
    position: absolute;
    left: 0.5rem;
    right: 0.5rem;
    top: 50%;
    z-index: 1;
    height: 48px;
    transform: translateY(-50%);
    border-radius: 0.75rem;
    border: 1px solid color-mix(in srgb, var(--funnel-wheel-accent) 55%, var(--wa-border));
    background: color-mix(in srgb, var(--funnel-wheel-accent) 10%, var(--wa-panel));
    box-shadow:
        0 0 0 1px color-mix(in srgb, var(--funnel-wheel-accent) 12%, transparent),
        inset 0 1px 0 color-mix(in srgb, #fff 8%, transparent);
}

.funnel-wheel__fade {
    pointer-events: none;
    position: absolute;
    left: 0;
    right: 0;
    z-index: 3;
    height: 32%;
}

.funnel-wheel__fade--top {
    top: 0;
    background: linear-gradient(
        to bottom,
        var(--wa-panel-header) 0%,
        color-mix(in srgb, var(--wa-panel-header) 88%, transparent) 55%,
        transparent 100%
    );
}

.funnel-wheel__fade--bottom {
    bottom: 0;
    background: linear-gradient(
        to top,
        var(--wa-panel-header) 0%,
        color-mix(in srgb, var(--wa-panel-header) 88%, transparent) 55%,
        transparent 100%
    );
}

.funnel-wheel__item {
    position: relative;
    z-index: 2;
    display: flex;
    width: 100%;
    align-items: center;
    gap: 0.625rem;
    padding: 0 1rem 0 1.25rem;
    border: 0;
    background: transparent;
    color: var(--wa-text);
    cursor: pointer;
    scroll-snap-align: center;
    transform-origin: center center;
    transform-style: preserve-3d;
    transition:
        opacity 0.12s ease,
        transform 0.12s ease;
    will-change: transform, opacity;
}

.funnel-wheel__item--active .funnel-wheel__label {
    font-weight: 600;
    color: var(--wa-text);
}

.funnel-wheel__item--active .funnel-wheel__index {
    color: var(--wa-text);
    opacity: 0.9;
}

.funnel-wheel__dot {
    width: 0.5rem;
    height: 0.5rem;
    flex-shrink: 0;
    border-radius: 9999px;
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--wa-panel-header) 80%, transparent);
}

.funnel-wheel__label {
    min-width: 0;
    flex: 1;
    text-align: left;
    font-size: 0.8125rem;
    line-height: 1.25;
    color: var(--wa-text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.funnel-wheel__index {
    flex-shrink: 0;
    font-size: 0.6875rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    color: var(--wa-text-secondary);
    opacity: 0.65;
}
</style>
