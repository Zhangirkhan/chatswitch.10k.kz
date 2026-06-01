<script setup lang="ts">
defineProps<{
    message?: string;
}>();
</script>

<template>
    <div
        class="rounded-lg border border-dashed px-4 py-6 text-center text-sm"
        :style="{ borderColor: 'var(--ui-border)', color: 'var(--ui-text-secondary)' }"
    >
        <div class="mb-1 text-base" aria-hidden="true">💳</div>
        <p>{{ message || 'Нет данных — подключим при интеграции заказов/оплат' }}</p>
    </div>
</template>
