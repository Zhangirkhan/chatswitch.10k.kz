<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import UiModal from '@/Components/Ui/UiModal.vue';
import { binDigitsOnly, maskBinInput, maskKzPhoneInput, sanitizeTenantSlugInput } from '@/utils/inputMasks';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';

type SlugStatus = 'idle' | 'checking' | 'available' | 'taken' | 'reserved' | 'invalid' | 'error';

const SLUG_PATTERN = /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/;

const props = defineProps<{
    rootDomain?: string;
}>();

const rootDomain = computed(() => props.rootDomain ?? 'accel.kz');

const page = usePage();
const requestModalOpen = ref(false);

const flashSuccess = computed(() => {
    const flash = page.props.flash as { success?: string } | undefined;
    return flash?.success ?? null;
});

const features = [
    {
        icon: 'chat',
        title: 'Несколько WhatsApp',
        text: 'Все номера и диалоги в одном окне — без переключения между телефонами.',
    },
    {
        icon: 'ai',
        title: 'AI в переписке',
        text: 'Подсказки, автоответы и разбор обращений, чтобы быстрее отвечать клиентам.',
    },
    {
        icon: 'team',
        title: 'Команда и воронки',
        text: 'Задачи, статусы сделок и чаты отделов рядом с WhatsApp.',
    },
];

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
            return 'Проверяем адрес…';
        case 'available':
            return `Адрес ${form.desired_slug}.${rootDomain.value} свободен`;
        case 'taken':
            return 'Этот поддомен уже занят — выберите другой';
        case 'reserved':
            return 'Поддомен зарезервирован системой';
        case 'invalid':
            return 'Только латиница, цифры и дефис (например my-company)';
        case 'error':
            return 'Не удалось проверить адрес — попробуйте ещё раз';
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
    if (window.location.hash === '#request' || Object.keys(form.errors).length > 0) {
        openRequestModal();
        if (window.location.hash === '#request') {
            history.replaceState(null, '', window.location.pathname);
        }
    }
});
</script>

<template>
    <div class="landing">
        <Head title="Accel — WhatsApp для команды" />

        <header class="landing__header landing__header--row">
            <a href="/" class="landing__brand">
                Accel
            </a>
            <nav class="landing__nav">
                <a href="/calculator" class="landing__nav-link">Калькулятор AI</a>
            </nav>
        </header>

        <main class="landing__main">
            <section class="landing__hero">
                <h1 class="landing__title">WhatsApp для всей команды</h1>
                <p class="landing__tagline">
                    Переписка, AI-ассистент и задачи в одной платформе — без хаоса в личных телефонах.
                </p>
            </section>

            <ul class="landing__features">
                <li v-for="item in features" :key="item.title" class="landing__card">
                    <div class="landing__card-icon" :data-icon="item.icon" aria-hidden="true">
                        <svg v-if="item.icon === 'chat'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5M21 12c0 4.418-4.03 8-9 8a9.77 9.77 0 01-4-.8L3 20l1.8-5.4A7.77 7.77 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                        </svg>
                        <svg v-else-if="item.icon === 'ai'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.5l-6 3v6l6 3 6-3v-6l-6-3zm0 6l6 3m-6-3v6m6-3v6M14.25 6.5l6 3v6l-6 3" />
                        </svg>
                        <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </div>
                    <h2 class="landing__card-title">{{ item.title }}</h2>
                    <p class="landing__card-text">{{ item.text }}</p>
                </li>
            </ul>

            <section id="request" class="landing__cta">
                <button type="button" class="landing__cta-btn" @click="openRequestModal">
                    Оставить заявку
                </button>
                <p class="landing__cta-hint">
                    Заявка на проверку — рабочее пространство на {{ rootDomain }} создаётся после одобрения
                </p>
            </section>
        </main>

        <p v-if="flashSuccess" class="landing__toast" role="status">{{ flashSuccess }}</p>

        <UiModal
            :open="requestModalOpen"
            title="Заявка на подключение"
            subtitle="Заявка попадёт в обработку — после одобрения создадим ваш поддомен и пришлём доступ на email"
            max-width="md"
            body-class="px-5 py-4"
            @close="closeRequestModal"
        >
            <form id="landing-request-form" class="landing-modal-form" @submit.prevent="submit">
                <div class="landing-modal-form__field">
                    <label class="landing-modal-form__label" for="company_name">Компания</label>
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
                    <label class="landing-modal-form__label" for="bin">БИН</label>
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
                    <p class="landing-modal-form__hint">12 цифр бизнес-идентификационного номера</p>
                    <InputError :message="form.errors.bin" />
                </div>

                <div class="landing-modal-form__field">
                    <label class="landing-modal-form__label" for="desired_slug">Желаемый поддомен</label>
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
                    <p class="landing-modal-form__hint">Только латиница, цифры и дефис. Будет адрес вида <strong>my-company.{{ rootDomain }}</strong></p>
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
                    <label class="landing-modal-form__label" for="contact_name">Контактное лицо</label>
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
                    <label class="landing-modal-form__label" for="phone">Телефон</label>
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
                        Комментарий <span class="landing-modal-form__optional">необязательно</span>
                    </label>
                    <textarea
                        id="message"
                        v-model="form.message"
                        rows="3"
                        maxlength="2000"
                        class="landing-modal-form__input landing-modal-form__textarea"
                        placeholder="Сколько менеджеров, какие номера WhatsApp…"
                    />
                    <InputError :message="form.errors.message" />
                </div>

                <div class="landing-modal-form__legal">
                    <div class="landing-modal-form__notice" role="note">
                        <p class="landing-modal-form__notice-title">После одобрения заявки</p>
                        <p class="landing-modal-form__notice-text">
                            Мы проверим данные и создадим рабочее пространство на выбранном поддомене. С момента
                            подключения у вас будет <strong>14 календарных дней</strong> пробного периода. По их
                            окончании выставим счёт на оплату подписки; без оплаты доступ может быть ограничен.
                        </p>
                    </div>

                    <div class="landing-modal-form__terms-wrap">
                        <label class="landing-modal-form__terms">
                            <UiCheckbox v-model="form.terms_accepted" size="sm" />
                            <span class="landing-modal-form__terms-text">
                                Согласен с условиями: проверка заявки, создание рабочего пространства после
                                одобрения, пробный период 14&nbsp;дней и выставление счёта
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
                    Отмена
                </button>
                <button
                    type="submit"
                    form="landing-request-form"
                    class="landing-modal-form__btn landing-modal-form__btn--primary"
                    :disabled="!canSubmit"
                >
                    {{ form.processing ? 'Отправка…' : 'Отправить' }}
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
    --landing-bg: #111b21;
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

.landing__nav-link {
    font-size: 0.875rem;
    color: var(--landing-muted);
    text-decoration: none;
}

.landing__nav-link:hover {
    color: var(--landing-accent);
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
    max-width: 68rem;
    margin: 0 auto;
    padding: 0 clamp(1.5rem, 5vw, 3rem) 3rem;
}

.landing__hero {
    max-width: 36rem;
    margin: 0 auto 3rem;
    text-align: center;
}

.landing__title {
    margin: 0 0 1rem;
    font-size: clamp(1.75rem, 4.5vw, 2.5rem);
    font-weight: 600;
    line-height: 1.2;
    letter-spacing: -0.03em;
    color: var(--landing-text);
}

.landing__tagline {
    margin: 0;
    font-size: clamp(1rem, 2vw, 1.125rem);
    line-height: 1.65;
    color: var(--landing-muted);
}

.landing__features {
    margin: 0 0 3rem;
    padding: 0;
    list-style: none;
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1rem;
}

@media (max-width: 800px) {
    .landing__features {
        grid-template-columns: 1fr;
    }
}

.landing__card {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 1.5rem;
    border-radius: 1rem;
    background: var(--landing-surface);
    border: 1px solid var(--landing-border);
    transition: border-color 0.2s ease, transform 0.2s ease;
}

.landing__card:hover {
    border-color: rgba(1, 185, 100, 0.35);
    transform: translateY(-2px);
}

.landing__card-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 0.625rem;
    background: rgba(1, 185, 100, 0.12);
    color: var(--landing-accent);
}

.landing__card-icon svg {
    width: 1.25rem;
    height: 1.25rem;
}

.landing__card-title {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.3;
    color: var(--landing-text);
}

.landing__card-text {
    margin: 0;
    font-size: 0.875rem;
    line-height: 1.6;
    color: var(--landing-muted);
}

.landing__cta {
    scroll-margin-top: 1.5rem;
    text-align: center;
    padding-bottom: 1rem;
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

.landing__cta-hint {
    margin: 0.75rem 0 0;
    font-size: 0.8125rem;
    color: var(--landing-muted);
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
