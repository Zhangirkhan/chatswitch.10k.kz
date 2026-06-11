<script setup lang="ts">
import LandingHead from '@/Components/Landing/LandingHead.vue';
import LandingHeader from '@/Components/Landing/LandingHeader.vue';
import LandingSignupRequestModal from '@/Components/Landing/LandingSignupRequestModal.vue';
import { useLandingLocale } from '@/composables/useLandingLocale';
import { messagesForLocale } from '@/i18n/messages';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    rootDomain?: string;
    androidApkUrl?: string;
}>();

const rootDomain = computed(() => props.rootDomain ?? 'accel.kz');
const apkDownloadUrl = computed(() => props.androidApkUrl ?? '/apk/app-release.apk');

const page = usePage();
const { t, locale } = useLandingLocale();
const requestModalOpen = ref(false);

const faqCategories = computed(() => messagesForLocale(locale.value).landing.faqCategories ?? []);

const flashSuccess = computed(() => {
    const flash = page.props.flash as { success?: string } | undefined;
    return flash?.success ?? null;
});

function openRequestModal(): void {
    requestModalOpen.value = true;
}

function closeRequestModal(): void {
    requestModalOpen.value = false;
}
</script>

<template>
    <div class="landing">
        <LandingHead page="faq" />

        <LandingHeader
            anchor-base="/"
            :android-apk-url="apkDownloadUrl"
            @request="openRequestModal"
        />

        <main class="landing__main">
            <section class="landing__faq-page landing__section-block">
                <header class="landing__section-header landing__section-header--center">
                    <p class="landing__section-eyebrow">{{ t('landing.faqEyebrow') }}</p>
                    <h1 class="landing__section-heading">{{ t('landing.faqTitle') }}</h1>
                    <p class="landing__section-lead">{{ t('landing.faqSectionLead') }}</p>
                </header>

                <section
                    v-for="category in faqCategories"
                    :key="category.id"
                    class="landing__faq-category"
                    :aria-labelledby="`faq-${category.id}`"
                >
                    <h2 :id="`faq-${category.id}`" class="landing__faq-category-title">
                        {{ category.title }}
                    </h2>
                    <dl class="landing__faq-list">
                        <div
                            v-for="(item, index) in category.items"
                            :key="`${category.id}-${index}`"
                            class="landing__faq-item"
                        >
                            <dt class="landing__faq-question">{{ item.question }}</dt>
                            <dd class="landing__faq-answer">{{ item.answer }}</dd>
                        </div>
                    </dl>
                </section>
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
                <Link href="/">{{ t('landing.backHome') }}</Link>
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

.landing__main {
    flex: 1;
    width: 100%;
    max-width: 80rem;
    margin: 0 auto;
    padding: 0 clamp(1.5rem, 5vw, 3rem) 3rem;
}

@media (max-width: 767px) {
    .landing__main {
        padding: 0 1rem 2rem;
    }
}
</style>
