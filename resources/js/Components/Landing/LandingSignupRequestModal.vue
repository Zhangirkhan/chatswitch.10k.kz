<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import UiModal from '@/Components/Ui/UiModal.vue';
import { useLandingLocale } from '@/composables/useLandingLocale';
import { binDigitsOnly, maskBinInput, maskKzPhoneInput, sanitizeTenantSlugInput } from '@/utils/inputMasks';
import { useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';

type SlugStatus = 'idle' | 'checking' | 'available' | 'taken' | 'reserved' | 'invalid' | 'error';

const SLUG_PATTERN = /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/;

const props = defineProps<{
    open: boolean;
    rootDomain: string;
}>();

const emit = defineEmits<{
    close: [];
}>();

const page = usePage();
const { t } = useLandingLocale();

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

const slugStatus = ref<SlugStatus>('idle');
let slugCheckTimer: ReturnType<typeof setTimeout> | null = null;
let slugCheckAbort: AbortController | null = null;

const canSubmit = computed(() => (
    form.terms_accepted
    && !form.processing
    && form.desired_slug.length > 0
    && slugStatus.value === 'available'
));

const slugStatusMessage = computed(() => {
    switch (slugStatus.value) {
        case 'checking':
            return t('landing.slugChecking');
        case 'available':
            return t('landing.slugAvailable', { slug: form.desired_slug, domain: props.rootDomain });
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

function closeModal(): void {
    emit('close');
    resetSlugCheck();
}

function onPhoneInput(event: Event): void {
    const el = event.target as HTMLInputElement;
    const masked = maskKzPhoneInput(el.value);
    form.phone = masked;
    el.value = masked;
}

function onBinInput(event: Event): void {
    const el = event.target as HTMLInputElement;
    const masked = maskBinInput(el.value);
    form.bin = masked;
    el.value = masked;
}

function onSlugInput(event: Event): void {
    const el = event.target as HTMLInputElement;
    const slug = sanitizeTenantSlugInput(el.value);
    form.desired_slug = slug;
    el.value = slug;
    scheduleSlugCheck(slug);
}

function submit(): void {
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
                closeModal();
            },
        });
}

watch(
    () => page.props.flash as { success?: string } | undefined,
    (flash) => {
        if (flash?.success) {
            closeModal();
        }
    },
);
</script>

<template>
    <UiModal
        :open="open"
        :title="t('landing.requestTitle')"
        :subtitle="t('landing.requestSubtitle')"
        max-width="md"
        body-class="px-5 py-4"
        @close="closeModal"
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
                    :placeholder="t('landing.phonePlaceholder')"
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
            <button type="button" class="landing-modal-form__btn landing-modal-form__btn--ghost" @click="closeModal">
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
</template>
