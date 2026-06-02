<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from '@/composables/useI18n';
import { computed } from 'vue';

const { t } = useI18n();

const props = defineProps<{
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}>();

const showSummary = computed(() => props.total > 0 && props.from !== null && props.to !== null);

const pageLinks = computed(() =>
    props.links.filter((link) => {
        const label = link.label.trim();
        return label !== '&laquo; Previous' && label !== 'Next &raquo;' && label !== '« Previous' && label !== 'Next »';
    }),
);
</script>

<template>
    <nav v-if="links.length > 3 || total > 0" class="ui-pagination" :aria-label="t('misc.components.pagination.ariaLabel')">
        <p v-if="showSummary" class="ui-pagination__summary text-sm text-ui-text-secondary">
            {{ t('misc.components.pagination.summary', { from: from ?? 0, to: to ?? 0, total }) }}
        </p>
        <div class="ui-pagination__links">
            <Link
                v-for="(link, idx) in links"
                :key="`${idx}-${link.label}`"
                :href="link.url ?? '#'"
                class="ui-pagination__link"
                :class="{ 'is-active': link.active, 'is-disabled': !link.url }"
                :preserve-scroll="true"
                :preserve-state="true"
                v-html="link.label"
            />
        </div>
    </nav>
</template>

<style scoped>
.ui-pagination {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 16px;
    border-top: 1px solid var(--ui-border);
}

.ui-pagination__links {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

.ui-pagination__link {
    display: inline-flex;
    min-width: 2rem;
    align-items: center;
    justify-content: center;
    padding: 6px 10px;
    font-size: 0.8125rem;
    border-radius: 0.375rem;
    color: var(--ui-text-secondary);
    text-decoration: none;
    transition: background-color 0.12s ease, color 0.12s ease;
}

.ui-pagination__link:hover:not(.is-disabled):not(.is-active) {
    background: var(--ui-surface-hover);
    color: var(--ui-text);
}

.ui-pagination__link.is-active {
    background: var(--ui-accent-soft);
    color: var(--ui-accent);
    font-weight: 600;
}

.ui-pagination__link.is-disabled {
    opacity: 0.4;
    pointer-events: none;
}
</style>
