<script setup lang="ts">
const props = defineProps<{
    modelValue: boolean;
    title: string;
    description?: string;
    helpLink?: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
}>();

function toggle() {
    emit('update:modelValue', !props.modelValue);
}
</script>

<template>
    <div class="setting-toggle">
        <div class="flex-1 min-w-0">
            <div class="text-[15px] text-[var(--wa-text)]">{{ title }}</div>
            <div v-if="description" class="text-xs text-[var(--wa-text-secondary)] mt-1 leading-relaxed">
                {{ description }}
                <a
                    v-if="helpLink"
                    :href="helpLink"
                    target="_blank"
                    rel="noopener"
                    class="font-medium"
                    :style="{ color: 'var(--wa-accent)' }"
                >
                    Подробнее
                </a>
            </div>
        </div>
        <button
            type="button"
            @click="toggle"
            class="switch shrink-0"
            :class="{ 'switch-on': modelValue }"
            :aria-pressed="modelValue"
            role="switch"
        >
            <span class="switch-knob"></span>
        </button>
    </div>
</template>

<style scoped>
.setting-toggle {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    padding: 0.875rem 1.5rem;
    width: 100%;
}
.switch {
    position: relative;
    width: 36px;
    height: 20px;
    border-radius: 9999px;
    background-color: var(--wa-border-strong);
    transition: background-color 0.15s ease;
    margin-top: 2px;
}
.switch-knob {
    position: absolute;
    top: 2px;
    left: 2px;
    width: 16px;
    height: 16px;
    border-radius: 9999px;
    background: #ffffff;
    transition: transform 0.15s ease;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}
.switch-on {
    background-color: var(--wa-accent);
}
.switch-on .switch-knob {
    transform: translateX(16px);
}
</style>
