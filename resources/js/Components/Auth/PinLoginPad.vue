<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { computed } from 'vue';

const { t } = useI18n();

const props = defineProps<{
    modelValue: string;
    disabled?: boolean;
    error?: string | null;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
    submit: [];
}>();

const digits = computed(() => props.modelValue.split(''));

const slots = computed(() => {
    const items: Array<string | null> = [...digits.value];
    while (items.length < 6) {
        items.push(null);
    }

    return items.slice(0, 6);
});

function append(digit: string): void {
    if (props.disabled || props.modelValue.length >= 6) {
        return;
    }
    emit('update:modelValue', props.modelValue + digit);
}

function backspace(): void {
    if (props.disabled || props.modelValue.length === 0) {
        return;
    }
    emit('update:modelValue', props.modelValue.slice(0, -1));
}

function clear(): void {
    if (props.disabled) {
        return;
    }
    emit('update:modelValue', '');
}
</script>

<template>
    <div class="pin-login">
        <div class="pin-login__display" aria-hidden="true">
            <span
                v-for="(slot, index) in slots"
                :key="index"
                class="pin-login__dot"
                :class="{ 'pin-login__dot--filled': slot !== null }"
            />
        </div>

        <p v-if="error" class="pin-login__error">{{ error }}</p>
        <p v-else class="pin-login__hint">{{ t('misc.components.pinLogin.hint') }}</p>

        <div class="pin-login__pad" role="group" :aria-label="t('misc.components.pinLogin.padAria')">
            <button
                v-for="n in 9"
                :key="n"
                type="button"
                class="pin-login__key"
                :disabled="disabled"
                @click="append(String(n))"
            >
                {{ n }}
            </button>
            <button type="button" class="pin-login__key pin-login__key--muted" :disabled="disabled" @click="clear">
                C
            </button>
            <button type="button" class="pin-login__key" :disabled="disabled" @click="append('0')">
                0
            </button>
            <button type="button" class="pin-login__key pin-login__key--muted" :disabled="disabled" @click="backspace">
                ⌫
            </button>
        </div>

        <button
            type="button"
            class="pin-login__submit"
            :disabled="disabled || modelValue.length < 6"
            @click="emit('submit')"
        >
            {{ t('auth.pinSubmit') }}
        </button>
    </div>
</template>

<style scoped>
.pin-login {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.pin-login__display {
    display: flex;
    justify-content: center;
    gap: 0.625rem;
    padding: 0.5rem 0;
}

.pin-login__dot {
    width: 0.75rem;
    height: 0.75rem;
    border-radius: 9999px;
    border: 2px solid var(--wa-border, #3b4a54);
    background: transparent;
    transition:
        border-color 0.15s ease,
        background 0.15s ease,
        transform 0.15s ease;
}

.pin-login__dot--filled {
    border-color: var(--wa-accent, #01b964);
    background: var(--wa-accent, #01b964);
    transform: scale(1.05);
}

.pin-login__hint,
.pin-login__error {
    margin: 0;
    text-align: center;
    font-size: 0.8125rem;
    line-height: 1.4;
}

.pin-login__hint {
    color: var(--wa-text-secondary, #8696a0);
}

.pin-login__error {
    color: #f87171;
}

.pin-login__pad {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.5rem;
}

.pin-login__key {
    min-height: 3rem;
    border-radius: 0.625rem;
    border: 1px solid var(--wa-border, #3b4a54);
    background: var(--wa-panel-input, #2a3942);
    color: var(--wa-text, #e9edef);
    font-size: 1.125rem;
    font-weight: 500;
    transition:
        background 0.15s ease,
        border-color 0.15s ease;
}

.pin-login__key:hover:not(:disabled) {
    border-color: var(--wa-accent, #01b964);
    background: color-mix(in srgb, var(--wa-accent, #01b964) 12%, var(--wa-panel-input, #2a3942));
}

.pin-login__key:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pin-login__key--muted {
    font-size: 0.875rem;
    font-weight: 600;
    letter-spacing: 0.02em;
}

.pin-login__submit {
    width: 100%;
    min-height: 2.75rem;
    border: none;
    border-radius: 0.5rem;
    background: var(--wa-accent, #01b964);
    color: #fff;
    font-size: 0.875rem;
    font-weight: 600;
    transition: opacity 0.15s ease;
}

.pin-login__submit:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}
</style>
