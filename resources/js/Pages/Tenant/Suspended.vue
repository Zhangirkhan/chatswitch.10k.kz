<script setup lang="ts">
import AccelMark from '@/Components/AccelMark.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from '@/composables/useI18n';

type Reason = 'disabled' | 'suspended' | 'canceled';

const props = defineProps<{
    companyName: string;
    companySlug: string;
    reason: Reason;
    status: string;
    isActive: boolean;
    title: string;
    description: string;
    supportEmail: string;
}>();

const { t } = useI18n();

const accent = computed(() => {
    switch (props.reason) {
        case 'disabled':
            return { color: '#f59e0b', glow: 'rgba(245, 158, 11, 0.4)', label: t('misc.tenantDisabled') };
        case 'suspended':
            return { color: '#ef4444', glow: 'rgba(239, 68, 68, 0.4)', label: t('misc.tenantSuspended') };
        case 'canceled':
            return { color: '#94a3b8', glow: 'rgba(148, 163, 184, 0.35)', label: t('misc.tenantCanceled') };
        default:
            return { color: '#f59e0b', glow: 'rgba(245, 158, 11, 0.4)', label: t('misc.tenantUnavailable') };
    }
});

const iconPath = computed(() => {
    switch (props.reason) {
        case 'disabled':
            return 'M12 3 1.5 21h21L12 3Zm0 4.5L19.4 19.5H4.6L12 7.5ZM11 10v5h2v-5h-2Zm0 6v2h2v-2h-2Z';
        case 'suspended':
            return 'M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8 8 8 0 0 1-8 8ZM10 8h2v8h-2Zm4 0h2v8h-2Z';
        case 'canceled':
            return 'M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm5.66 14.24-1.42 1.42L12 13.41l-4.24 4.25-1.42-1.42L10.59 12 6.34 7.76l1.42-1.42L12 10.59l4.24-4.25 1.42 1.42L13.41 12Z';
        default:
            return 'M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Z';
    }
});
</script>

<template>
    <div class="suspended">
        <Head :title="title" />

        <div class="suspended__bg" aria-hidden="true">
            <span class="suspended__orb suspended__orb--a" :style="{ background: `radial-gradient(circle, ${accent.glow}, transparent 70%)` }"></span>
            <span class="suspended__orb suspended__orb--b"></span>
            <span class="suspended__grid"></span>
        </div>

        <header class="suspended__header">
            <a href="https://accel.kz/" class="suspended__brand">
                <AccelMark :size="28" />
                <span>Accel</span>
            </a>
            <span class="suspended__badge" :style="{ color: accent.color, borderColor: accent.color }">
                <span class="suspended__badge-dot" :style="{ background: accent.color }"></span>
                {{ accent.label }}
            </span>
        </header>

        <main class="suspended__main">
            <div class="suspended__card">
                <div class="suspended__icon-wrap" :style="{ color: accent.color, background: `${accent.color}1a`, boxShadow: `0 0 0 1px ${accent.color}55, 0 18px 36px ${accent.glow}` }">
                    <svg viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path :d="iconPath" />
                    </svg>
                </div>

                <h1 class="suspended__title">{{ title }}</h1>
                <p class="suspended__lead">{{ description }}</p>

                <div class="suspended__meta">
                    <div class="suspended__meta-row">
                        <span class="suspended__meta-label">{{ t('misc.tenantCompany') }}</span>
                        <span class="suspended__meta-value">{{ companyName }}</span>
                    </div>
                    <div class="suspended__meta-row">
                        <span class="suspended__meta-label">{{ t('misc.tenantSubdomain') }}</span>
                        <span class="suspended__meta-value suspended__meta-mono">{{ companySlug }}.accel.kz</span>
                    </div>
                    <div class="suspended__meta-row">
                        <span class="suspended__meta-label">{{ t('misc.statusTitle') }}</span>
                        <span class="suspended__meta-value suspended__meta-mono" :style="{ color: accent.color }">
                            {{ isActive ? status : 'inactive' }}
                        </span>
                    </div>
                </div>

                <div class="suspended__actions">
                    <a :href="`mailto:${supportEmail}?subject=Доступ к ${companySlug}.accel.kz`" class="suspended__btn suspended__btn--primary">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M3 6h18a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" />
                            <path d="m3 7 9 6 9-6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        {{ t('misc.tenantContactSupport') }}
                    </a>
                    <a href="https://accel.kz/" class="suspended__btn suspended__btn--ghost">
                        {{ t('misc.tenantBackToAccel') }}
                    </a>
                </div>
            </div>
        </main>

        <footer class="suspended__footer">
            <span>© {{ new Date().getFullYear() }} Accel</span>
            <a href="https://accel.kz/">accel.kz</a>
        </footer>
    </div>
</template>

<style scoped>
.suspended {
    position: relative;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    background: #050810;
    color: #e2e8f0;
    overflow: hidden;
    font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

.suspended__bg {
    position: absolute;
    inset: 0;
    z-index: 0;
    pointer-events: none;
}

.suspended__orb {
    position: absolute;
    border-radius: 50%;
    filter: blur(90px);
    opacity: 0.55;
}

.suspended__orb--a {
    width: 460px;
    height: 460px;
    top: -120px;
    left: -100px;
}

.suspended__orb--b {
    width: 520px;
    height: 520px;
    bottom: -160px;
    right: -120px;
    background: radial-gradient(circle, rgba(14, 165, 233, 0.35), transparent 70%);
}

.suspended__grid {
    position: absolute;
    inset: 0;
    background-image:
        linear-gradient(rgba(148, 163, 184, 0.07) 1px, transparent 1px),
        linear-gradient(90deg, rgba(148, 163, 184, 0.07) 1px, transparent 1px);
    background-size: 48px 48px;
    mask-image: radial-gradient(ellipse at center, black 30%, transparent 75%);
    -webkit-mask-image: radial-gradient(ellipse at center, black 30%, transparent 75%);
}

.suspended__header {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.5rem clamp(1.25rem, 4vw, 2.5rem);
}

.suspended__brand {
    display: inline-flex;
    align-items: center;
    gap: 0.625rem;
    font-size: 1.25rem;
    font-weight: 700;
    color: #ffffff;
    text-decoration: none;
    letter-spacing: -0.01em;
}

.suspended__badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.35rem 0.9rem;
    border-radius: 9999px;
    border: 1px solid;
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    background: rgba(15, 23, 42, 0.6);
}

.suspended__badge-dot {
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 50%;
    box-shadow: 0 0 12px currentColor;
}

.suspended__main {
    position: relative;
    z-index: 1;
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem clamp(1.25rem, 4vw, 2.5rem) 3rem;
}

.suspended__card {
    width: 100%;
    max-width: 38rem;
    text-align: center;
    padding: clamp(1.75rem, 4vw, 2.75rem);
    border-radius: 1.75rem;
    border: 1px solid rgba(148, 163, 184, 0.15);
    background:
        linear-gradient(180deg, rgba(15, 23, 42, 0.85), rgba(8, 12, 22, 0.92));
    backdrop-filter: blur(18px) saturate(140%);
    box-shadow:
        0 30px 80px rgba(0, 0, 0, 0.55),
        inset 0 1px 0 rgba(255, 255, 255, 0.05);
}

.suspended__icon-wrap {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 4.5rem;
    height: 4.5rem;
    border-radius: 1.4rem;
    margin-bottom: 1.5rem;
}

.suspended__icon-wrap svg {
    width: 2.25rem;
    height: 2.25rem;
}

.suspended__title {
    margin: 0 0 0.65rem;
    font-size: clamp(1.5rem, 3.4vw, 2rem);
    font-weight: 700;
    letter-spacing: -0.02em;
    color: #ffffff;
}

.suspended__lead {
    margin: 0 auto;
    max-width: 28rem;
    font-size: 1rem;
    line-height: 1.6;
    color: #94a3b8;
}

.suspended__meta {
    margin: 1.75rem auto 0;
    max-width: 28rem;
    border-radius: 1rem;
    border: 1px solid rgba(148, 163, 184, 0.14);
    background: rgba(15, 23, 42, 0.55);
    overflow: hidden;
    text-align: left;
}

.suspended__meta-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid rgba(148, 163, 184, 0.08);
}

.suspended__meta-row:last-child {
    border-bottom: 0;
}

.suspended__meta-label {
    font-size: 0.8rem;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.suspended__meta-value {
    font-size: 0.9rem;
    color: #e2e8f0;
    font-weight: 500;
}

.suspended__meta-mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-weight: 500;
}

.suspended__actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.75rem;
    margin-top: 1.75rem;
}

.suspended__btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    height: 2.75rem;
    padding: 0 1.4rem;
    border-radius: 9999px;
    font-size: 0.9rem;
    font-weight: 600;
    text-decoration: none;
    transition: transform 0.15s ease, background 0.15s ease, border-color 0.15s ease, box-shadow 0.2s ease;
}

.suspended__btn svg {
    width: 1.1rem;
    height: 1.1rem;
}

.suspended__btn--primary {
    background: linear-gradient(135deg, #10b981, #0ea5e9);
    color: #04111d;
    box-shadow: 0 14px 30px rgba(16, 185, 129, 0.35);
}

.suspended__btn--primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 18px 40px rgba(16, 185, 129, 0.45);
}

.suspended__btn--ghost {
    background: rgba(15, 23, 42, 0.6);
    border: 1px solid rgba(148, 163, 184, 0.25);
    color: #e2e8f0;
}

.suspended__btn--ghost:hover {
    background: rgba(15, 23, 42, 0.85);
    border-color: rgba(148, 163, 184, 0.45);
}

.suspended__footer {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.25rem clamp(1.25rem, 4vw, 2.5rem);
    font-size: 0.78rem;
    color: #475569;
}

.suspended__footer a {
    color: #94a3b8;
    text-decoration: none;
}

.suspended__footer a:hover {
    color: #ffffff;
}
</style>
