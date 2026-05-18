<script setup lang="ts">
import Modal from '@/Components/Modal.vue';
import AiSimulationResult, { type SimulationResult } from '@/Components/Ai/AiSimulationResult.vue';
import axios from 'axios';
import { ref, watch } from 'vue';

const props = defineProps<{
    show: boolean;
    chatId: number;
    chatName?: string | null;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const simulationMessage = ref('');
const simulationHistory = ref('');
const simulationLoading = ref(false);
const simulationError = ref<string | null>(null);
const simulationResult = ref<SimulationResult | null>(null);

watch(
    () => props.show,
    (open) => {
        if (!open) {
            return;
        }
        simulationMessage.value = '';
        simulationHistory.value = '';
        simulationError.value = null;
        simulationResult.value = null;
        simulationLoading.value = false;
    },
);

async function runSimulation(): Promise<void> {
    const message = simulationMessage.value.trim();
    if (!message || simulationLoading.value) {
        return;
    }

    simulationLoading.value = true;
    simulationError.value = null;
    simulationResult.value = null;

    try {
        const { data } = await axios.post(route('chats.ai-simulate', props.chatId), {
            message,
            history: simulationHistory.value.trim(),
        });
        simulationResult.value = data.result as SimulationResult;
    } catch (e: any) {
        simulationError.value = e?.response?.data?.message || 'Не удалось запустить симуляцию.';
    } finally {
        simulationLoading.value = false;
    }
}
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="emit('close')">
        <div class="p-6 space-y-5" :style="{ background: 'var(--wa-panel)', color: 'var(--wa-text)' }">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">Симулятор AI</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--wa-text-secondary)' }">
                        Dry-run для чата{{ chatName ? ` «${chatName}»` : '' }}: ответ, этап и действия без записи в переписку.
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded-lg px-2 py-1 text-sm"
                    :style="{ color: 'var(--wa-text-secondary)' }"
                    @click="emit('close')"
                >
                    Закрыть
                </button>
            </div>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(300px,0.95fr)]">
                <div class="space-y-3">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium" :style="{ color: 'var(--wa-text-secondary)' }">
                            Тестовое сообщение клиента
                        </span>
                        <textarea
                            v-model="simulationMessage"
                            rows="4"
                            class="w-full rounded-xl border px-3 py-2 text-sm focus:outline-none focus:ring-2"
                            :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border)', color: 'var(--wa-text)', '--tw-ring-color': 'var(--wa-accent)' }"
                            placeholder="Например: хочу кухню, когда замер?"
                        />
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-xs font-medium" :style="{ color: 'var(--wa-text-secondary)' }">
                            Доп. контекст <small>(необязательно)</small>
                        </span>
                        <textarea
                            v-model="simulationHistory"
                            rows="3"
                            class="w-full rounded-xl border px-3 py-2 text-sm focus:outline-none focus:ring-2"
                            :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border)', color: 'var(--wa-text)', '--tw-ring-color': 'var(--wa-accent)' }"
                            placeholder="Уточнения, которых нет в истории чата"
                        />
                    </label>

                    <button
                        type="button"
                        class="rounded-xl px-4 py-2 text-sm font-semibold disabled:opacity-60"
                        :disabled="simulationLoading || simulationMessage.trim().length === 0"
                        :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                        @click="runSimulation"
                    >
                        {{ simulationLoading ? 'AI думает…' : 'Запустить симуляцию' }}
                    </button>
                </div>

                <div class="rounded-xl border p-4 min-h-[220px]" :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel-header)' }">
                    <div v-if="simulationError" class="text-sm" :style="{ color: 'var(--wa-danger)' }">{{ simulationError }}</div>
                    <div v-else-if="!simulationResult" class="text-sm" :style="{ color: 'var(--wa-text-secondary)' }">
                        Результат появится здесь. История реального чата подставляется автоматически.
                    </div>
                    <AiSimulationResult v-else :result="simulationResult" />
                </div>
            </div>
        </div>
    </Modal>
</template>
