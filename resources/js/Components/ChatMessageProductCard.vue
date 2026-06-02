<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { computed } from 'vue';

const { t } = useI18n();
import type { MessageProductAttachment } from '@/types';

const props = defineProps<{
    product: MessageProductAttachment;
    outbound?: boolean;
}>();

const attributeLine = computed(() => {
    const attrs = props.product.attributes;
    if (!attrs || typeof attrs !== 'object') {
        return '';
    }

    return Object.entries(attrs)
        .filter(([, value]) => value !== null && value !== '')
        .slice(0, 3)
        .map(([key, value]) => {
            const v = Array.isArray(value) ? value.join(', ') : String(value);

            return `${key}: ${v}`;
        })
        .join(' · ');
});
</script>

<template>
    <div class="wa-product-card" :class="{ 'wa-product-card--out': outbound }">
        <div v-if="product.image_url" class="wa-product-card__media">
            <img :src="product.image_url" :alt="product.name" class="wa-product-card__img" loading="lazy" />
        </div>
        <div class="wa-product-card__footer">
            <div class="wa-product-card__head">
                <span class="wa-product-card__title">{{ product.name }}</span>
                <span v-if="product.price_formatted" class="wa-product-card__price">{{ product.price_formatted }}</span>
            </div>
            <p v-if="product.description" class="wa-product-card__desc">{{ product.description }}</p>
            <p v-if="attributeLine" class="wa-product-card__attrs">{{ attributeLine }}</p>
            <p v-if="product.sku" class="wa-product-card__sku">{{ t('misc.components.productCard.sku', { sku: product.sku }) }}</p>
        </div>
    </div>
</template>

<style scoped>
.wa-product-card {
    --wa-product-footer-bg: color-mix(in srgb, #000 10%, transparent);
    --wa-product-footer-border: color-mix(in srgb, currentColor 10%, transparent);
    max-width: min(280px, 100%);
    margin: 2px -4px 6px;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 1px 0 color-mix(in srgb, #000 12%, transparent);
}

.wa-product-card--out {
    --wa-product-footer-bg: color-mix(in srgb, #000 16%, transparent);
    --wa-product-footer-border: color-mix(in srgb, #fff 10%, transparent);
}

.wa-product-card__media {
    line-height: 0;
    background: color-mix(in srgb, #000 6%, transparent);
}

.wa-product-card__img {
    display: block;
    width: 100%;
    max-height: 220px;
    object-fit: cover;
}

.wa-product-card__footer {
    padding: 8px 10px 9px;
    background: var(--wa-product-footer-bg);
    border-top: 1px solid var(--wa-product-footer-border);
}

.wa-product-card__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
}

.wa-product-card__title {
    flex: 1;
    min-width: 0;
    font-size: 14px;
    font-weight: 600;
    line-height: 1.25;
    color: var(--wa-text);
    word-break: break-word;
}

.wa-product-card__price {
    flex-shrink: 0;
    font-size: 13px;
    font-weight: 700;
    line-height: 1.25;
    color: var(--wa-accent);
    white-space: nowrap;
}

.wa-product-card__desc,
.wa-product-card__attrs,
.wa-product-card__sku {
    margin: 5px 0 0;
    font-size: 12px;
    line-height: 1.35;
    color: var(--wa-text-secondary);
    word-break: break-word;
}

.wa-product-card__desc {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>
