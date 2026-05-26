<script setup lang="ts">
import { useRecaptcha } from '@/composables/useRecaptcha';
import { onMounted, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        version?: 'v2' | 'v3';
    }>(),
    {},
);

const recaptcha = useRecaptcha();
const v2Container = ref<HTMLElement | null>(null);
const widgetId = ref<number | null>(null);

const effectiveVersion = () => props.version ?? recaptcha.config.value.version ?? 'v3';

onMounted(async () => {
    if (!recaptcha.enabled.value || effectiveVersion() !== 'v2' || !v2Container.value) {
        return;
    }

    widgetId.value = await recaptcha.renderV2Widget(v2Container.value, () => {});
});

async function resolveToken(action: string): Promise<string> {
    if (!recaptcha.enabled.value) {
        return '';
    }

    if (effectiveVersion() === 'v2') {
        if (widgetId.value === null) {
            return '';
        }

        return recaptcha.getV2Response(widgetId.value);
    }

    return recaptcha.getToken(action);
}

function reset(): void {
    if (widgetId.value !== null) {
        recaptcha.resetV2(widgetId.value);
    }
}

defineExpose({ resolveToken, reset });
</script>

<template>
    <div v-if="recaptcha.enabled && effectiveVersion() === 'v2'" class="flex justify-center py-1">
        <div ref="v2Container" />
    </div>
</template>
