<script setup lang="ts">
import Avatar from '@/Components/Avatar.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import type { Contact } from '@/types';
import { formatPhone } from '@/utils/phone';

const props = defineProps<{
    contacts: Contact[];
    search?: string;
}>();

const { t } = useI18n();

const q = ref((props.search || '').toString());
const savingId = ref<number | null>(null);
const error = ref<string | null>(null);
const local = ref<Contact[]>([...(props.contacts || [])]);

watch(
    () => props.contacts,
    (val) => {
        local.value = [...(val || [])];
    },
);

let debounceTimer: ReturnType<typeof setTimeout> | null = null;
watch(q, (val) => {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }
    debounceTimer = setTimeout(() => {
        router.get(route('contacts.index'), { search: val || undefined }, { preserveState: true, preserveScroll: true, replace: true });
    }, 250);
});

const rows = computed(() => local.value);

function label(c: Contact): string {
    return c.name || c.push_name || formatPhone(c.phone_number) || '';
}

async function saveName(c: Contact, name: string): Promise<void> {
    if (savingId.value) {
        return;
    }
    error.value = null;
    savingId.value = c.id;
    try {
        const { data } = await axios.patch(route('contacts.update', c.id), { name });
        const updated = (data?.contact || null) as Contact | null;
        if (updated) {
            const idx = local.value.findIndex((x) => x.id === c.id);
            if (idx >= 0) {
                local.value[idx] = { ...local.value[idx], ...updated };
            }
        }
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string; error?: string } } };
        error.value = err?.response?.data?.message || err?.response?.data?.error || t('misc.contactsSaveError');
    } finally {
        savingId.value = null;
    }
}
</script>

<template>
    <Head :title="t('misc.contactsTitle')" />
    <AuthenticatedLayout>
        <div class="ui-tool-list-page">
            <header class="ui-tool-list-page__header">
                <div class="text-base font-medium text-[var(--wa-text)]">{{ t('misc.contactsTitle') }}</div>
                <div class="ui-tool-list-page__search">
                    <svg class="ui-tool-list-page__search-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <input
                        v-model="q"
                        type="search"
                        :placeholder="t('misc.contactsSearch')"
                        class="ui-tool-list-page__search-input"
                    />
                </div>
            </header>

            <div class="flex-1 overflow-y-auto wa-scrollbar">
                <div v-if="error" class="ui-alert ui-alert--danger mx-5 mt-3">
                    {{ error }}
                </div>

                <div class="px-5 py-4">
                    <div class="mb-3 text-xs text-[var(--wa-text-secondary)]">
                        {{ rows.length }}
                    </div>

                    <div v-if="rows.length === 0" class="ui-empty-state ui-empty-state--dashed mx-auto max-w-md">
                        <Avatar :size="72" variant="neutral" class="mx-auto mb-3" />
                        <p class="text-sm font-medium text-[var(--wa-text)] m-0">
                            {{ q.trim() ? 'Ничего не найдено' : 'Контактов пока нет' }}
                        </p>
                        <p class="text-xs text-[var(--wa-text-secondary)] mt-2 mb-0">
                            {{ q.trim()
                                ? 'Попробуйте другой запрос или очистите поиск.'
                                : 'Новые контакты появятся после первых сообщений в WhatsApp.' }}
                        </p>
                        <Link
                            v-if="!q.trim()"
                            :href="route('settings.connections')"
                            class="ui-empty-state__action mt-4 inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-medium text-white"
                            :style="{ background: 'var(--wa-accent)' }"
                        >
                            {{ t('misc.channelsConnectWhatsapp') }}
                        </Link>
                    </div>

                    <div v-else class="grid grid-cols-1 gap-2">
                        <div
                            v-for="c in rows"
                            :key="c.id"
                            class="ui-result-card"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex items-start gap-3 min-w-0">
                                    <Avatar
                                        :name="label(c)"
                                        :avatar-url="c.profile_picture_url"
                                        :size="44"
                                        variant="neutral"
                                        fallback-initials
                                        class="shrink-0"
                                    />
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-medium text-[var(--wa-text)]">
                                            {{ label(c) || t('clients.noName') }}
                                        </div>
                                        <div class="mt-1 text-xs text-[var(--wa-text-secondary)]">
                                            {{ formatPhone(c.phone_number) || '' }}
                                        </div>
                                        <div v-if="c.push_name && (!c.name || c.push_name !== c.name)" class="mt-1 text-xs text-[var(--wa-text-secondary)]">
                                            WhatsApp: {{ c.push_name }}
                                        </div>
                                    </div>
                                </div>

                                <div class="w-[260px] shrink-0">
                                    <label class="mb-1 block text-[11px] text-[var(--wa-text-secondary)]">{{ t('clients.detail.savedName') }}</label>
                                    <input
                                        :value="c.name || ''"
                                        class="ui-search-input--boxed w-full"
                                        @change="saveName(c, ($event.target as HTMLInputElement).value)"
                                    />
                                    <div v-if="savingId === c.id" class="mt-1 text-[11px] text-[var(--wa-text-secondary)]">
                                        {{ t('common.wait') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
