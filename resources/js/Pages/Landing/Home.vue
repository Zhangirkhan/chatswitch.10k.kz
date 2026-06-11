<script setup lang="ts">
import LandingBeforeAfter from '@/Components/Landing/LandingBeforeAfter.vue';
import LandingBorderGlow from '@/Components/Landing/LandingBorderGlow.vue';
import LandingDataKz from '@/Components/Landing/LandingDataKz.vue';
import LandingHead from '@/Components/Landing/LandingHead.vue';
import LandingHeader from '@/Components/Landing/LandingHeader.vue';
import LandingHeroMockup from '@/Components/Landing/LandingHeroMockup.vue';
import LandingParticles from '@/Components/Landing/LandingParticles.vue';
import LandingSignupRequestModal from '@/Components/Landing/LandingSignupRequestModal.vue';
import { useLandingLocale } from '@/composables/useLandingLocale';
import { messagesForLocale } from '@/i18n/messages';
import type { MessageKey } from '@/i18n/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

type PricingPlanCode = 'standard' | 'boxed';

interface PricingPlanProp {
    code: string;
    price: string;
    interval: string;
}

const props = defineProps<{
    rootDomain?: string;
    androidApkUrl?: string;
    pricingPlans?: PricingPlanProp[];
}>();

const rootDomain = computed(() => props.rootDomain ?? 'accel.kz');
const apkDownloadUrl = computed(() => props.androidApkUrl ?? '/apk/app-release.apk');

const page = usePage();
const { t, locale } = useLandingLocale();
const requestModalOpen = ref(false);

const flashSuccess = computed(() => {
    const flash = page.props.flash as { success?: string } | undefined;
    return flash?.success ?? null;
});

const features = computed(() => [
    { icon: 'chat', title: t('landing.feature1Title'), text: t('landing.feature1Text') },
    { icon: 'autopilot', title: t('landing.feature2Title'), text: t('landing.feature2Text') },
    { icon: 'funnel', title: t('landing.feature3Title'), text: t('landing.feature3Text') },
    { icon: 'nudge', title: t('landing.feature4Title'), text: t('landing.feature4Text') },
    { icon: 'ai', title: t('landing.feature5Title'), text: t('landing.feature5Text') },
    { icon: 'team', title: t('landing.feature6Title'), text: t('landing.feature6Text') },
    { icon: 'tasks', title: t('landing.feature7Title'), text: t('landing.feature7Text') },
    { icon: 'chart', title: t('landing.feature8Title'), text: t('landing.feature8Text') },
    { icon: 'database', title: t('landing.feature9Title'), text: t('landing.feature9Text') },
    { icon: 'calendar', title: t('landing.feature10Title'), text: t('landing.feature10Text') },
]);

const featureGlowColors = ['#01b964', '#06d670', '#2dd4bf'] as const;

function isPricingPlanCode(code: string): code is PricingPlanCode {
    return code === 'standard' || code === 'boxed';
}

function planField(code: PricingPlanCode, field: 'name' | 'period' | 'trial' | 'badge' | 'aiNote'): string {
    return t(`landing.pricingPlans.${code}.${field}` as MessageKey);
}

function planBullets(code: PricingPlanCode): string[] {
    return [1, 2, 3, 4, 5].map((index) =>
        t(`landing.pricingPlans.${code}.bullet${index}` as MessageKey),
    );
}

const visiblePricingPlans = computed(() =>
    (props.pricingPlans ?? []).filter((plan): plan is PricingPlanProp & { code: PricingPlanCode } =>
        isPricingPlanCode(plan.code),
    ),
);

const beforeAfterRows = computed(() => messagesForLocale(locale.value).landing.beforeAfterRows ?? []);

const dataPoints = computed(() => messagesForLocale(locale.value).landing.dataPoints ?? []);

const dataKzSecurityList = computed(() => messagesForLocale(locale.value).landing.dataKzSecurityList ?? []);

const problemAsidePoints = computed(() => messagesForLocale(locale.value).landing.problemAsidePoints ?? []);

const isMobileViewport = ref(
    typeof window !== 'undefined' && window.matchMedia('(max-width: 768px)').matches,
);

const heroParticleCount = computed(() => (isMobileViewport.value ? 150 : 300));

function syncViewport(): void {
    isMobileViewport.value = window.matchMedia('(max-width: 768px)').matches;
}

function openRequestModal(): void {
    requestModalOpen.value = true;
}

function closeRequestModal(): void {
    requestModalOpen.value = false;
}

onMounted(() => {
    syncViewport();
    window.addEventListener('resize', syncViewport, { passive: true });

    const errors = page.props.errors as Record<string, string> | undefined;

    if (window.location.hash === '#request' || Object.keys(errors ?? {}).length > 0) {
        openRequestModal();
        if (window.location.hash === '#request') {
            history.replaceState(null, '', window.location.pathname);
        }
    }
});

onUnmounted(() => {
    window.removeEventListener('resize', syncViewport);
});
</script>

<template>
    <div class="landing">
        <LandingHead page="home" />

        <LandingHeader :android-apk-url="apkDownloadUrl" @request="openRequestModal" />

        <main class="landing__main">
            <section class="landing__hero">
                <div class="landing__hero-backdrop" aria-hidden="true">
                    <LandingParticles
                        :particle-count="heroParticleCount"
                        :particle-spread="10"
                        :speed="0.1"
                        :particle-base-size="200"
                        :size-randomness="0.35"
                        :alpha-particles="true"
                        :move-particles-on-hover="true"
                        :particle-hover-factor="0.65"
                        :disable-rotation="true"
                    />
                </div>
                <div class="landing__hero-copy">
                    <h1 class="landing__title">{{ t('landing.heroTitle') }}</h1>
                    <p class="landing__tagline">
                        {{ t('landing.heroTaglineLong') }}
                    </p>
                    <div class="landing__hero-actions">
                        <button type="button" class="landing__cta-btn" @click="openRequestModal">
                            {{ t('landing.ctaButton') }}
                        </button>
                        <p class="landing__hero-trial">{{ t('landing.heroTrialHint') }}</p>
                    </div>
                </div>
                <div class="landing__hero-visual">
                    <LandingHeroMockup :alt="t('landing.heroMockupAlt')" />
                </div>
            </section>

            <LandingBeforeAfter
                :title="t('landing.problemTitle')"
                :lead="t('landing.problemLead')"
                :rows="beforeAfterRows"
                :ba-pain0-unread="t('landing.baPain0Unread')"
                :ba-pain0-wait="t('landing.baPain0Wait')"
                :ba-pain2-muted="t('landing.baPain2Muted')"
                :ba-pain3-label="t('landing.baPain3Label')"
                :ba-label-before="t('landing.baLabelBefore')"
                :ba-label-after="t('landing.baLabelAfter')"
                :ba-after0a="t('landing.baAfter0a')"
                :ba-after0b="t('landing.baAfter0b')"
                :ba-after1-dialogs="t('landing.baAfter1Dialogs')"
                :ba-after1-min="t('landing.baAfter1Min')"
                :ba-after1-window="t('landing.baAfter1Window')"
                :aside-aria="t('landing.problemAsideAria')"
                :aside-title="t('landing.problemAsideTitle')"
                :aside-lead="t('landing.problemAsideLead')"
                :aside-points="problemAsidePoints"
            />

            <section id="features" class="landing__features-section landing__section-block">
                <header class="landing__section-header">
                    <p class="landing__section-eyebrow">{{ t('landing.featuresEyebrow') }}</p>
                    <h2 class="landing__section-heading">{{ t('landing.featuresSectionTitle') }}</h2>
                </header>
                <ul class="landing__features">
                <li v-for="item in features" :key="item.title" class="landing__features-item">
                    <LandingBorderGlow
                        class="landing__card-glow"
                        background-color="transparent"
                        :border-radius="16"
                        :edge-sensitivity="11"
                        :glow-radius="52"
                        :glow-intensity="1.5"
                        :cone-spread="25"
                        :colors="[...featureGlowColors]"
                        glow-color="152 99 36"
                    >
                    <div class="landing__card">
                    <div class="landing__card-icon" :data-icon="item.icon" aria-hidden="true">
                        <span class="landing__card-icon-chip">
                        <svg v-if="item.icon === 'chat'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5M21 12c0 4.418-4.03 8-9 8a9.77 9.77 0 01-4-.8L3 20l1.8-5.4A7.77 7.77 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                        </svg>
                        <svg v-else-if="item.icon === 'autopilot'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                        <svg v-else-if="item.icon === 'funnel'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 4h18M6 12h12M10 20h4" />
                        </svg>
                        <svg v-else-if="item.icon === 'nudge'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                        </svg>
                        <svg v-else-if="item.icon === 'ai'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.5l-6 3v6l6 3 6-3v-6l-6-3zm0 6l6 3m-6-3v6m6-3v6M14.25 6.5l6 3v6l-6 3" />
                        </svg>
                        <svg v-else-if="item.icon === 'team'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <svg v-else-if="item.icon === 'tasks'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                        </svg>
                        <svg v-else-if="item.icon === 'chart'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                        <svg v-else-if="item.icon === 'database'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
                        </svg>
                        <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        </span>
                    </div>
                    <div class="landing__card-body">
                    <h3 class="landing__card-title">{{ item.title }}</h3>
                    <p class="landing__card-text">{{ item.text }}</p>
                    </div>
                    </div>
                    </LandingBorderGlow>
                </li>
                </ul>
            </section>

            <LandingDataKz
                :eyebrow="t('landing.navDataKz')"
                :map-caption="t('landing.dataKzMapCaption')"
                :title="t('landing.dataKzTitle')"
                :lead="t('landing.dataKzLead')"
                :note="t('landing.dataKzNote')"
                :law-badge="t('landing.dataLawBadge')"
                :law-quote="t('landing.dataLawQuote')"
                :law-fine="t('landing.dataLawFine')"
                :law-link-pd="t('landing.dataLawLinkPd')"
                :law-link-coap="t('landing.dataLawLinkCoap')"
                :data-points="dataPoints"
                :pill-made="t('landing.dataPillMade')"
                :pill-data="t('landing.dataPillData')"
                :pill-support="t('landing.dataPillSupport')"
                :fit-lead="t('landing.dataKzFitLead')"
                :security-heading="t('landing.dataKzSecurityHeading')"
                :security-list="dataKzSecurityList"
            />

            <section id="pricing" class="landing__pricing landing__section-block">
                <header class="landing__section-header landing__section-header--center">
                    <p class="landing__section-eyebrow">{{ t('landing.pricingEyebrow') }}</p>
                    <h2 class="landing__section-heading">{{ t('landing.pricingTitle') }}</h2>
                    <p class="landing__section-lead">{{ t('landing.pricingSectionLead') }}</p>
                </header>
                <div class="landing__pricing-grid">
                    <article
                        v-for="plan in visiblePricingPlans"
                        :key="plan.code"
                        class="landing__pricing-card"
                        :class="{ 'landing__pricing-card--boxed': plan.code === 'boxed' }"
                    >
                        <div class="landing__pricing-head">
                            <p class="landing__pricing-plan">{{ planField(plan.code, 'name') }}</p>
                            <p v-if="plan.code === 'boxed'" class="landing__pricing-badge">
                                {{ planField('boxed', 'badge') }}
                            </p>
                        </div>
                        <p class="landing__pricing-amount">
                            {{ plan.price }}
                            <span class="landing__pricing-period">{{ planField(plan.code, 'period') }}</span>
                        </p>
                        <p class="landing__pricing-trial">{{ planField(plan.code, 'trial') }}</p>
                        <ul class="landing__pricing-list">
                            <li v-for="bullet in planBullets(plan.code)" :key="bullet">{{ bullet }}</li>
                        </ul>
                        <p class="landing__pricing-ai-note">
                            {{ planField(plan.code, 'aiNote') }}
                            <Link href="/calculator" class="landing__pricing-calc-link">{{ t('landing.pricingCalcLink') }}</Link>
                        </p>
                        <button type="button" class="landing__cta-btn landing__pricing-btn" @click="openRequestModal">
                            {{ t('landing.ctaButton') }}
                        </button>
                    </article>
                </div>
            </section>

            <section class="landing__faq-teaser landing__section-block">
                <h2 class="landing__faq-teaser-title">{{ t('landing.faqTeaserTitle') }}</h2>
                <p class="landing__faq-teaser-lead">{{ t('landing.faqTeaserLead') }}</p>
                <Link href="/faq" class="landing__cta-btn">{{ t('landing.faqTeaserLink') }}</Link>
            </section>
        </main>

        <p v-if="flashSuccess" class="landing__toast" role="status">{{ flashSuccess }}</p>

        <LandingSignupRequestModal
            :open="requestModalOpen"
            :root-domain="rootDomain"
            @close="closeRequestModal"
        />

        <footer class="landing__footer">
            <span>© {{ new Date().getFullYear() }} Accel</span>
            <nav class="landing__footer-links">
                <a href="mailto:hello@accel.kz">hello@accel.kz</a>
                <Link href="/faq">{{ t('landing.navFaq') }}</Link>
                <Link href="/calculator">{{ t('landing.calculatorLink') }}</Link>
            </nav>
        </footer>
    </div>
</template>

<style scoped>
.landing {
    position: relative;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    min-height: 100dvh;
    background: var(--landing-bg);
    color: var(--landing-text);
    font-family: Figtree, ui-sans-serif, system-ui, sans-serif;
    -webkit-font-smoothing: antialiased;
}

.landing__hero {
    overflow-x: clip;
}

.landing__main,
.landing__footer {
    position: relative;
    z-index: 1;
}

.landing__main {
    flex: 1;
    width: 100%;
    max-width: 80rem;
    margin: 0 auto;
    padding: 0 clamp(1.5rem, 5vw, 3rem) 3rem;
}

.landing__hero {
    position: relative;
    display: grid;
    gap: 3rem;
    align-items: center;
    margin: 0 0 3.75rem;
}

@media (min-width: 768px) {
    .landing__hero {
        margin: 0 0 8rem;
    }
}

.landing__hero-backdrop {
    position: absolute;
    inset: clamp(-6rem, -8vh, -3rem) clamp(-8rem, -10vw, -2rem) clamp(-10rem, -12vh, -5rem);
    z-index: 0;
    overflow: hidden;
    pointer-events: none;
    background:
        radial-gradient(ellipse 52% 48% at 44% 45%, rgba(1, 185, 100, 0.14) 0%, rgba(1, 185, 100, 0.04) 38%, transparent 58%),
        radial-gradient(ellipse 68% 62% at 50% 50%, rgba(24, 38, 46, 0.65) 0%, rgba(0, 0, 0, 0.92) 100%),
        radial-gradient(ellipse 38% 42% at 68% 50%, rgba(0, 0, 0, 0.28) 0%, transparent 72%);
}

.landing__hero-backdrop::after {
    content: '';
    position: absolute;
    inset: 0;
    z-index: 1;
    pointer-events: none;
    background: radial-gradient(
        ellipse 58% 54% at 50% 50%,
        transparent 36%,
        rgba(0, 0, 0, 0.22) 54%,
        rgba(0, 0, 0, 0.68) 72%,
        rgba(0, 0, 0, 0.94) 88%,
        #000 100%
    );
}

.landing__hero-copy {
    position: relative;
    z-index: 1;
    text-align: center;
}

.landing__hero-actions {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.625rem;
    margin-top: 2rem;
}

.landing__hero-trial {
    margin: 0;
    font-size: 0.9375rem;
    color: var(--landing-accent);
}

.landing__hero-visual {
    position: relative;
    z-index: 1;
    width: 100%;
    min-width: 0;
}

@media (max-width: 768px) {
    .landing__hero {
        padding: 1rem 0 0;
        margin: 0 0 3.75rem;
        gap: 1.5rem;
    }

    .landing__hero-copy {
        padding-top: 0.5rem;
    }

    .landing__title {
        font-size: clamp(1.75rem, 8vw, 2.5rem);
    }

    .landing__tagline {
        font-size: 1rem;
    }

    .landing__hero-visual {
        max-height: 44vh;
        overflow: hidden;
    }

    .landing__cta-btn {
        width: 100%;
        text-align: center;
    }

    .landing__main {
        padding: 0 1rem 2rem;
    }

    .landing__section-heading {
        font-size: clamp(1.5rem, 6vw, 1.85rem);
    }

    .landing__pricing-card {
        padding: 1.25rem 1rem;
        border-radius: var(--landing-radius);
    }

    .landing__pricing-amount {
        font-size: clamp(1.75rem, 8vw, 2.25rem);
    }
}

@media (prefers-reduced-motion: reduce) {
    .landing__hero-backdrop {
        background:
            radial-gradient(ellipse 85% 70% at 58% 45%, rgba(1, 185, 100, 0.16) 0%, rgba(1, 185, 100, 0.05) 45%, transparent 75%),
            radial-gradient(ellipse 100% 100% at 50% 50%, rgba(17, 27, 33, 0.6) 0%, rgba(0, 0, 0, 0.95) 100%);
    }

    .landing__features-item,
    .landing__features-item:hover,
    .landing__pricing-card,
    .landing__pricing-card:hover,
    .landing__cta-btn,
    .landing__cta-btn:hover {
        transform: none;
        transition: none;
    }
}

@media (min-width: 1024px) {
    .landing__hero {
        grid-template-columns: minmax(0, 0.78fr) minmax(0, 1.32fr);
        gap: 2.5rem 4rem;
        min-height: min(82vh, 920px);
        align-items: center;
        padding: 2rem 0;
    }

    .landing__hero-copy {
        text-align: left;
        align-self: center;
    }

    .landing__hero-actions {
        align-items: flex-start;
        margin-top: 2.25rem;
    }
}

.landing__features-section {
    overflow: visible;
}

.landing__title {
    margin: 0 0 1.125rem;
    font-size: clamp(2rem, 5.5vw, 3.35rem);
    font-weight: 600;
    line-height: 1.1;
    letter-spacing: -0.03em;
    color: var(--landing-text);
}

.landing__tagline {
    margin: 0;
    font-size: clamp(1.0625rem, 2.2vw, 1.3125rem);
    line-height: 1.6;
    color: var(--landing-muted);
}

.landing__features {
    margin: 0;
    padding: 0;
    list-style: none;
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1rem;
    overflow: visible;
}

.landing__features-item {
    list-style: none;
    min-height: 0;
    overflow: visible;
    transition: transform 0.22s ease;
}

.landing__features-item:hover {
    transform: translateY(-2px);
}

.landing__card-glow {
    height: 100%;
    overflow: hidden;
}

@media (max-width: 900px) {
    .landing__features {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 560px) {
    .landing__features {
        grid-template-columns: 1fr;
        gap: 0.5rem;
    }

    .landing__card {
        flex-direction: row;
        align-items: flex-start;
        gap: 0.75rem;
        padding: 0.875rem;
    }

    .landing__card-icon {
        flex-shrink: 0;
        margin-top: 0.1rem;
    }

    .landing__card-body {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        min-width: 0;
    }
}

.landing__pricing {
    scroll-margin-top: 5rem;
}

.landing__pricing-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1.5rem;
    max-width: 56rem;
    margin: 0 auto;
    align-items: stretch;
}

.landing__pricing-card {
    position: relative;
    display: flex;
    flex-direction: column;
    min-height: 100%;
    padding: 1.85rem;
    border-radius: var(--landing-radius);
    background: linear-gradient(160deg, var(--landing-card-top), var(--landing-card));
    border: 1px solid var(--landing-border-bright);
    box-shadow: var(--landing-elevation);
    transition:
        transform 0.22s ease,
        box-shadow 0.22s ease,
        border-color 0.22s ease;
}

.landing__pricing-card:hover {
    transform: translateY(-2px);
    border-color: rgba(1, 185, 100, 0.28);
}

.landing__pricing-card--boxed {
    border-color: rgba(45, 212, 191, 0.42);
    box-shadow:
        var(--landing-elevation),
        var(--landing-glow);
}

.landing__pricing-card--boxed:hover {
    box-shadow:
        var(--landing-elevation),
        var(--landing-glow),
        0 28px 56px -24px rgba(1, 185, 100, 0.4);
}

.landing__pricing-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    margin-bottom: 0.5rem;
}

.landing__pricing-badge {
    flex-shrink: 0;
    margin: 0.125rem 0 0;
    padding: 0.25rem 0.5rem;
    font-size: 0.625rem;
    font-weight: 600;
    line-height: 1.2;
    letter-spacing: 0.01em;
    white-space: nowrap;
    color: #04111d;
    background: linear-gradient(135deg, #2dd4bf, #01b964);
    border-radius: 999px;
}

.landing__pricing-plan {
    margin: 0;
    flex: 1;
    min-width: 0;
    font-size: 0.8125rem;
    font-weight: 600;
    line-height: 1.35;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    color: var(--landing-accent);
}

.landing__pricing-amount {
    margin: 0;
    font-size: clamp(2rem, 5vw, 2.5rem);
    font-weight: 700;
    line-height: 1.1;
    letter-spacing: -0.03em;
    color: var(--landing-text);
}

.landing__pricing-period {
    display: block;
    margin-top: 0.25rem;
    font-size: 1rem;
    font-weight: 500;
    color: var(--landing-muted);
}

.landing__pricing-trial {
    margin: 1rem 0 0;
    font-size: 0.9375rem;
    color: var(--landing-accent);
}

.landing__pricing-list {
    margin: 1.25rem 0 0.875rem;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    flex: 1;
}

.landing__pricing-list li {
    position: relative;
    padding-left: 1.25rem;
    font-size: 0.875rem;
    line-height: 1.45;
    color: var(--landing-muted);
}

.landing__pricing-list li::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0.55em;
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 50%;
    background: var(--landing-accent);
}

.landing__pricing-ai-note {
    margin: 0 0 1rem;
    padding: 0.625rem 0.75rem;
    font-size: 0.75rem;
    line-height: 1.4;
    color: var(--landing-muted);
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--landing-border);
    border-radius: 0.5rem;
}

.landing__pricing-calc-link {
    display: inline-block;
    margin-left: 0.375rem;
    color: var(--landing-accent);
    text-decoration: none;
    white-space: nowrap;
}

.landing__pricing-calc-link:hover {
    text-decoration: underline;
}

.landing__pricing-btn {
    width: 100%;
    margin-top: auto;
    flex-shrink: 0;
}

@media (max-width: 768px) {
    .landing__pricing-grid {
        grid-template-columns: 1fr;
    }
}

.landing__card {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 1.15rem 1.2rem;
    height: 100%;
    border-radius: var(--landing-radius);
    overflow: hidden;
    background: linear-gradient(160deg, var(--landing-card-top), var(--landing-card));
}

.landing__card-icon {
    display: flex;
    align-items: center;
    color: var(--landing-accent);
}

.landing__card-icon-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 0.65rem;
    background: rgba(1, 185, 100, 0.14);
    border: 1px solid rgba(1, 185, 100, 0.22);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.05);
}

.landing__card-icon-chip svg {
    width: 1.25rem;
    height: 1.25rem;
}

.landing__card-title {
    margin: 0;
    font-size: 0.875rem;
    font-weight: 600;
    line-height: 1.3;
    color: var(--landing-text);
}

.landing__card-text {
    margin: 0;
    font-size: 0.8125rem;
    line-height: 1.5;
    color: var(--landing-muted);
}
</style>
