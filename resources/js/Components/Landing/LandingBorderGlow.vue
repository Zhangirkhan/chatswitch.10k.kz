<script setup lang="ts">
/**
 * Border glow card wrapper — adapted from Vue Bits (MIT)
 * https://vue-bits.dev/components/border-glow
 */
import { computed, onMounted, ref, useTemplateRef, watch } from 'vue';

interface BorderGlowProps {
    className?: string;
    edgeSensitivity?: number;
    glowColor?: string;
    backgroundColor?: string;
    borderRadius?: number;
    glowRadius?: number;
    glowIntensity?: number;
    coneSpread?: number;
    animated?: boolean;
    colors?: string[];
    fillOpacity?: number;
    enabled?: boolean;
}

function parseHSL(hslStr: string): { h: number; s: number; l: number } {
    const match = hslStr.match(/([\d.]+)\s*([\d.]+)%?\s*([\d.]+)%?/);
    if (!match) return { h: 40, s: 80, l: 80 };
    return { h: parseFloat(match[1]), s: parseFloat(match[2]), l: parseFloat(match[3]) };
}

function buildBoxShadow(glowColor: string, intensity: number): string {
    const { h, s, l } = parseHSL(glowColor);
    const base = `${h}deg ${s}% ${l}%`;
    const layers: [number, number, number, number, number, boolean][] = [
        [0, 0, 0, 1, 100, true],
        [0, 0, 1, 0, 60, true],
        [0, 0, 3, 0, 50, true],
        [0, 0, 6, 0, 40, true],
        [0, 0, 15, 0, 30, true],
        [0, 0, 25, 2, 20, true],
        [0, 0, 50, 2, 10, true],
        [0, 0, 1, 0, 60, false],
        [0, 0, 3, 0, 50, false],
        [0, 0, 6, 0, 40, false],
        [0, 0, 15, 0, 30, false],
        [0, 0, 25, 2, 20, false],
        [0, 0, 50, 2, 10, false],
    ];
    return layers
        .map(([x, y, blur, spread, alpha, inset]) => {
            const a = Math.min(alpha * intensity, 100);
            return `${inset ? 'inset ' : ''}${x}px ${y}px ${blur}px ${spread}px hsl(${base} / ${a}%)`;
        })
        .join(', ');
}

function easeOutCubic(x: number): number {
    return 1 - Math.pow(1 - x, 3);
}

function easeInCubic(x: number): number {
    return x * x * x;
}

interface AnimateOpts {
    start?: number;
    end?: number;
    duration?: number;
    delay?: number;
    ease?: (t: number) => number;
    onUpdate: (v: number) => void;
    onEnd?: () => void;
}

function animateValue({
    start = 0,
    end = 100,
    duration = 1000,
    delay = 0,
    ease = easeOutCubic,
    onUpdate,
    onEnd,
}: AnimateOpts): void {
    const t0 = performance.now() + delay;
    function tick(): void {
        const elapsed = performance.now() - t0;
        const t = Math.min(elapsed / duration, 1);
        onUpdate(start + (end - start) * ease(t));
        if (t < 1) requestAnimationFrame(tick);
        else if (onEnd) onEnd();
    }
    setTimeout(() => requestAnimationFrame(tick), delay);
}

const GRADIENT_POSITIONS = ['80% 55%', '69% 34%', '8% 6%', '41% 38%', '86% 85%', '82% 18%', '51% 4%'];
const COLOR_MAP = [0, 1, 2, 0, 1, 2, 1];

function buildMeshGradients(colors: string[]): string[] {
    const gradients: string[] = [];
    for (let i = 0; i < 7; i++) {
        const c = colors[Math.min(COLOR_MAP[i], colors.length - 1)];
        gradients.push(`radial-gradient(at ${GRADIENT_POSITIONS[i]}, ${c} 0px, transparent 50%)`);
    }
    gradients.push(`linear-gradient(${colors[0]} 0 100%)`);
    return gradients;
}

const props = withDefaults(defineProps<BorderGlowProps>(), {
    className: '',
    edgeSensitivity: 30,
    glowColor: '40 80 80',
    backgroundColor: '#060010',
    borderRadius: 16,
    glowRadius: 40,
    glowIntensity: 1.0,
    coneSpread: 25,
    animated: false,
    colors: () => ['#c084fc', '#f472b6', '#38bdf8'],
    fillOpacity: 0.5,
    enabled: true,
});

const cardRef = useTemplateRef<HTMLDivElement>('cardRef');
const isHovered = ref(false);
const cursorAngle = ref(45);
const edgeProximity = ref(0);
const sweepActive = ref(false);
const prefersReducedMotion = ref(false);
const hoverCapable = ref(false);

const glowActive = computed(() => props.enabled && hoverCapable.value && !prefersReducedMotion.value);

const getCenterOfElement = (el: HTMLElement): [number, number] => {
    const { width, height } = el.getBoundingClientRect();
    return [width / 2, height / 2];
};

const getEdgeProximity = (el: HTMLElement, x: number, y: number): number => {
    const [cx, cy] = getCenterOfElement(el);
    const dx = x - cx;
    const dy = y - cy;
    let kx = Infinity;
    let ky = Infinity;
    if (dx !== 0) kx = cx / Math.abs(dx);
    if (dy !== 0) ky = cy / Math.abs(dy);
    return Math.min(Math.max(1 / Math.min(kx, ky), 0), 1);
};

const getCursorAngle = (el: HTMLElement, x: number, y: number): number => {
    const [cx, cy] = getCenterOfElement(el);
    const dx = x - cx;
    const dy = y - cy;
    if (dx === 0 && dy === 0) return 0;
    const radians = Math.atan2(dy, dx);
    let degrees = radians * (180 / Math.PI) + 90;
    if (degrees < 0) degrees += 360;
    return degrees;
};

const handlePointerMove = (e: PointerEvent): void => {
    const card = cardRef.value;
    if (!card || !glowActive.value) return;
    const rect = card.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;
    edgeProximity.value = getEdgeProximity(card, x, y);
    cursorAngle.value = getCursorAngle(card, x, y);
};

watch(
    () => [props.animated, glowActive.value],
    () => {
        if (!props.animated || !glowActive.value) return;
        const angleStart = 110;
        const angleEnd = 465;
        sweepActive.value = true;
        cursorAngle.value = angleStart;

        animateValue({ duration: 500, onUpdate: (v) => (edgeProximity.value = v / 100) });
        animateValue({
            ease: easeInCubic,
            duration: 1500,
            end: 50,
            onUpdate: (v) => {
                cursorAngle.value = (angleEnd - angleStart) * (v / 100) + angleStart;
            },
        });
        animateValue({
            ease: easeOutCubic,
            delay: 1500,
            duration: 2250,
            start: 50,
            end: 100,
            onUpdate: (v) => {
                cursorAngle.value = (angleEnd - angleStart) * (v / 100) + angleStart;
            },
        });
        animateValue({
            ease: easeInCubic,
            delay: 2500,
            duration: 1500,
            start: 100,
            end: 0,
            onUpdate: (v) => (edgeProximity.value = v / 100),
            onEnd: () => (sweepActive.value = false),
        });
    },
    {
        deep: true,
        immediate: true,
    },
);

const colorSensitivity = computed(() => props.edgeSensitivity + 20);
const isVisible = computed(() => isHovered.value || sweepActive.value);

/** Always keep a faint border; brighten on hover, strongest near edges. */
const borderOpacity = computed(() => {
    const base = 0.42;
    if (!isVisible.value) {
        return base;
    }
    const edgeBoost = Math.max(
        0,
        (edgeProximity.value * 100 - colorSensitivity.value) / (100 - colorSensitivity.value),
    );
    return Math.min(1, base + edgeBoost * (1 - base));
});

/** Outer halo: visible anywhere on the card while hovered, brightest at edges. */
const glowOpacity = computed(() => {
    if (!isVisible.value) {
        return 0;
    }
    const edgeBoost = Math.max(
        0,
        (edgeProximity.value * 100 - props.edgeSensitivity) / (100 - props.edgeSensitivity),
    );
    return Math.min(1, 0.28 + edgeBoost * 0.72);
});

const meshGradients = computed(() => buildMeshGradients(props.colors));
const borderBg = computed(() => meshGradients.value.map((g) => `${g} border-box`));
const fillBg = computed(() => meshGradients.value.map((g) => `${g} padding-box`));
const angleDeg = computed(() => `${cursorAngle.value.toFixed(3)}deg`);

const shellStyle = computed(() => ({
    background: props.backgroundColor,
    borderRadius: `${props.borderRadius}px`,
}));

onMounted(() => {
    prefersReducedMotion.value = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    hoverCapable.value = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
});
</script>

<template>
    <div
        v-if="!glowActive"
        :class="['landing-border-glow landing-border-glow--static', props.className]"
        :style="shellStyle"
    >
        <div class="landing-border-glow__content">
            <slot />
        </div>
    </div>

    <div
        v-else
        ref="cardRef"
        :class="['landing-border-glow relative grid isolate border border-white/15', props.className]"
        :style="{
            ...shellStyle,
            transform: 'translate3d(0, 0, 0.01px)',
        }"
        @pointermove="handlePointerMove"
        @pointerenter="isHovered = true"
        @pointerleave="isHovered = false"
    >
        <div
            class="-z-[1] absolute inset-0 rounded-[inherit]"
            :style="{
                border: '1px solid transparent',
                background: [
                    `linear-gradient(${props.backgroundColor} 0 100%) padding-box`,
                    'linear-gradient(rgb(255 255 255 / 0%) 0% 100%) border-box',
                    ...borderBg,
                ].join(', '),
                opacity: borderOpacity,
                maskImage: `conic-gradient(from ${angleDeg} at center, black ${props.coneSpread}%, transparent ${
                    props.coneSpread + 15
                }%, transparent ${100 - props.coneSpread - 15}%, black ${100 - props.coneSpread}%)`,
                WebkitMaskImage: `conic-gradient(from ${angleDeg} at center, black ${props.coneSpread}%, transparent ${
                    props.coneSpread + 15
                }%, transparent ${100 - props.coneSpread - 15}%, black ${100 - props.coneSpread}%)`,
                transition: isVisible ? 'opacity 0.25s ease-out' : 'opacity 0.75s ease-in-out',
            }"
        />

        <div
            class="-z-[1] absolute inset-0 rounded-[inherit]"
            :style="{
                border: '1px solid transparent',
                background: fillBg.join(', '),
                maskImage: [
                    'linear-gradient(to bottom, black, black)',
                    'radial-gradient(ellipse at 50% 50%, black 40%, transparent 65%)',
                    'radial-gradient(ellipse at 66% 66%, black 5%, transparent 40%)',
                    'radial-gradient(ellipse at 33% 33%, black 5%, transparent 40%)',
                    'radial-gradient(ellipse at 66% 33%, black 5%, transparent 40%)',
                    'radial-gradient(ellipse at 33% 66%, black 5%, transparent 40%)',
                    `conic-gradient(from ${angleDeg} at center, transparent 5%, black 15%, black 85%, transparent 95%)`,
                ].join(', '),
                WebkitMaskImage: [
                    'linear-gradient(to bottom, black, black)',
                    'radial-gradient(ellipse at 50% 50%, black 40%, transparent 65%)',
                    'radial-gradient(ellipse at 66% 66%, black 5%, transparent 40%)',
                    'radial-gradient(ellipse at 33% 33%, black 5%, transparent 40%)',
                    'radial-gradient(ellipse at 66% 33%, black 5%, transparent 40%)',
                    'radial-gradient(ellipse at 33% 66%, black 5%, transparent 40%)',
                    `conic-gradient(from ${angleDeg} at center, transparent 5%, black 15%, black 85%, transparent 95%)`,
                ].join(', '),
                maskComposite: 'subtract, add, add, add, add, add',
                WebkitMaskComposite: 'source-out, source-over, source-over, source-over, source-over, source-over',
                opacity: borderOpacity * props.fillOpacity,
                mixBlendMode: 'soft-light',
                transition: isVisible ? 'opacity 0.25s ease-out' : 'opacity 0.75s ease-in-out',
            }"
        />

        <span
            class="z-[1] absolute rounded-[inherit] pointer-events-none"
            :style="{
                inset: `-${props.glowRadius}px`,
                maskImage: `conic-gradient(from ${angleDeg} at center, black 2.5%, transparent 10%, transparent 90%, black 97.5%)`,
                WebkitMaskImage: `conic-gradient(from ${angleDeg} at center, black 2.5%, transparent 10%, transparent 90%, black 97.5%)`,
                opacity: glowOpacity,
                mixBlendMode: 'plus-lighter',
                transition: isVisible ? 'opacity 0.25s ease-out' : 'opacity 0.75s ease-in-out',
            }"
        >
            <span
                class="absolute rounded-[inherit]"
                :style="{
                    inset: `${props.glowRadius}px`,
                    boxShadow: buildBoxShadow(props.glowColor, props.glowIntensity),
                }"
            />
        </span>

        <div class="landing-border-glow__content z-[1] relative flex flex-col overflow-hidden">
            <slot />
        </div>
    </div>
</template>

<style scoped>
.landing-border-glow {
    height: 100%;
    overflow: hidden;
    isolation: isolate;
}

.landing-border-glow__content {
    height: 100%;
    border-radius: inherit;
}

.landing-border-glow--static {
    overflow: hidden;
    border: 1px solid rgba(1, 185, 100, 0.22);
    box-shadow:
        0 0 0 1px rgba(1, 185, 100, 0.06),
        0 0 24px rgba(1, 185, 100, 0.06);
}
</style>
