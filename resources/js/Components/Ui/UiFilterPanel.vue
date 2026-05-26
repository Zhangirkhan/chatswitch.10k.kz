<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        as?: 'form' | 'div';
        compact?: boolean;
    }>(),
    {
        as: 'form',
        compact: false,
    },
);

const emit = defineEmits<{
    submit: [];
}>();

const tag = computed(() => props.as);

function onSubmit(event: Event): void {
    event.preventDefault();
    emit('submit');
}
</script>

<template>
    <component
        :is="tag"
        class="ui-filter-panel"
        :class="{ 'ui-filter-panel--compact': compact }"
        @submit="as === 'form' ? onSubmit($event) : undefined"
    >
        <slot />
        <div v-if="$slots.actions" class="ui-filter-panel__actions">
            <slot name="actions" />
        </div>
    </component>
</template>
