<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

const props = defineProps<{
    attemptedHost?: string | null;
    reason?: 'unknown_tenant' | 'not_found' | null;
}>();

const isUnknownTenant = props.reason === 'unknown_tenant';
const heading = isUnknownTenant
    ? 'Такого рабочего пространства не существует'
    : 'Страница не найдена';
const subheading = isUnknownTenant && props.attemptedHost
    ? `Адрес ${props.attemptedHost} не зарегистрирован в Accel.`
    : 'Извините, страница, которую вы ищете, отсутствует или была перемещена.';
</script>

<template>
    <div class="not-found">
        <Head :title="heading" />

        <div class="not-found__bg" aria-hidden="true">
            <span class="not-found__orb not-found__orb--a"></span>
            <span class="not-found__orb not-found__orb--b"></span>
            <span class="not-found__orb not-found__orb--c"></span>
            <span class="not-found__grid"></span>
        </div>

        <header class="not-found__header">
            <a href="/" class="not-found__brand">Accel</a>
            <nav class="not-found__nav">
                <a href="https://app.accel.kz/login" class="not-found__nav-link">Вход</a>
                <a href="/#request" class="not-found__nav-cta">Оставить заявку</a>
            </nav>
        </header>

        <main class="not-found__main">
            <div class="not-found__panel">
                <div class="not-found__digits" aria-hidden="true">
                    <span>4</span>
                    <span class="not-found__zero">
                        <svg viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">
                            <defs>
                                <linearGradient id="ring-grad" x1="0" y1="0" x2="1" y2="1">
                                    <stop offset="0%" stop-color="#34d399" />
                                    <stop offset="60%" stop-color="#10b981" />
                                    <stop offset="100%" stop-color="#0ea5e9" />
                                </linearGradient>
                            </defs>
                            <circle cx="60" cy="60" r="46" fill="none" stroke="url(#ring-grad)" stroke-width="10" stroke-linecap="round" stroke-dasharray="220 70" />
                            <circle cx="60" cy="60" r="32" fill="none" stroke="rgba(148, 163, 184, 0.35)" stroke-width="2" stroke-dasharray="4 6" />
                            <circle cx="60" cy="60" r="6" fill="#34d399" />
                        </svg>
                    </span>
                    <span>4</span>
                </div>

                <h1 class="not-found__title">{{ heading }}</h1>
                <p class="not-found__lead">{{ subheading }}</p>

                <div v-if="attemptedHost" class="not-found__chip">
                    <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M3 7l7-4 7 4M3 7v6l7 4 7-4V7M3 7l7 4 7-4" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                    </svg>
                    <span>{{ attemptedHost }}</span>
                </div>

                <div class="not-found__actions">
                    <a href="/" class="not-found__btn not-found__btn--primary">
                        <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M3 10l7-7 7 7M5 9v8h10V9" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        На главную
                    </a>
                    <a href="/#request" class="not-found__btn not-found__btn--ghost">
                        Оставить заявку
                    </a>
                </div>

                <p class="not-found__hint">
                    Если вы&nbsp;уверены, что адрес верный — напишите нам:
                    <a href="mailto:hello@accel.kz">hello@accel.kz</a>
                </p>
            </div>
        </main>

        <footer class="not-found__footer">
            <span>© {{ new Date().getFullYear() }} Accel</span>
            <a href="/">accel.kz</a>
        </footer>
    </div>
</template>

<style scoped>
.not-found {
    position: relative;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    background: #050810;
    color: #e2e8f0;
    overflow: hidden;
    font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

.not-found__bg {
    position: absolute;
    inset: 0;
    z-index: 0;
    pointer-events: none;
}

.not-found__orb {
    position: absolute;
    border-radius: 50%;
    filter: blur(90px);
    opacity: 0.55;
}

.not-found__orb--a {
    width: 460px;
    height: 460px;
    top: -120px;
    left: -100px;
    background: radial-gradient(circle, rgba(16, 185, 129, 0.55), transparent 70%);
}

.not-found__orb--b {
    width: 520px;
    height: 520px;
    bottom: -160px;
    right: -120px;
    background: radial-gradient(circle, rgba(14, 165, 233, 0.45), transparent 70%);
}

.not-found__orb--c {
    width: 320px;
    height: 320px;
    top: 30%;
    left: 55%;
    background: radial-gradient(circle, rgba(139, 92, 246, 0.35), transparent 70%);
    opacity: 0.4;
}

.not-found__grid {
    position: absolute;
    inset: 0;
    background-image:
        linear-gradient(rgba(148, 163, 184, 0.07) 1px, transparent 1px),
        linear-gradient(90deg, rgba(148, 163, 184, 0.07) 1px, transparent 1px);
    background-size: 48px 48px;
    mask-image: radial-gradient(ellipse at center, black 30%, transparent 75%);
    -webkit-mask-image: radial-gradient(ellipse at center, black 30%, transparent 75%);
}

.not-found__header {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.5rem clamp(1.25rem, 4vw, 2.5rem);
}

.not-found__brand {
    font-size: 1.25rem;
    font-weight: 700;
    color: #ffffff;
    text-decoration: none;
    letter-spacing: -0.01em;
}

.not-found__nav {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.not-found__nav-link {
    color: #cbd5e1;
    font-size: 0.875rem;
    text-decoration: none;
    transition: color 0.15s ease;
}

.not-found__nav-link:hover {
    color: #ffffff;
}

.not-found__nav-cta {
    display: inline-flex;
    align-items: center;
    height: 2.25rem;
    padding: 0 1rem;
    border-radius: 9999px;
    background: rgba(16, 185, 129, 0.12);
    border: 1px solid rgba(16, 185, 129, 0.45);
    color: #6ee7b7;
    font-size: 0.875rem;
    font-weight: 500;
    text-decoration: none;
    transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}

.not-found__nav-cta:hover {
    background: rgba(16, 185, 129, 0.22);
    border-color: rgba(16, 185, 129, 0.7);
    color: #ffffff;
}

.not-found__main {
    position: relative;
    z-index: 1;
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem clamp(1.25rem, 4vw, 2.5rem) 3rem;
}

.not-found__panel {
    width: 100%;
    max-width: 38rem;
    text-align: center;
    padding: clamp(1.5rem, 4vw, 2.75rem);
    border-radius: 1.75rem;
    border: 1px solid rgba(148, 163, 184, 0.15);
    background:
        linear-gradient(180deg, rgba(15, 23, 42, 0.85), rgba(8, 12, 22, 0.92));
    backdrop-filter: blur(18px) saturate(140%);
    box-shadow:
        0 30px 80px rgba(0, 0, 0, 0.55),
        inset 0 1px 0 rgba(255, 255, 255, 0.05);
}

.not-found__digits {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    margin-bottom: 1.5rem;
    font-size: clamp(4rem, 12vw, 7rem);
    font-weight: 800;
    line-height: 1;
    letter-spacing: -0.06em;
    color: #f8fafc;
    background: linear-gradient(180deg, #ffffff 0%, #94a3b8 100%);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
}

.not-found__zero {
    display: inline-flex;
    width: clamp(4rem, 12vw, 7rem);
    height: clamp(4rem, 12vw, 7rem);
    align-items: center;
    justify-content: center;
    animation: nf-spin 14s linear infinite;
    -webkit-text-fill-color: initial;
}

.not-found__zero svg {
    width: 100%;
    height: 100%;
    filter: drop-shadow(0 0 24px rgba(16, 185, 129, 0.35));
}

.not-found__title {
    margin: 0 0 0.65rem;
    font-size: clamp(1.5rem, 3.4vw, 2rem);
    font-weight: 700;
    letter-spacing: -0.02em;
    color: #ffffff;
}

.not-found__lead {
    margin: 0 auto;
    max-width: 28rem;
    font-size: 1rem;
    line-height: 1.6;
    color: #94a3b8;
}

.not-found__chip {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 1.25rem;
    padding: 0.4rem 0.9rem;
    border-radius: 9999px;
    background: rgba(15, 23, 42, 0.8);
    border: 1px solid rgba(148, 163, 184, 0.2);
    font-size: 0.825rem;
    color: #cbd5e1;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}

.not-found__chip svg {
    width: 1rem;
    height: 1rem;
    color: #6ee7b7;
}

.not-found__actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.75rem;
    margin-top: 2rem;
}

.not-found__btn {
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

.not-found__btn svg {
    width: 1.1rem;
    height: 1.1rem;
}

.not-found__btn--primary {
    background: linear-gradient(135deg, #10b981, #0ea5e9);
    color: #04111d;
    box-shadow: 0 14px 30px rgba(16, 185, 129, 0.35);
}

.not-found__btn--primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 18px 40px rgba(16, 185, 129, 0.45);
}

.not-found__btn--ghost {
    background: rgba(15, 23, 42, 0.6);
    border: 1px solid rgba(148, 163, 184, 0.25);
    color: #e2e8f0;
}

.not-found__btn--ghost:hover {
    background: rgba(15, 23, 42, 0.85);
    border-color: rgba(148, 163, 184, 0.45);
}

.not-found__hint {
    margin: 1.75rem 0 0;
    font-size: 0.82rem;
    color: #64748b;
}

.not-found__hint a {
    color: #6ee7b7;
    text-decoration: none;
    border-bottom: 1px dashed rgba(110, 231, 183, 0.4);
}

.not-found__hint a:hover {
    color: #ffffff;
    border-bottom-color: rgba(255, 255, 255, 0.7);
}

.not-found__footer {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.25rem clamp(1.25rem, 4vw, 2.5rem);
    font-size: 0.78rem;
    color: #475569;
}

.not-found__footer a {
    color: #94a3b8;
    text-decoration: none;
}

.not-found__footer a:hover {
    color: #ffffff;
}

@keyframes nf-spin {
    to { transform: rotate(360deg); }
}

@media (max-width: 480px) {
    .not-found__nav-link { display: none; }
}
</style>
