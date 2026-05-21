<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

defineOptions({ inheritAttrs: false });

const navRef = ref<HTMLElement | null>(null);
const ready = ref(false);

const indicator = ref({
    width: 0,
    x: 0,
    insetTop: 4,
    insetBottom: 4,
    opacity: 0,
});

let resizeObserver: ResizeObserver | null = null;
let mutationObserver: MutationObserver | null = null;

function getActiveItem(): HTMLElement | null {
    return navRef.value?.querySelector('.ui-pill-nav__item.is-active') ?? null;
}

function readNavInsets(nav: HTMLElement): { top: number; bottom: number } {
    const styles = getComputedStyle(nav);
    return {
        top: Number.parseFloat(styles.paddingTop) || 0,
        bottom: Number.parseFloat(styles.paddingBottom) || 0,
    };
}

function moveTo(el: HTMLElement | null, animate: boolean): void {
    const nav = navRef.value;
    if (!nav) {
        return;
    }

    if (!el) {
        indicator.value = { ...indicator.value, opacity: 0 };
        return;
    }

    const navRect = nav.getBoundingClientRect();
    const rect = el.getBoundingClientRect();
    const insets = readNavInsets(nav);

    indicator.value = {
        width: rect.width,
        x: rect.left - navRect.left,
        insetTop: insets.top,
        insetBottom: insets.bottom,
        opacity: 1,
    };

    nav.dataset.pillNavAnimate = animate && ready.value ? '1' : '0';

    if (!ready.value) {
        void nextTick(() => {
            ready.value = true;
        });
    }
}

function syncFromActive(animate = true): void {
    moveTo(getActiveItem(), animate);
}

function onNavClick(event: MouseEvent): void {
    const item = (event.target as HTMLElement | null)?.closest('.ui-pill-nav__item');
    if (item instanceof HTMLElement) {
        moveTo(item, true);
    }
}

onMounted(async () => {
    await nextTick();
    syncFromActive(false);

    const nav = navRef.value;
    if (!nav) {
        return;
    }

    resizeObserver = new ResizeObserver(() => syncFromActive(true));
    resizeObserver.observe(nav);

    mutationObserver = new MutationObserver(() => syncFromActive(true));
    mutationObserver.observe(nav, {
        subtree: true,
        attributes: true,
        attributeFilter: ['class'],
    });

    nav.addEventListener('click', onNavClick);
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    mutationObserver?.disconnect();
    navRef.value?.removeEventListener('click', onNavClick);
});
</script>

<template>
    <div
        ref="navRef"
        class="ui-pill-nav ui-pill-nav--sliding"
        v-bind="$attrs"
    >
        <span
            class="ui-pill-nav__indicator"
            :style="{
                width: `${indicator.width}px`,
                top: `${indicator.insetTop}px`,
                bottom: `${indicator.insetBottom}px`,
                transform: `translate3d(${indicator.x}px, 0, 0)`,
                opacity: indicator.opacity,
            }"
            aria-hidden="true"
        />
        <slot />
    </div>
</template>
