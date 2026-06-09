<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import LandingHeroMockup from '@/Components/Landing/LandingHeroMockup.vue';
import LandingParticles from '@/Components/Landing/LandingParticles.vue';
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import UiModal from '@/Components/Ui/UiModal.vue';
import { binDigitsOnly, maskBinInput, maskKzPhoneInput, sanitizeTenantSlugInput } from '@/utils/inputMasks';
import { useI18n } from '@/composables/useI18n';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

type SlugStatus = 'idle' | 'checking' | 'available' | 'taken' | 'reserved' | 'invalid' | 'error';

const SLUG_PATTERN = /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/;

const props = defineProps<{
    rootDomain?: string;
}>();

const rootDomain = computed(() => props.rootDomain ?? 'accel.kz');

const page = usePage();
const { t } = useI18n();
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

const pricingBullets = computed(() => [
    t('landing.pricingBullet1'),
    t('landing.pricingBullet2'),
    t('landing.pricingBullet3'),
    t('landing.pricingBullet4'),
    t('landing.pricingBullet5'),
]);

const isMobileViewport = ref(false);

const heroParticleCount = computed(() => (isMobileViewport.value ? 150 : 300));

function syncViewport(): void {
    isMobileViewport.value = window.matchMedia('(max-width: 768px)').matches;
}

const form = useForm({
    company_name: '',
    bin: '',
    desired_slug: '',
    contact_name: '',
    email: '',
    phone: '',
    message: '',
    terms_accepted: false,
});

const canSubmit = computed(() => (
    form.terms_accepted
    && !form.processing
    && form.desired_slug.length > 0
    && slugStatus.value === 'available'
));

const slugStatus = ref<SlugStatus>('idle');
let slugCheckTimer: ReturnType<typeof setTimeout> | null = null;
let slugCheckAbort: AbortController | null = null;

const slugStatusMessage = computed(() => {
    switch (slugStatus.value) {
        case 'checking':
            return t('landing.slugChecking');
        case 'available':
            return t('landing.slugAvailable', { slug: form.desired_slug, domain: rootDomain.value });
        case 'taken':
            return t('landing.slugTaken');
        case 'reserved':
            return t('landing.slugReserved');
        case 'invalid':
            return t('landing.slugInvalid');
        case 'error':
            return t('landing.slugError');
        default:
            return '';
    }
});

const slugFieldClass = computed(() => {
    if (slugStatus.value === 'available') {
        return 'landing-modal-form__slug--available';
    }
    if (['taken', 'reserved', 'invalid', 'error'].includes(slugStatus.value)) {
        return 'landing-modal-form__slug--unavailable';
    }
    return '';
});

function resetSlugCheck(): void {
    if (slugCheckTimer) {
        clearTimeout(slugCheckTimer);
        slugCheckTimer = null;
    }
    slugCheckAbort?.abort();
    slugCheckAbort = null;
    slugStatus.value = 'idle';
}

function scheduleSlugCheck(slug: string): void {
    resetSlugCheck();

    if (!slug) {
        return;
    }

    if (!SLUG_PATTERN.test(slug)) {
        slugStatus.value = 'invalid';
        return;
    }

    slugStatus.value = 'checking';
    slugCheckTimer = setTimeout(() => {
        void checkSlugAvailability(slug);
    }, 400);
}

async function checkSlugAvailability(slug: string): Promise<void> {
    slugCheckAbort = new AbortController();

    try {
        const { data } = await axios.get('/check-tenant-slug', {
            params: { slug },
            signal: slugCheckAbort.signal,
        });

        if (form.desired_slug !== slug) {
            return;
        }

        if (data.available) {
            slugStatus.value = 'available';
            return;
        }

        if (data.reason === 'reserved') {
            slugStatus.value = 'reserved';
            return;
        }

        if (data.reason === 'taken') {
            slugStatus.value = 'taken';
            return;
        }

        slugStatus.value = 'invalid';
    } catch (error: unknown) {
        if (axios.isCancel(error)) {
            return;
        }
        if (form.desired_slug !== slug) {
            return;
        }
        slugStatus.value = 'error';
    }
}

function openRequestModal() {
    requestModalOpen.value = true;
}

function closeRequestModal() {
    requestModalOpen.value = false;
    resetSlugCheck();
}

function onPhoneInput(event: Event) {
    const el = event.target as HTMLInputElement;
    const masked = maskKzPhoneInput(el.value);
    form.phone = masked;
    el.value = masked;
}

function onBinInput(event: Event) {
    const el = event.target as HTMLInputElement;
    const masked = maskBinInput(el.value);
    form.bin = masked;
    el.value = masked;
}

function onSlugInput(event: Event) {
    const el = event.target as HTMLInputElement;
    const slug = sanitizeTenantSlugInput(el.value);
    form.desired_slug = slug;
    el.value = slug;
    scheduleSlugCheck(slug);
}

function submit() {
    form
        .transform((data) => ({
            ...data,
            bin: binDigitsOnly(data.bin),
        }))
        .post('/signup-request', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                resetSlugCheck();
                closeRequestModal();
            },
        });
}

watch(flashSuccess, (msg) => {
    if (msg) {
        closeRequestModal();
    }
});

onMounted(() => {
    syncViewport();
    window.addEventListener('resize', syncViewport, { passive: true });

    if (window.location.hash === '#request' || Object.keys(form.errors).length > 0) {
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
        <Head :title="t('landing.pageTitle')" />

        <header class="landing__header landing__header--row">
            <a href="/" class="landing__brand">
                Accel
            </a>
            <nav class="landing__nav">
                <a href="#features" class="landing__nav-link">{{ t('landing.navFeatures') }}</a>
                <a href="#pricing" class="landing__nav-link">{{ t('landing.navPricing') }}</a>
                <button type="button" class="landing__header-cta" @click="openRequestModal">
                    {{ t('landing.ctaButton') }}
                </button>
            </nav>
        </header>

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
                    <LandingHeroMockup />
                </div>
            </section>

            <section id="features" class="landing__features-section">
                <h2 class="landing__section-title">{{ t('landing.featuresSectionTitle') }}</h2>
                <ul class="landing__features">
                <li v-for="item in features" :key="item.title" class="landing__card">
                    <div class="landing__card-icon" :data-icon="item.icon" aria-hidden="true">
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
                    </div>
                    <h3 class="landing__card-title">{{ item.title }}</h3>
                    <p class="landing__card-text">{{ item.text }}</p>
                </li>
                </ul>
            </section>

            <section id="pricing" class="landing__pricing">
                <h2 class="landing__section-title">{{ t('landing.pricingTitle') }}</h2>
                <div class="landing__pricing-card">
                    <p class="landing__pricing-plan">{{ t('landing.pricingPlanName') }}</p>
                    <p class="landing__pricing-amount">
                        {{ t('landing.pricingAmount') }}
                        <span class="landing__pricing-period">{{ t('landing.pricingPeriod') }}</span>
                    </p>
                    <p class="landing__pricing-trial">{{ t('landing.pricingTrial') }}</p>
                    <ul class="landing__pricing-list">
                        <li v-for="bullet in pricingBullets" :key="bullet">{{ bullet }}</li>
                    </ul>
                    <button type="button" class="landing__cta-btn landing__pricing-btn" @click="openRequestModal">
                        {{ t('landing.ctaButton') }}
                    </button>
                </div>
            </section>
        </main>

        <p v-if="flashSuccess" class="landing__toast" role="status">{{ flashSuccess }}</p>

        <UiModal
            :open="requestModalOpen"
            :title="t('landing.requestTitle')"
            :subtitle="t('landing.requestSubtitle')"
            max-width="md"
            body-class="px-5 py-4"
            @close="closeRequestModal"
        >
            <form id="landing-request-form" class="landing-modal-form" @submit.prevent="submit">
                <div class="landing-modal-form__field">
                    <label class="landing-modal-form__label" for="company_name">{{ t('landing.company') }}</label>
                    <input
                        id="company_name"
                        v-model="form.company_name"
                        type="text"
                        required
                        maxlength="160"
                        class="landing-modal-form__input"
                        autocomplete="organization"
                    />
                    <InputError :message="form.errors.company_name" />
                </div>

                <div class="landing-modal-form__field">
                    <label class="landing-modal-form__label" for="bin">{{ t('landing.bin') }}</label>
                    <input
                        id="bin"
                        :value="form.bin"
                        type="text"
                        required
                        class="landing-modal-form__input"
                        placeholder="1234 5678 9012"
                        inputmode="numeric"
                        autocomplete="off"
                        maxlength="14"
                        @input="onBinInput"
                    />
                    <p class="landing-modal-form__hint">{{ t('landing.binHint') }}</p>
                    <InputError :message="form.errors.bin" />
                </div>

                <div class="landing-modal-form__field">
                    <label class="landing-modal-form__label" for="desired_slug">{{ t('landing.subdomain') }}</label>
                    <div class="landing-modal-form__slug" :class="slugFieldClass">
                        <input
                            id="desired_slug"
                            :value="form.desired_slug"
                            type="text"
                            required
                            maxlength="32"
                            class="landing-modal-form__input landing-modal-form__input--slug"
                            placeholder="my-company"
                            autocomplete="off"
                            spellcheck="false"
                            :aria-invalid="['taken', 'reserved', 'invalid', 'error'].includes(slugStatus)"
                            @input="onSlugInput"
                        />
                        <span class="landing-modal-form__slug-suffix">.{{ rootDomain }}</span>
                    </div>
                    <p class="landing-modal-form__hint">{{ t('landing.subdomainHint', { domain: rootDomain }) }}</p>
                    <p
                        v-if="slugStatusMessage"
                        class="landing-modal-form__slug-status"
                        :class="{
                            'landing-modal-form__slug-status--ok': slugStatus === 'available',
                            'landing-modal-form__slug-status--bad': ['taken', 'reserved', 'invalid', 'error'].includes(slugStatus),
                            'landing-modal-form__slug-status--pending': slugStatus === 'checking',
                        }"
                        role="status"
                    >
                        {{ slugStatusMessage }}
                    </p>
                    <InputError :message="form.errors.desired_slug" />
                </div>

                <div class="landing-modal-form__field">
                    <label class="landing-modal-form__label" for="contact_name">{{ t('landing.contactName') }}</label>
                    <input
                        id="contact_name"
                        v-model="form.contact_name"
                        type="text"
                        required
                        maxlength="120"
                        class="landing-modal-form__input"
                        autocomplete="name"
                    />
                    <InputError :message="form.errors.contact_name" />
                </div>

                <div class="landing-modal-form__field">
                    <label class="landing-modal-form__label" for="email">Email</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
                        maxlength="160"
                        class="landing-modal-form__input"
                        autocomplete="email"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="landing-modal-form__field">
                    <label class="landing-modal-form__label" for="phone">{{ t('landing.phone') }}</label>
                    <input
                        id="phone"
                        :value="form.phone"
                        type="tel"
                        required
                        class="landing-modal-form__input"
                        placeholder="+7 (___) ___-__-__"
                        autocomplete="tel"
                        inputmode="tel"
                        @input="onPhoneInput"
                    />
                    <InputError :message="form.errors.phone" />
                </div>

                <div class="landing-modal-form__field">
                    <label class="landing-modal-form__label" for="message">
                        {{ t('landing.comment') }} <span class="landing-modal-form__optional">{{ t('landing.optional') }}</span>
                    </label>
                    <textarea
                        id="message"
                        v-model="form.message"
                        rows="3"
                        maxlength="2000"
                        class="landing-modal-form__input landing-modal-form__textarea"
                        :placeholder="t('landing.commentPlaceholder')"
                    />
                    <InputError :message="form.errors.message" />
                </div>

                <div class="landing-modal-form__legal">
                    <div class="landing-modal-form__notice" role="note">
                        <p class="landing-modal-form__notice-title">{{ t('landing.afterApprovalTitle') }}</p>
                        <p class="landing-modal-form__notice-text">
                            {{ t('landing.afterApprovalText', { trialDays: t('landing.trialDaysBold') }) }}
                        </p>
                    </div>

                    <div class="landing-modal-form__terms-wrap">
                        <label class="landing-modal-form__terms">
                            <UiCheckbox v-model="form.terms_accepted" size="sm" />
                            <span class="landing-modal-form__terms-text">
                                {{ t('landing.termsText') }}
                            </span>
                        </label>
                        <InputError
                            class="landing-modal-form__terms-error"
                            :message="form.errors.terms_accepted"
                        />
                    </div>
                </div>
            </form>

            <template #footer>
                <button type="button" class="landing-modal-form__btn landing-modal-form__btn--ghost" @click="closeRequestModal">
                    {{ t('landing.cancel') }}
                </button>
                <button
                    type="submit"
                    form="landing-request-form"
                    class="landing-modal-form__btn landing-modal-form__btn--primary"
                    :disabled="!canSubmit"
                >
                    {{ form.processing ? t('landing.submitting') : t('landing.submit') }}
                </button>
            </template>
        </UiModal>

        <footer class="landing__footer">
            <span>© {{ new Date().getFullYear() }} Accel</span>
            <a href="mailto:hello@accel.kz">hello@accel.kz</a>
        </footer>
    </div>
</template>

<style scoped>
.landing {
    --landing-bg: #000;
    --landing-surface: #1d1f1f;
    --landing-surface-raised: #232626;
    --landing-border: rgba(134, 150, 160, 0.18);
    --landing-text: #e9edef;
    --landing-muted: #8696a0;
    --landing-accent: #01b964;
    --landing-accent-hover: #06d670;

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

.landing__header,
.landing__main,
.landing__footer {
    position: relative;
    z-index: 1;
}

.landing__header {
    padding: 1.5rem clamp(1.5rem, 5vw, 3rem);
}

.landing__header--row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.landing__nav {
    display: flex;
    align-items: center;
    gap: 1.25rem;
}

.landing__nav-link {
    font-size: 0.875rem;
    color: var(--landing-muted);
    text-decoration: none;
}

.landing__nav-link:hover {
    color: var(--landing-accent);
}

.landing__header-cta {
    padding: 0.5rem 1.125rem;
    font-size: 0.8125rem;
    font-weight: 600;
    font-family: inherit;
    color: #fff;
    background: var(--landing-accent);
    border: none;
    border-radius: 999px;
    cursor: pointer;
    white-space: nowrap;
    transition: background 0.15s ease, transform 0.15s ease;
}

.landing__header-cta:hover {
    background: var(--landing-accent-hover);
    transform: translateY(-1px);
}

@media (max-width: 640px) {
    .landing__header--row {
        flex-wrap: wrap;
    }

    .landing__nav {
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 0.75rem 1rem;
    }

    .landing__header-cta {
        width: 100%;
        padding-top: 0.625rem;
        padding-bottom: 0.625rem;
    }
}

.landing__brand {
    display: inline-flex;
    align-items: center;
    gap: 0.625rem;
    font-size: 1.0625rem;
    font-weight: 600;
    color: var(--landing-text);
    text-decoration: none;
    letter-spacing: -0.02em;
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
    margin: 0 0 5rem;
    overflow: hidden;
    border-radius: 1.75rem;
}

.landing__hero-backdrop {
    position: absolute;
    inset: 0;
    z-index: 0;
    overflow: hidden;
    border-radius: inherit;
    background:
        radial-gradient(ellipse 85% 70% at 58% 45%, rgba(1, 185, 100, 0.14) 0%, rgba(1, 185, 100, 0.04) 42%, transparent 72%),
        radial-gradient(ellipse 100% 100% at 50% 50%, rgba(17, 27, 33, 0.55) 0%, rgba(0, 0, 0, 0.92) 100%);
    box-shadow:
        inset 0 0 0 1px rgba(1, 185, 100, 0.08),
        inset 0 0 80px rgba(0, 0, 0, 0.65);
}

.landing__hero-backdrop::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    pointer-events: none;
    background: radial-gradient(ellipse at center, transparent 30%, rgba(0, 0, 0, 0.5) 100%);
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
        border-radius: 1.25rem;
        padding: 1.5rem 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .landing__hero-backdrop {
        background:
            radial-gradient(ellipse 85% 70% at 58% 45%, rgba(1, 185, 100, 0.16) 0%, rgba(1, 185, 100, 0.05) 45%, transparent 75%),
            radial-gradient(ellipse 100% 100% at 50% 50%, rgba(17, 27, 33, 0.6) 0%, rgba(0, 0, 0, 0.95) 100%);
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

.landing__section-title {
    margin: 0 0 1.25rem;
    font-size: clamp(1.125rem, 2vw, 1.35rem);
    font-weight: 600;
    line-height: 1.3;
    letter-spacing: -0.02em;
    text-align: center;
    color: var(--landing-text);
}

.landing__features-section {
    scroll-margin-top: 1.5rem;
    margin-bottom: 3rem;
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
    gap: 0.75rem;
}

@media (max-width: 900px) {
    .landing__features {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 560px) {
    .landing__features {
        grid-template-columns: 1fr;
    }
}

.landing__pricing {
    scroll-margin-top: 1.5rem;
    margin-bottom: 3rem;
}

.landing__pricing-card {
    max-width: 28rem;
    margin: 0 auto;
    padding: 2rem;
    border-radius: 1.25rem;
    background: var(--landing-surface);
    border: 1px solid rgba(1, 185, 100, 0.28);
    box-shadow: 0 24px 48px rgba(0, 0, 0, 0.22);
}

.landing__pricing-plan {
    margin: 0 0 0.5rem;
    font-size: 0.875rem;
    font-weight: 600;
    letter-spacing: 0.04em;
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
    margin: 1.5rem 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
}

.landing__pricing-list li {
    position: relative;
    padding-left: 1.375rem;
    font-size: 0.9375rem;
    line-height: 1.5;
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

.landing__pricing-btn {
    width: 100%;
}

.landing__card {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.5rem;
    padding: 0.875rem 1rem;
    border-radius: 0.75rem;
    background: var(--landing-surface);
    border: 1px solid var(--landing-border);
    transition: border-color 0.2s ease, transform 0.2s ease;
}

.landing__card:hover {
    border-color: rgba(1, 185, 100, 0.35);
    transform: translateY(-1px);
}

.landing__card-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border-radius: 0.5rem;
    background: rgba(1, 185, 100, 0.12);
    color: var(--landing-accent);
}

.landing__card-icon svg {
    width: 1rem;
    height: 1rem;
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

.landing__cta-btn {
    padding: 0.875rem 2rem;
    font-size: 1rem;
    font-weight: 600;
    font-family: inherit;
    color: #fff;
    background: var(--landing-accent);
    border: none;
    border-radius: 999px;
    cursor: pointer;
    transition: background 0.15s ease, transform 0.15s ease;
}

.landing__cta-btn:hover {
    background: var(--landing-accent-hover);
    transform: translateY(-1px);
}

.landing__toast {
    position: fixed;
    bottom: 1.25rem;
    left: 50%;
    z-index: 1300;
    max-width: min(24rem, calc(100vw - 2rem));
    padding: 0.75rem 1rem;
    font-size: 0.875rem;
    text-align: center;
    color: var(--landing-accent);
    background: var(--landing-surface);
    border: 1px solid rgba(1, 185, 100, 0.35);
    border-radius: 0.5rem;
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.35);
    transform: translateX(-50%);
}

.landing-modal-form {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.landing-modal-form__field {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.landing-modal-form__label {
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--wa-text-secondary, #8696a0);
}

.landing-modal-form__optional {
    font-weight: 400;
    opacity: 0.8;
}

.landing-modal-form__hint {
    margin: 0;
    font-size: 0.75rem;
    line-height: 1.45;
    color: var(--wa-text-secondary, #8696a0);
}

.landing-modal-form__hint strong {
    font-weight: 600;
    color: var(--wa-text, #e9edef);
}

.landing-modal-form__slug {
    display: flex;
    align-items: stretch;
    border-radius: 0.5rem;
    border: 1px solid var(--wa-border-strong, rgba(134, 150, 160, 0.22));
    overflow: hidden;
    background: var(--wa-panel-input, #1a1f1f);
}

.landing-modal-form__slug:focus-within {
    border-color: rgba(1, 185, 100, 0.55);
    box-shadow: 0 0 0 2px rgba(1, 185, 100, 0.12);
}

.landing-modal-form__input--slug {
    border: none !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    flex: 1;
    min-width: 0;
}

.landing-modal-form__slug--available {
    border-color: rgba(1, 185, 100, 0.55);
}

.landing-modal-form__slug--unavailable {
    border-color: rgba(234, 112, 112, 0.55);
}

.landing-modal-form__slug-status {
    margin: 0.35rem 0 0;
    font-size: 0.8125rem;
    line-height: 1.4;
}

.landing-modal-form__slug-status--ok {
    color: #01b964;
}

.landing-modal-form__slug-status--bad {
    color: #ea7070;
}

.landing-modal-form__slug-status--pending {
    color: var(--wa-text-secondary, #8696a0);
}

.landing-modal-form__slug-suffix {
    display: flex;
    align-items: center;
    padding: 0 0.75rem;
    font-size: 0.875rem;
    white-space: nowrap;
    color: var(--wa-text-secondary, #8696a0);
    background: color-mix(in srgb, #ffffff 4%, var(--wa-panel-header, #1d1f1f));
    border-left: 1px solid var(--wa-border, rgba(134, 150, 160, 0.13));
}

.landing-modal-form__input {
    width: 100%;
    padding: 0.7rem 0.8rem;
    font-size: 0.9375rem;
    font-family: inherit;
    color: var(--wa-text, #e9edef);
    background: var(--wa-panel-input, #1a1f1f);
    border: 1px solid var(--wa-border-strong, rgba(134, 150, 160, 0.22));
    border-radius: 0.5rem;
    outline: none;
}

.landing-modal-form__input:focus {
    border-color: rgba(1, 185, 100, 0.55);
}

.landing-modal-form__textarea {
    resize: vertical;
    min-height: 4rem;
}

.landing-modal-form__field :deep(p) {
    margin: 0;
    font-size: 0.75rem;
    color: #ea7070;
}

.landing-modal-form__btn {
    padding: 0.6rem 1.25rem;
    font-size: 0.875rem;
    font-weight: 600;
    font-family: inherit;
    border-radius: 0.5rem;
    cursor: pointer;
    border: none;
    transition: opacity 0.15s ease;
}

.landing-modal-form__btn--ghost {
    color: var(--wa-text-secondary, #8696a0);
    background: transparent;
    border: 1px solid var(--wa-border-strong, rgba(134, 150, 160, 0.22));
}

.landing-modal-form__btn--primary {
    color: #fff;
    background: var(--landing-accent, #01b964);
}

.landing-modal-form__btn--primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.landing-modal-form__legal {
    display: flex;
    flex-direction: column;
    gap: 0.875rem;
    margin-top: 0.125rem;
}

.landing-modal-form__notice {
    padding: 1rem 1.125rem;
    border-radius: 0.625rem;
    background: rgba(1, 185, 100, 0.07);
    border: 1px solid rgba(1, 185, 100, 0.2);
    border-left: 3px solid rgba(1, 185, 100, 0.55);
}

.landing-modal-form__notice-title {
    margin: 0 0 0.5rem;
    font-size: 0.8125rem;
    font-weight: 600;
    line-height: 1.35;
    color: var(--wa-text, #e9edef);
}

.landing-modal-form__notice-text {
    margin: 0;
    font-size: 0.75rem;
    line-height: 1.6;
    color: var(--wa-text-secondary, #8696a0);
}

.landing-modal-form__notice-text strong {
    color: var(--wa-text, #e9edef);
    font-weight: 600;
}

.landing-modal-form__terms-wrap {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
}

.landing-modal-form__terms {
    display: grid;
    grid-template-columns: 0.9375rem minmax(0, 1fr);
    column-gap: 0.625rem;
    align-items: start;
    font-size: 0.8125rem;
    line-height: 1.5;
    color: var(--wa-text-secondary, #8696a0);
    cursor: pointer;
}

.landing-modal-form__terms :deep(.ui-checkbox) {
    margin-top: 0.125rem;
}

.landing-modal-form__terms-text {
    min-width: 0;
}

.landing-modal-form__terms-error {
    margin: 0;
    padding-left: calc(0.9375rem + 0.625rem);
}

.landing__footer {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.25rem clamp(1.5rem, 5vw, 3rem);
    font-size: 0.8125rem;
    color: #667781;
}

.landing__footer a {
    color: var(--landing-muted);
    text-decoration: none;
}

.landing__footer a:hover {
    color: var(--landing-accent);
}
</style>
