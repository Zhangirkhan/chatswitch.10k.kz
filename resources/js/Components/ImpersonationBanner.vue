<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/composables/useI18n';
import { computed } from 'vue';

type ImpersonationMeta = {
    super_user_name?: string;
    company_name?: string;
    return_url?: string;
};

const page = usePage();
const { t } = useI18n();
const meta = computed(() => page.props.impersonation as ImpersonationMeta | null | undefined);
const visible = computed(() => meta.value != null && typeof meta.value === 'object');

function leave(): void {
    router.post('/impersonate/leave');
}
</script>

<template>
    <div
        v-if="visible"
        class="impersonation-banner"
        role="status"
        aria-live="polite"
    >
        <p class="impersonation-banner__text">
            {{ t('misc.components.impersonation.text', { company: meta?.company_name ?? t('misc.components.impersonation.companyFallback') }) }}
            <span v-if="meta?.super_user_name" class="impersonation-banner__muted">
                ({{ meta.super_user_name }})
            </span>
        </p>
        <button
            type="button"
            class="impersonation-banner__close"
            :title="t('misc.components.impersonation.leaveTitle')"
            :aria-label="t('misc.components.impersonation.leaveAria')"
            @click="leave"
        >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
            </svg>
        </button>
    </div>
</template>

<style scoped>
.impersonation-banner {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 200;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1rem;
    padding: 0.5rem 2.75rem 0.5rem 1rem;
    background: linear-gradient(
        90deg,
        color-mix(in srgb, #d97706 92%, #1a1a1a),
        color-mix(in srgb, #b45309 88%, #1a1a1a)
    );
    color: #fffbeb;
    font-size: 0.8125rem;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.35);
}

.impersonation-banner__text {
    margin: 0;
    text-align: center;
    line-height: 1.4;
}

.impersonation-banner__text strong {
    font-weight: 600;
}

.impersonation-banner__muted {
    opacity: 0.85;
}

.impersonation-banner__close {
    position: absolute;
    right: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    display: flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border: none;
    border-radius: 0.375rem;
    background: rgba(0, 0, 0, 0.2);
    color: inherit;
    cursor: pointer;
}

.impersonation-banner__close:hover {
    background: rgba(0, 0, 0, 0.35);
}
</style>
