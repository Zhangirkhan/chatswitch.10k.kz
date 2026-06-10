<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import SectionHeader from './SectionHeader.vue';
import { useI18n } from '@/composables/useI18n';
import { useForm, router, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, ref } from 'vue';

type FeedbackItem = {
    id: number;
    type: 'complaint' | 'suggestion';
    message: string;
    status: 'new' | 'read' | 'resolved';
    source: 'web' | 'mobile';
    created_at: string | null;
};

const props = defineProps<{
    items: FeedbackItem[];
}>();

const { t } = useI18n();
const page = usePage();

const flashSuccess = computed(() => {
    const flash = page.props.flash as { success?: string } | undefined;
    return flash?.success ?? '';
});

const showSuccess = ref(false);
let successTimer: ReturnType<typeof setTimeout> | null = null;

const successMessage = computed(() => {
    if (showSuccess.value) {
        return t('profile.contactSection.submitSuccess');
    }
    return flashSuccess.value;
});

const form = useForm({
    type: 'suggestion' as 'complaint' | 'suggestion',
    message: '',
});

function submit(): void {
    form.post(route('profile.feedback.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('message');
            showSuccess.value = true;
            if (successTimer !== null) {
                clearTimeout(successTimer);
            }
            successTimer = setTimeout(() => {
                showSuccess.value = false;
                successTimer = null;
            }, 6000);
            router.reload({ only: ['feedbackItems'] });
        },
    });
}

onUnmounted(() => {
    if (successTimer !== null) {
        clearTimeout(successTimer);
    }
});

function typeLabel(type: FeedbackItem['type']): string {
    return type === 'complaint'
        ? t('profile.contactSection.typeComplaint')
        : t('profile.contactSection.typeSuggestion');
}

function statusLabel(status: FeedbackItem['status']): string {
    if (status === 'read') {
        return t('profile.contactSection.statusRead');
    }
    if (status === 'resolved') {
        return t('profile.contactSection.statusResolved');
    }
    return t('profile.contactSection.statusNew');
}

function sourceLabel(source: FeedbackItem['source']): string {
    return source === 'mobile'
        ? t('profile.contactSection.sourceMobile')
        : t('profile.contactSection.sourceWeb');
}

function formatDate(value: string | null): string {
    if (!value) {
        return '';
    }
    return new Intl.DateTimeFormat(undefined, {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}
</script>

<template>
    <div class="h-full flex flex-col">
        <SectionHeader :title="t('profile.contactSection.title')" />

        <div class="flex-1 overflow-y-auto wa-scrollbar px-6 py-4 space-y-6">
            <p class="text-sm text-[var(--wa-text-secondary)] leading-relaxed">
                {{ t('profile.contactSection.intro') }}
            </p>

            <p v-if="successMessage" class="text-sm text-[var(--wa-accent)]" role="status">
                {{ successMessage }}
            </p>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="contact-type-btn"
                        :class="{ 'contact-type-btn--active': form.type === 'complaint' }"
                        @click="form.type = 'complaint'"
                    >
                        {{ t('profile.contactSection.typeComplaint') }}
                    </button>
                    <button
                        type="button"
                        class="contact-type-btn"
                        :class="{ 'contact-type-btn--active': form.type === 'suggestion' }"
                        @click="form.type = 'suggestion'"
                    >
                        {{ t('profile.contactSection.typeSuggestion') }}
                    </button>
                </div>

                <div>
                    <label class="block mb-2 text-sm text-[var(--wa-text-secondary)]">
                        {{ t('profile.contactSection.messageLabel') }}
                    </label>
                    <textarea
                        v-model="form.message"
                        rows="6"
                        class="contact-textarea"
                        :placeholder="t('profile.contactSection.messagePlaceholder')"
                    />
                    <InputError class="mt-2" :message="form.errors.message" />
                    <InputError class="mt-2" :message="form.errors.type" />
                </div>

                <button
                    type="submit"
                    class="ui-btn ui-btn--primary"
                    :disabled="form.processing"
                >
                    {{ form.processing ? t('profile.contactSection.submitting') : t('profile.contactSection.submit') }}
                </button>
            </form>

            <section class="space-y-3">
                <h3 class="text-sm font-medium text-[var(--wa-text)]">
                    {{ t('profile.contactSection.historyTitle') }}
                </h3>

                <p v-if="props.items.length === 0" class="text-sm text-[var(--wa-text-secondary)]">
                    {{ t('profile.contactSection.historyEmpty') }}
                </p>

                <ul v-else class="space-y-2">
                    <li
                        v-for="item in props.items"
                        :key="item.id"
                        class="contact-history-item"
                    >
                        <div class="flex flex-wrap items-center gap-2 mb-1">
                            <span class="contact-badge">{{ typeLabel(item.type) }}</span>
                            <span class="contact-badge contact-badge--muted">{{ statusLabel(item.status) }}</span>
                            <span class="contact-badge contact-badge--muted">{{ sourceLabel(item.source) }}</span>
                            <span class="text-xs text-[var(--wa-text-secondary)] ml-auto">
                                {{ formatDate(item.created_at) }}
                            </span>
                        </div>
                        <p class="text-sm text-[var(--wa-text)] whitespace-pre-wrap break-words">
                            {{ item.message }}
                        </p>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>

<style scoped>
.contact-type-btn {
    padding: 0.5rem 0.875rem;
    border-radius: 999px;
    border: 1px solid var(--wa-border);
    background: transparent;
    color: var(--wa-text-secondary);
    font-size: 0.875rem;
    transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}

.contact-type-btn--active {
    background: color-mix(in srgb, var(--wa-accent) 16%, transparent);
    border-color: color-mix(in srgb, var(--wa-accent) 45%, var(--wa-border));
    color: var(--wa-text);
}

.contact-textarea {
    width: 100%;
    resize: vertical;
    min-height: 8rem;
    padding: 0.75rem 0.875rem;
    border-radius: 0.75rem;
    border: 1px solid var(--wa-border);
    background: var(--wa-panel-header);
    color: var(--wa-text);
    font: inherit;
}

.contact-textarea:focus {
    outline: 2px solid color-mix(in srgb, var(--wa-accent) 45%, transparent);
    outline-offset: 1px;
}

.contact-history-item {
    padding: 0.875rem 1rem;
    border-radius: 0.75rem;
    border: 1px solid var(--wa-border);
    background: var(--wa-panel-header);
}

.contact-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.125rem 0.5rem;
    border-radius: 999px;
    font-size: 0.75rem;
    background: color-mix(in srgb, var(--wa-accent) 14%, transparent);
    color: var(--wa-text);
}

.contact-badge--muted {
    background: color-mix(in srgb, var(--wa-text-secondary) 12%, transparent);
    color: var(--wa-text-secondary);
}
</style>
