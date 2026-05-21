<script setup lang="ts">
import { useViewTransitionKey, type ViewTransitionScope } from '@/composables/useViewTransitionKey';

const props = withDefaults(
    defineProps<{
        transitionKey?: string | number | null;
        scope?: ViewTransitionScope;
        appear?: boolean;
        panelClass?: string;
    }>(),
    {
        transitionKey: null,
        scope: 'page',
        appear: false,
        panelClass: '',
    },
);

defineOptions({ inheritAttrs: false });

const resolvedKey = useViewTransitionKey(
    () => props.scope,
    () => props.transitionKey,
);
</script>

<template>
    <div class="ui-view-transition" v-bind="$attrs">
        <Transition name="ui-view" mode="out-in" :appear="appear">
            <div :key="resolvedKey" class="ui-view-transition__panel" :class="panelClass">
                <slot />
            </div>
        </Transition>
    </div>
</template>
