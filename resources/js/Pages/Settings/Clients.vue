<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import ContactCardSkeleton from '@/Components/Contact/ContactCardSkeleton.vue';
import ContactCrmSections, { type ContactCrmPayload } from '@/Components/Contact/ContactCrmSections.vue';
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import { useI18n } from '@/composables/useI18n';
import { Head, router, Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { formatPhone } from '@/utils/phone';
import { useToastStore } from '@/stores/toast';

type ClientItem = {
    id: number;
    whatsapp_id: string | null;
    phone_number: string | null;
    name: string | null;
    push_name: string | null;
    profile_picture_url: string | null;
    chats_count: number;
    last_chat_name: string | null;
    last_chat_at: string | null;
    channels: Array<{
        chat_id: number;
        session_id: number | null;
        session_label: string;
        session_phone: string | null;
        chat_name: string | null;
        last_message_at: string | null;
    }>;
    companies: ClientCompany[];
};

type ClientCompany = {
    id: number;
    name: string;
    position: string | null;
};

type CompanyItem = {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    website: string | null;
    description: string | null;
    clients_count: number;
    clients: Array<{
        id: number;
        name: string;
        phone_number: string | null;
        position: string | null;
    }>;
};

type CompanyOption = {
    id: number;
    name: string;
};

type PaginationPayload<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

type ContactCardPayload = {
    identity: {
        display_name: string;
        possible_names: string[];
    };
    activity: {
        chats_count: number;
        channels_count: number;
        first_message_at: string | null;
        last_message_at: string | null;
        last_client_message: { body: string | null; at: string | null } | null;
        messages: { total: number; inbound: number; outbound: number };
        attachments: { media: number; documents: number; links: number };
    };
    crm: ContactCrmPayload;
};

const props = defineProps<{
    search: string;
    activeTab: 'clients' | 'companies';
    clients: PaginationPayload<ClientItem>;
    companies: PaginationPayload<CompanyItem>;
    companyOptions: CompanyOption[];
}>();
const { show: showToast } = useToastStore();
const { t, locale } = useI18n();

const search = ref(props.search || '');
const clients = ref<ClientItem[]>([...props.clients.data]);
const companies = ref<CompanyItem[]>([...props.companies.data]);
const companyOptions = ref<CompanyOption[]>([...props.companyOptions]);
const activeTab = ref<'clients' | 'companies'>(props.activeTab || 'clients');
const isSingleTenant = computed(() => companyOptions.value.length <= 1);
const openClientId = ref<number | null>(null);
const editingName = ref('');
const saving = ref(false);
const companySaving = ref(false);
const companyDeleteDialogOpen = ref(false);
const companyDeleteTarget = ref<CompanyItem | null>(null);
const companyDeleting = ref(false);
const clientCompaniesSaving = ref(false);
const contactCard = ref<ContactCardPayload | null>(null);
const contactCardLoading = ref(false);
const contactCardError = ref<string | null>(null);
const clientCompanyDraft = ref<Array<{ company_id: number; position: string }>>([]);
const openedCompanyId = ref<number | 'new' | null>(null);
const companyForm = ref({
    name: '',
    phone: '',
    email: '',
    website: '',
    description: '',
});

watch(
    () => props.clients,
    (next) => {
        clients.value = [...next.data];
    },
);

watch(
    () => props.companies,
    (next) => {
        companies.value = [...next.data];
    },
);

watch(
    () => props.companyOptions,
    (next) => {
        companyOptions.value = [...next];
    },
);

let timer: ReturnType<typeof setTimeout> | null = null;
watch(search, (q) => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
        visitSettings({ search: q || undefined, clients_page: undefined, companies_page: undefined });
    }, 250);
});

watch(activeTab, (tab) => {
    visitSettings({ tab, clients_page: undefined, companies_page: undefined });
});

function displayName(c: ClientItem): string {
    return (
        (c.name || '').trim() ||
        (c.push_name || '').trim() ||
        (c.last_chat_name || '').trim() ||
        formatPhone(c.phone_number) ||
        t('settings.clients.noName')
    );
}

function waIdLabel(c: ClientItem): string {
    const wa = (c.whatsapp_id || '').trim();
    return wa !== '' ? wa : '—';
}

function lastChatLabel(c: ClientItem): string {
    const n = (c.last_chat_name || '').trim();
    return n !== '' ? n : '—';
}

function dateLabel(v: string | null): string {
    if (!v) return '—';
    const d = new Date(v);
    if (Number.isNaN(d.getTime())) return '—';
    const loc = locale.value === 'kk' ? 'kk-KZ' : locale.value === 'en' ? 'en-GB' : 'ru-RU';
    return d.toLocaleString(loc, { dateStyle: 'short', timeStyle: 'short' });
}

const total = computed(() => props.clients.total);
const companiesTotal = computed(() => props.companies.total);
const openedClient = computed(() => clients.value.find((c) => c.id === openClientId.value) || null);

function visitSettings(overrides: Record<string, string | number | undefined>): void {
    router.get(
        route('settings.clients'),
        {
            search: search.value.trim() || undefined,
            tab: activeTab.value,
            clients_page: props.clients.current_page > 1 ? props.clients.current_page : undefined,
            companies_page: props.companies.current_page > 1 ? props.companies.current_page : undefined,
            ...overrides,
        },
        { preserveState: true, replace: true },
    );
}

function openClient(c: ClientItem): void {
    openClientId.value = c.id;
    editingName.value = (c.name || '').trim();
    clientCompanyDraft.value = (c.companies || []).map((company) => ({
        company_id: company.id,
        position: company.position || '',
    }));
    loadContactCard(c.id);
}

function closeClient(): void {
    openClientId.value = null;
    editingName.value = '';
    clientCompanyDraft.value = [];
    contactCard.value = null;
    contactCardError.value = null;
}

async function loadContactCard(contactId: number): Promise<void> {
    contactCardLoading.value = true;
    contactCardError.value = null;
    try {
        const { data } = await axios.get(route('contacts.card', contactId));
        contactCard.value = data as ContactCardPayload;
    } catch (e: any) {
        contactCard.value = null;
        contactCardError.value = e?.response?.data?.message || e?.response?.data?.error || t('settings.clients.errorLoadCard');
    } finally {
        contactCardLoading.value = false;
    }
}

function shortText(text?: string | null): string {
    const value = (text || '').trim();
    return value !== '' ? value : '—';
}

async function saveClientName(): Promise<void> {
    if (!openedClient.value || saving.value) return;
    saving.value = true;
    try {
        const name = editingName.value.trim();
        const { data } = await axios.patch(route('contacts.update', openedClient.value.id), { name });
        if (data?.success) {
            const newChatName =
                name !== ''
                    ? name
                    : (data?.contact?.push_name ?? openedClient.value.push_name ?? openedClient.value.phone_number ?? null);

            const idx = clients.value.findIndex((c) => c.id === openedClient.value!.id);
            if (idx !== -1) {
                clients.value[idx] = {
                    ...clients.value[idx],
                    name: name !== '' ? name : null,
                };
            }

            // Update modal channels immediately: UI shows `ch.chat_name || displayName(openedClient)`.
            openedClient.value.channels = (openedClient.value.channels || []).map((ch) => ({
                ...ch,
                chat_name: newChatName,
            }));

            showToast({ message: t('settings.clients.toastNameUpdated') });
            return;
        }
        showToast({ message: data?.error || t('settings.clients.toastNameError') });
    } catch (e: any) {
        const msg = e?.response?.data?.message || e?.message || t('settings.clients.toastNameError');
        showToast({ message: msg });
    } finally {
        saving.value = false;
    }
}

const openedCompany = computed(() => {
    if (openedCompanyId.value === null || openedCompanyId.value === 'new') return null;
    return companies.value.find((company) => company.id === openedCompanyId.value) || null;
});

function isClientCompanySelected(companyId: number): boolean {
    return clientCompanyDraft.value.some((row) => row.company_id === companyId);
}

function goToPage(kind: 'clients' | 'companies', page: number): void {
    const meta = kind === 'clients' ? props.clients : props.companies;
    if (page < 1 || page > meta.last_page || page === meta.current_page) return;

    visitSettings({
        tab: kind,
        [kind === 'clients' ? 'clients_page' : 'companies_page']: page,
    });
}

function clientCompanyPosition(companyId: number): string {
    return clientCompanyDraft.value.find((row) => row.company_id === companyId)?.position || '';
}

function toggleClientCompany(companyId: number): void {
    if (isClientCompanySelected(companyId)) {
        clientCompanyDraft.value = clientCompanyDraft.value.filter((row) => row.company_id !== companyId);
        return;
    }

    clientCompanyDraft.value = [...clientCompanyDraft.value, { company_id: companyId, position: '' }];
}

function setClientCompanyPosition(companyId: number, position: string): void {
    clientCompanyDraft.value = clientCompanyDraft.value.map((row) =>
        row.company_id === companyId ? { ...row, position } : row,
    );
}

function setClientCompanyPositionFromEvent(companyId: number, event: Event): void {
    const target = event.target;
    setClientCompanyPosition(companyId, target instanceof HTMLInputElement ? target.value : '');
}

async function saveClientCompanies(): Promise<void> {
    if (!openedClient.value || clientCompaniesSaving.value) return;
    clientCompaniesSaving.value = true;
    try {
        const { data } = await axios.patch(route('settings.clients.companies.sync', openedClient.value.id), {
            companies: clientCompanyDraft.value,
        });
        if (data?.success) {
            router.reload({ only: ['clients', 'companies', 'companyOptions'] });
            showToast({ message: t('settings.clients.toastCompaniesUpdated') });
        }
    } catch (e: any) {
        showToast({ message: e?.response?.data?.message || e?.message || t('settings.clients.toastCompaniesError') });
    } finally {
        clientCompaniesSaving.value = false;
    }
}

function openCompany(company?: CompanyItem): void {
    if (company) {
        openedCompanyId.value = company.id;
        companyForm.value = {
            name: company.name || '',
            phone: company.phone || '',
            email: company.email || '',
            website: company.website || '',
            description: company.description || '',
        };
        return;
    }

    openedCompanyId.value = 'new';
    companyForm.value = {
        name: '',
        phone: '',
        email: '',
        website: '',
        description: '',
    };
}

function closeCompany(): void {
    openedCompanyId.value = null;
}

async function saveCompany(): Promise<void> {
    if (companySaving.value || !companyForm.value.name.trim()) return;
    companySaving.value = true;
    try {
        const payload = { ...companyForm.value, name: companyForm.value.name.trim() };
        if (openedCompanyId.value === 'new') {
            await axios.post(route('settings.companies.store'), payload);
            showToast({ message: t('settings.clients.toastCompanyCreated') });
        } else if (openedCompanyId.value) {
            await axios.put(route('settings.companies.update', openedCompanyId.value), payload);
            showToast({ message: t('settings.clients.toastCompanyUpdated') });
        }
        closeCompany();
        router.reload({ only: ['clients', 'companies', 'companyOptions'] });
    } catch (e: any) {
        showToast({ message: e?.response?.data?.message || e?.message || t('settings.clients.toastCompanySaveError') });
    } finally {
        companySaving.value = false;
    }
}

function closeCompanyDeleteDialog(): void {
    if (companyDeleting.value) return;
    companyDeleteDialogOpen.value = false;
    companyDeleteTarget.value = null;
}

function requestDeleteCompany(company: CompanyItem): void {
    companyDeleteTarget.value = company;
    companyDeleteDialogOpen.value = true;
}

async function confirmDeleteCompany(): Promise<void> {
    const company = companyDeleteTarget.value;
    if (!company) return;
    companyDeleting.value = true;
    try {
        await axios.delete(route('settings.companies.destroy', company.id));
        showToast({ message: t('settings.clients.toastCompanyDeleted') });
        companyDeleteDialogOpen.value = false;
        companyDeleteTarget.value = null;
        router.reload({ only: ['clients', 'companies', 'companyOptions'] });
    } catch (e: any) {
        showToast({ message: e?.response?.data?.message || e?.message || t('settings.clients.toastCompanyDeleteError') });
    } finally {
        companyDeleting.value = false;
    }
}

const companyDeleteDescription = computed(() => {
    const c = companyDeleteTarget.value;
    if (!c) return '';
    return t('settings.clients.deleteCompanyDescription', { name: c.name });
});
</script>

<template>
    <Head :title="t('settings.clients.title')" />

    <SettingsLayout :title="t('settings.clients.title')" :subtitle="t('settings.clients.subtitle')">
        <div class="p-6">
            <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-if="!isSingleTenant"
                        type="button"
                        class="rounded-full px-4 py-2 text-sm font-medium"
                        :style="{
                            background: activeTab === 'clients' ? 'var(--ui-accent)' : 'var(--ui-surface-muted)',
                            color: activeTab === 'clients' ? '#fff' : 'var(--ui-text)',
                        }"
                        @click="activeTab = 'clients'"
                    >
                        {{ t('settings.clients.tabClients') }} · {{ total }}
                    </button>
                    <button
                        type="button"
                        class="rounded-full px-4 py-2 text-sm font-medium"
                        :style="{
                            background: activeTab === 'companies' ? 'var(--ui-accent)' : 'var(--ui-surface-muted)',
                            color: activeTab === 'companies' ? '#fff' : 'var(--ui-text)',
                        }"
                        @click="activeTab = 'companies'"
                    >
                        {{ t('settings.clients.tabCompanies') }} · {{ companiesTotal }}
                    </button>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <button
                        v-if="activeTab === 'companies' && !isSingleTenant"
                        type="button"
                        class="rounded-full px-4 py-2 text-sm font-semibold"
                        :style="{ background: 'var(--ui-accent)', color: '#fff' }"
                        @click="openCompany()"
                    >
                        {{ t('settings.clients.addCompany') }}
                    </button>
                    <input
                        v-model="search"
                        type="text"
                        :placeholder="activeTab === 'clients' ? t('settings.clients.searchClients') : t('settings.clients.searchCompanies')"
                        class="w-full min-w-[260px] rounded-full border-0 px-4 py-2 text-sm focus:ring-0 focus:outline-none"
                        :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                    />
                </div>
            </div>

            <div v-if="activeTab === 'clients'" class="space-y-3">
                <div class="text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                    {{ t('settings.clients.shownRange', { from: props.clients.from || 0, to: props.clients.to || 0, total: props.clients.total }) }}
                </div>

                <div
                    v-for="c in clients"
                    :key="c.id"
                    class="rounded-2xl border p-4"
                    :style="{ background: 'var(--ui-surface)', borderColor: 'var(--ui-border)' }"
                >
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <div class="truncate text-[15px]" :style="{ color: 'var(--ui-text)' }">
                                {{ displayName(c) }}
                            </div>
                            <div class="truncate text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                                {{ formatPhone(c.phone_number) || '—' }}
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                                {{ t('settings.clients.chatsCount', { count: c.chats_count }) }}
                            </div>
                            <button
                                type="button"
                                class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[var(--ui-surface-hover)]"
                                :style="{ color: 'var(--ui-text)' }"
                                :title="t('settings.clients.edit')" :aria-label="t('settings.clients.edit')"
                                @click.stop="openClient(c)"
                            >
                                ✎
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-2 text-xs sm:grid-cols-2">
                        <div :style="{ color: 'var(--ui-text-secondary)' }">
                            <span :style="{ color: 'var(--ui-text)' }">{{ t('settings.clients.savedName') }}</span> {{ c.name || '—' }}
                        </div>
                        <div class="sm:col-span-2 truncate" :style="{ color: 'var(--ui-text-secondary)' }">
                            <span :style="{ color: 'var(--ui-text)' }">{{ t('settings.clients.whatsappId') }}</span> {{ waIdLabel(c) }}
                        </div>
                        <div class="truncate" :style="{ color: 'var(--ui-text-secondary)' }">
                            <span :style="{ color: 'var(--ui-text)' }">{{ t('settings.clients.lastChat') }}</span> {{ lastChatLabel(c) }}
                        </div>
                        <div :style="{ color: 'var(--ui-text-secondary)' }">
                            <span :style="{ color: 'var(--ui-text)' }">{{ t('settings.clients.lastActivity') }}</span> {{ dateLabel(c.last_chat_at) }}
                        </div>
                    </div>

                    <div v-if="c.channels.length" class="mt-3 border-t pt-3 text-xs" :style="{ borderColor: 'var(--ui-border)' }">
                        <div class="mb-1.5" :style="{ color: 'var(--ui-text-secondary)' }">{{ t('settings.clients.wroteToNumbers') }}</div>
                        <div class="space-y-1.5">
                            <div
                                v-for="ch in c.channels"
                                :key="`ch-${c.id}-${ch.chat_id}`"
                                class="flex items-center justify-between gap-2 rounded-md px-2 py-1"
                                :style="{ background: 'var(--ui-surface-muted)' }"
                            >
                                <div class="min-w-0">
                                    <div class="truncate" :style="{ color: 'var(--ui-text)' }">
                                        {{ ch.session_label }}<span v-if="ch.session_phone"> · {{ formatPhone(ch.session_phone) }}</span>
                                    </div>
                                    <div class="truncate" :style="{ color: 'var(--ui-text-secondary)' }">
                                        {{ ch.chat_name || displayName(c) }}
                                    </div>
                                </div>
                                <div class="shrink-0" :style="{ color: 'var(--ui-text-secondary)' }">
                                    {{ dateLabel(ch.last_message_at) }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="c.companies.length && !isSingleTenant" class="mt-3 border-t pt-3 text-xs" :style="{ borderColor: 'var(--ui-border)' }">
                        <div class="mb-1.5" :style="{ color: 'var(--ui-text-secondary)' }">{{ t('settings.clients.companies') }}</div>
                        <div class="flex flex-wrap gap-2">
                            <span
                                v-for="company in c.companies"
                                :key="`client-company-${c.id}-${company.id}`"
                                class="rounded-full px-2.5 py-1"
                                :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                            >
                                {{ company.name }}<span v-if="company.position" :style="{ color: 'var(--ui-text-secondary)' }"> · {{ company.position }}</span>
                            </span>
                        </div>
                    </div>

                </div>

                <div
                    v-if="clients.length === 0"
                    class="rounded-xl border border-dashed px-6 py-10 text-center"
                    :style="{ borderColor: 'var(--ui-border)', background: 'var(--ui-surface-muted)' }"
                >
                    <div class="text-sm font-medium" :style="{ color: 'var(--ui-text)' }">
                        {{ search.trim() ? t('settings.clients.emptySearch') : t('settings.clients.emptyClients') }}
                    </div>
                    <p class="mt-2 text-sm max-w-md mx-auto" :style="{ color: 'var(--ui-text-secondary)' }">
                        <template v-if="search.trim()">
                            {{ t('settings.clients.emptySearchHint') }}
                        </template>
                        <template v-else>
                            {{ t('settings.clients.emptyClientsHint') }}
                        </template>
                    </p>
                    <Link
                        v-if="!search.trim()"
                        :href="route('settings.connections')"
                        class="mt-4 inline-flex rounded-lg px-4 py-2 text-sm font-medium"
                        :style="{ background: 'var(--ui-accent)', color: '#fff' }"
                    >
                        {{ t('settings.clients.goToConnections') }}
                    </Link>
                </div>

                <div v-if="props.clients.last_page > 1" class="flex items-center justify-between gap-3 pt-2">
                    <button
                        type="button"
                        class="rounded-lg px-3 py-2 text-sm disabled:opacity-40"
                        :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                        :disabled="props.clients.current_page <= 1"
                        @click="goToPage('clients', props.clients.current_page - 1)"
                    >
                        {{ t('common.back') }}
                    </button>
                    <div class="text-sm" :style="{ color: 'var(--ui-text-secondary)' }">
                        {{ t('settings.clients.pageOf', { current: props.clients.current_page, last: props.clients.last_page }) }}
                    </div>
                    <button
                        type="button"
                        class="rounded-lg px-3 py-2 text-sm disabled:opacity-40"
                        :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                        :disabled="props.clients.current_page >= props.clients.last_page"
                        @click="goToPage('clients', props.clients.current_page + 1)"
                    >
                        {{ t('common.forward') }}
                    </button>
                </div>
            </div>

            <div v-else class="space-y-3">
                <div class="text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                    {{ t('settings.clients.shownRange', { from: props.companies.from || 0, to: props.companies.to || 0, total: props.companies.total }) }}
                </div>

                <div
                    v-for="company in companies"
                    :key="company.id"
                    class="rounded-2xl border p-4"
                    :style="{ background: 'var(--ui-surface)', borderColor: 'var(--ui-border)' }"
                >
                    <div class="mb-3 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="truncate text-[15px] font-medium" :style="{ color: 'var(--ui-text)' }">
                                {{ company.name }}
                            </div>
                            <div class="text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                                {{ t('settings.clients.clientsInCompany', { count: company.clients_count }) }}
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="rounded-lg px-3 py-1.5 text-xs hover:bg-[var(--ui-surface-hover)]"
                                :style="{ color: 'var(--ui-text)' }"
                                @click="openCompany(company)"
                            >
                                {{ t('settings.clients.change') }}
                            </button>
                            <button
                                type="button"
                                class="rounded-lg px-3 py-1.5 text-xs"
                                :style="{ color: 'var(--ui-danger)', background: 'color-mix(in srgb, var(--ui-danger) 10%, transparent)' }"
                                @click="requestDeleteCompany(company)"
                            >
                                {{ t('common.delete') }}
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-2 text-xs sm:grid-cols-2" :style="{ color: 'var(--ui-text-secondary)' }">
                        <div><span :style="{ color: 'var(--ui-text)' }">{{ t('settings.clients.phone') }}</span> {{ company.phone || '—' }}</div>
                        <div><span :style="{ color: 'var(--ui-text)' }">Email:</span> {{ company.email || '—' }}</div>
                        <div><span :style="{ color: 'var(--ui-text)' }">{{ t('settings.clients.website') }}</span> {{ company.website || '—' }}</div>
                        <div><span :style="{ color: 'var(--ui-text)' }">{{ t('settings.clients.description') }}</span> {{ company.description || '—' }}</div>
                    </div>

                    <div class="mt-3 border-t pt-3" :style="{ borderColor: 'var(--ui-border)' }">
                        <div class="mb-2 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">{{ t('settings.clients.clientsInCompanyTitle') }}</div>
                        <div v-if="company.clients.length" class="space-y-1.5">
                            <div
                                v-for="client in company.clients"
                                :key="`company-client-${company.id}-${client.id}`"
                                class="rounded-md px-2 py-1.5 text-xs"
                                :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                            >
                                {{ client.name }}
                                <span v-if="client.position" :style="{ color: 'var(--ui-text-secondary)' }"> · {{ client.position }}</span>
                                <span v-if="client.phone_number" :style="{ color: 'var(--ui-text-secondary)' }"> · {{ formatPhone(client.phone_number) }}</span>
                            </div>
                        </div>
                        <div v-else class="rounded-xl border border-dashed p-4 text-center text-sm" :style="{ borderColor: 'var(--ui-border)', color: 'var(--ui-text-secondary)' }">
                            {{ t('settings.clients.noClientsInCompany') }}
                        </div>
                    </div>
                </div>

                <div v-if="companies.length === 0" class="py-10 text-center text-sm" :style="{ color: 'var(--ui-text-secondary)' }">
                    {{ t('settings.clients.companiesNotFound') }}
                </div>

                <div v-if="props.companies.last_page > 1" class="flex items-center justify-between gap-3 pt-2">
                    <button
                        type="button"
                        class="rounded-lg px-3 py-2 text-sm disabled:opacity-40"
                        :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                        :disabled="props.companies.current_page <= 1"
                        @click="goToPage('companies', props.companies.current_page - 1)"
                    >
                        {{ t('common.back') }}
                    </button>
                    <div class="text-sm" :style="{ color: 'var(--ui-text-secondary)' }">
                        {{ t('settings.clients.pageOf', { current: props.companies.current_page, last: props.companies.last_page }) }}
                    </div>
                    <button
                        type="button"
                        class="rounded-lg px-3 py-2 text-sm disabled:opacity-40"
                        :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                        :disabled="props.companies.current_page >= props.companies.last_page"
                        @click="goToPage('companies', props.companies.current_page + 1)"
                    >
                        {{ t('common.forward') }}
                    </button>
                </div>
            </div>
        </div>

        <teleport to="body">
            <div
                v-if="openedClient"
                class="fixed inset-0 z-[450] overflow-y-auto px-4 py-4 sm:py-8"
                :style="{ background: 'rgba(0,0,0,.45)' }"
            >
                <div class="mx-auto flex min-h-[calc(100%-2rem)] max-w-[520px] items-center justify-center">
                <div
                    class="flex w-full max-h-[min(90dvh,calc(100dvh-2rem))] flex-col rounded-2xl border overflow-hidden"
                    :style="{ background: 'var(--ui-surface)', borderColor: 'var(--ui-border)' }"
                >
                    <div class="shrink-0 px-5 py-4 flex items-center justify-between" :style="{ background: 'var(--ui-surface-muted)' }">
                        <div class="text-sm font-medium" :style="{ color: 'var(--ui-text)' }">
                            {{ t('settings.clients.modalClient', { name: displayName(openedClient) }) }}
                        </div>
                        <button type="button" class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-[var(--ui-surface-hover)]" @click="closeClient">
                            ✕
                        </button>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto p-5 space-y-4 text-sm">
                        <div>
                            <div class="mb-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">{{ t('settings.clients.savedNameLabel') }}</div>
                            <input
                                v-model="editingName"
                                type="text"
                                class="w-full rounded-lg border-0 px-3 py-2 focus:ring-0 focus:outline-none"
                                :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                                :placeholder="t('settings.clients.namePlaceholder')"
                            />
                        </div>

                        <div class="grid grid-cols-1 gap-2 text-xs sm:grid-cols-2" :style="{ color: 'var(--ui-text-secondary)' }">
                            <div><span :style="{ color: 'var(--ui-text)' }">{{ t('settings.clients.phone') }}</span> {{ formatPhone(openedClient.phone_number) || '—' }}</div>
                            <div class="sm:col-span-2 truncate"><span :style="{ color: 'var(--ui-text)' }">{{ t('settings.clients.whatsappId') }}</span> {{ waIdLabel(openedClient) }}</div>
                            <div><span :style="{ color: 'var(--ui-text)' }">{{ t('settings.clients.lastChat') }}</span> {{ lastChatLabel(openedClient) }}</div>
                            <div><span :style="{ color: 'var(--ui-text)' }">{{ t('settings.clients.lastActivity') }}</span> {{ dateLabel(openedClient.last_chat_at) }}</div>
                        </div>

                        <div v-if="!isSingleTenant" class="rounded-xl border p-3" :style="{ borderColor: 'var(--ui-border)', background: 'var(--ui-surface-muted)' }">
                            <div class="mb-3 flex items-center justify-between gap-2">
                                <div>
                                    <div class="text-sm font-medium" :style="{ color: 'var(--ui-text)' }">{{ t('settings.clients.companiesAndPositions') }}</div>
                                    <div class="text-xs" :style="{ color: 'var(--ui-text-secondary)' }">{{ t('settings.clients.companiesHint') }}</div>
                                </div>
                                <button
                                    type="button"
                                    class="rounded-lg px-3 py-1.5 text-xs"
                                    :disabled="clientCompaniesSaving"
                                    :style="{ background: 'var(--ui-accent)', color: '#fff', opacity: clientCompaniesSaving ? 0.6 : 1 }"
                                    @click="saveClientCompanies"
                                >
                                    {{ t('settings.clients.saveLinks') }}
                                </button>
                            </div>

                            <div v-if="companyOptions.length" class="space-y-2">
                                <div
                                    v-for="company in companyOptions"
                                    :key="`client-modal-company-${company.id}`"
                                    class="rounded-lg border p-2"
                                    :style="{ borderColor: isClientCompanySelected(company.id) ? 'var(--ui-accent)' : 'var(--ui-border)', background: 'var(--ui-surface)' }"
                                >
                                    <label class="flex items-center gap-2 text-sm" :style="{ color: 'var(--ui-text)' }">
                                        <UiCheckbox
                                            size="sm"
                                            :model-value="isClientCompanySelected(company.id)"
                                            @update:model-value="toggleClientCompany(company.id)"
                                        />
                                        <span class="font-medium">{{ company.name }}</span>
                                    </label>
                                    <input
                                        v-if="isClientCompanySelected(company.id)"
                                        :value="clientCompanyPosition(company.id)"
                                        type="text"
                                        class="mt-2 w-full rounded-lg border-0 px-3 py-2 text-xs focus:ring-0 focus:outline-none"
                                        :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                                        :placeholder="t('settings.clients.positionPlaceholder')"
                                        @input="setClientCompanyPositionFromEvent(company.id, $event)"
                                    />
                                </div>
                            </div>
                            <div v-else class="rounded-xl border border-dashed p-4 text-center text-sm" :style="{ borderColor: 'var(--ui-border)', color: 'var(--ui-text-secondary)' }">
                                {{ t('settings.clients.noCompaniesHint') }}
                            </div>
                        </div>

                        <div class="rounded-xl border p-3" :style="{ borderColor: 'var(--ui-border)', background: 'var(--ui-surface-muted)' }">
                            <div class="mb-2 flex items-center justify-between gap-2">
                                <div>
                                    <div class="text-sm font-medium" :style="{ color: 'var(--ui-text)' }">{{ t('settings.clients.autoCard') }}</div>
                                    <div class="text-xs" :style="{ color: 'var(--ui-text-secondary)' }">{{ t('settings.clients.autoCardHint') }}</div>
                                </div>
                                <button
                                    type="button"
                                    class="rounded-lg px-2 py-1 text-xs hover:bg-[var(--ui-surface-hover)]"
                                    :style="{ color: 'var(--ui-text)' }"
                                    :disabled="contactCardLoading"
                                    @click="loadContactCard(openedClient.id)"
                                >
                                    {{ t('settings.clients.refresh') }}
                                </button>
                            </div>

                            <ContactCardSkeleton v-if="contactCardLoading" :show-deal="true" />
                            <div v-else-if="contactCardError" class="text-xs" :style="{ color: 'var(--ui-danger)' }">
                                {{ contactCardError }}
                            </div>
                            <div v-else-if="contactCard" class="space-y-3">
                                <div class="grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
                                    <div class="rounded-lg p-2" :style="{ background: 'var(--ui-surface)', border: '1px solid var(--ui-border)' }">
                                        <div :style="{ color: 'var(--ui-text-secondary)' }">{{ t('settings.clients.statsChats') }}</div>
                                        <div class="text-base font-semibold" :style="{ color: 'var(--ui-text)' }">{{ contactCard.activity.chats_count }}</div>
                                    </div>
                                    <div class="rounded-lg p-2" :style="{ background: 'var(--ui-surface)', border: '1px solid var(--ui-border)' }">
                                        <div :style="{ color: 'var(--ui-text-secondary)' }">{{ t('settings.clients.statsChannels') }}</div>
                                        <div class="text-base font-semibold" :style="{ color: 'var(--ui-text)' }">{{ contactCard.activity.channels_count }}</div>
                                    </div>
                                    <div class="rounded-lg p-2" :style="{ background: 'var(--ui-surface)', border: '1px solid var(--ui-border)' }">
                                        <div :style="{ color: 'var(--ui-text-secondary)' }">{{ t('settings.clients.statsMessages') }}</div>
                                        <div class="text-base font-semibold" :style="{ color: 'var(--ui-text)' }">{{ contactCard.activity.messages.total }}</div>
                                    </div>
                                    <div class="rounded-lg p-2" :style="{ background: 'var(--ui-surface)', border: '1px solid var(--ui-border)' }">
                                        <div :style="{ color: 'var(--ui-text-secondary)' }">{{ t('settings.clients.statsAttachments') }}</div>
                                        <div class="text-base font-semibold" :style="{ color: 'var(--ui-text)' }">
                                            {{ contactCard.activity.attachments.media + contactCard.activity.attachments.documents + contactCard.activity.attachments.links }}
                                        </div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 gap-1 text-xs sm:grid-cols-2" :style="{ color: 'var(--ui-text-secondary)' }">
                                    <div><span :style="{ color: 'var(--ui-text)' }">{{ t('settings.clients.firstMessage') }}</span> {{ dateLabel(contactCard.activity.first_message_at) }}</div>
                                    <div><span :style="{ color: 'var(--ui-text)' }">{{ t('settings.clients.lastActivity') }}</span> {{ dateLabel(contactCard.activity.last_message_at) }}</div>
                                    <div><span :style="{ color: 'var(--ui-text)' }">{{ t('settings.clients.clientMessages') }}</span> {{ contactCard.activity.messages.inbound }}</div>
                                    <div><span :style="{ color: 'var(--ui-text)' }">{{ t('settings.clients.operatorMessages') }}</span> {{ contactCard.activity.messages.outbound }}</div>
                                </div>

                                <div v-if="contactCard.activity.last_client_message" class="rounded-lg p-2 text-xs" :style="{ background: 'var(--ui-surface)', border: '1px solid var(--ui-border)' }">
                                    <div :style="{ color: 'var(--ui-text-secondary)' }">{{ t('settings.clients.lastClientReply') }}</div>
                                    <div class="mt-1" :style="{ color: 'var(--ui-text)' }">{{ shortText(contactCard.activity.last_client_message.body) }}</div>
                                </div>

                                <ContactCrmSections v-if="contactCard.crm" :crm="contactCard.crm" />
                            </div>
                            <div
                                v-else
                                class="rounded-lg border border-dashed px-3 py-4 text-xs text-center"
                                :style="{ borderColor: 'var(--ui-border)', color: 'var(--ui-text-secondary)' }"
                            >
                                {{ t('settings.clients.autoCardEmpty') }}
                            </div>
                        </div>

                        <div v-if="openedClient.channels.length" class="border-t pt-3" :style="{ borderColor: 'var(--ui-border)' }">
                            <div class="mb-2 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">{{ t('settings.clients.channelsTitle') }}</div>
                            <div class="space-y-2 text-xs">
                                <div
                                    v-for="ch in openedClient.channels"
                                    :key="`open-${openedClient.id}-${ch.chat_id}`"
                                    class="rounded-md px-2 py-1.5 flex items-center justify-between gap-3"
                                    :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text-secondary)' }"
                                >
                                    <div class="min-w-0">
                                        <div :style="{ color: 'var(--ui-text)' }">
                                            {{ ch.session_label }}<span v-if="ch.session_phone"> · {{ formatPhone(ch.session_phone) }}</span>
                                        </div>
                                        <div class="truncate">
                                            {{ t('settings.clients.chatName', { name: ch.chat_name || displayName(openedClient) }) }}
                                        </div>
                                    </div>
                                    <div class="flex flex-col items-end gap-1 shrink-0">
                                        <div :style="{ color: 'var(--ui-text-secondary)', fontSize: '11px' }">
                                            {{ dateLabel(ch.last_message_at) }}
                                        </div>
                                        <Link
                                            :href="route('chats.show', ch.chat_id)"
                                            class="rounded-lg px-2 py-1 text-[11px] hover:bg-[var(--ui-surface-hover)]"
                                            :style="{ background: 'var(--ui-surface)', color: 'var(--ui-text)' }"
                                        >
                                            {{ t('settings.clients.goToChat') }}
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <div
                        class="shrink-0 flex justify-end gap-2 border-t px-5 py-4"
                        :style="{ borderColor: 'var(--ui-border)', background: 'var(--ui-surface)' }"
                    >
                        <button type="button" class="ui-btn ui-btn--secondary" @click="closeClient">
                            {{ t('common.close') }}
                        </button>
                        <button
                            type="button"
                            class="ui-btn ui-btn--primary"
                            :disabled="saving"
                            @click="saveClientName"
                        >
                            {{ t('common.save') }}
                        </button>
                    </div>
                </div>
                </div>
            </div>
        </teleport>

        <teleport to="body">
            <div
                v-if="openedCompanyId !== null"
                class="fixed inset-0 z-[460] overflow-y-auto px-4 py-4 sm:py-8"
                :style="{ background: 'rgba(0,0,0,.45)' }"
            >
                <div class="mx-auto flex min-h-[calc(100%-2rem)] max-w-[520px] items-center justify-center">
                <div
                    class="flex w-full max-h-[min(90dvh,calc(100dvh-2rem))] flex-col rounded-2xl border overflow-hidden"
                    :style="{ background: 'var(--ui-surface)', borderColor: 'var(--ui-border)' }"
                >
                    <div class="shrink-0 px-5 py-4 flex items-center justify-between" :style="{ background: 'var(--ui-surface-muted)' }">
                        <div>
                            <div class="text-sm font-medium" :style="{ color: 'var(--ui-text)' }">
                                {{ openedCompanyId === 'new' ? t('settings.clients.newCompany') : t('settings.clients.companyTitle', { name: openedCompany?.name || '' }) }}
                            </div>
                            <div class="text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                                {{ t('settings.clients.companyCanExistAlone') }}
                            </div>
                        </div>
                        <button type="button" class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-[var(--ui-surface-hover)]" @click="closeCompany">
                            ✕
                        </button>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto p-5 space-y-4 text-sm">
                        <div>
                            <div class="mb-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">{{ t('settings.clients.companyName') }}</div>
                            <input
                                v-model="companyForm.name"
                                type="text"
                                class="w-full rounded-lg border-0 px-3 py-2 focus:ring-0 focus:outline-none"
                                :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                                :placeholder="t('settings.clients.companyNamePlaceholder')"
                            />
                        </div>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <div class="mb-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">{{ t('settings.usersForm.phone') }}</div>
                                <input
                                    v-model="companyForm.phone"
                                    type="text"
                                    class="w-full rounded-lg border-0 px-3 py-2 focus:ring-0 focus:outline-none"
                                    :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                                />
                            </div>
                            <div>
                                <div class="mb-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">Email</div>
                                <input
                                    v-model="companyForm.email"
                                    type="email"
                                    class="w-full rounded-lg border-0 px-3 py-2 focus:ring-0 focus:outline-none"
                                    :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                                />
                            </div>
                        </div>

                        <div>
                            <div class="mb-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">{{ t('settings.clients.website').replace(':', '') }}</div>
                            <input
                                v-model="companyForm.website"
                                type="text"
                                class="w-full rounded-lg border-0 px-3 py-2 focus:ring-0 focus:outline-none"
                                :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                                placeholder="https://example.kz"
                            />
                        </div>

                        <div>
                            <div class="mb-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">{{ t('settings.clients.description').replace(':', '') }}</div>
                            <textarea
                                v-model="companyForm.description"
                                rows="3"
                                class="w-full resize-none rounded-lg border-0 px-3 py-2 focus:ring-0 focus:outline-none"
                                :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                            ></textarea>
                        </div>

                        <div v-if="openedCompany && openedCompany.clients.length" class="rounded-xl border p-3" :style="{ borderColor: 'var(--ui-border)', background: 'var(--ui-surface-muted)' }">
                            <div class="mb-2 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">{{ t('settings.clients.companyClients') }}</div>
                            <div class="space-y-1.5">
                                <div
                                    v-for="client in openedCompany.clients"
                                    :key="`opened-company-client-${client.id}`"
                                    class="text-xs"
                                    :style="{ color: 'var(--ui-text)' }"
                                >
                                    {{ client.name }}
                                    <span v-if="client.position" :style="{ color: 'var(--ui-text-secondary)' }"> · {{ client.position }}</span>
                                </div>
                            </div>
                        </div>

                    </div>

                    <div
                        class="shrink-0 flex justify-end gap-2 border-t px-5 py-4"
                        :style="{ borderColor: 'var(--ui-border)', background: 'var(--ui-surface)' }"
                    >
                        <button type="button" class="ui-btn ui-btn--secondary" @click="closeCompany">
                            {{ t('common.close') }}
                        </button>
                        <button
                            type="button"
                            class="ui-btn ui-btn--primary"
                            :disabled="companySaving || !companyForm.name.trim()"
                            @click="saveCompany"
                        >
                            {{ t('common.save') }}
                        </button>
                    </div>
                </div>
                </div>
            </div>
        </teleport>
    </SettingsLayout>

    <DangerConfirmModal
        :open="companyDeleteDialogOpen"
        :title="t('settings.clients.deleteCompanyTitle')"
        :description="companyDeleteDescription"
        :confirm-label="t('common.delete')"
        :busy="companyDeleting"
        confirm-variant="danger"
        @close="closeCompanyDeleteDialog"
        @confirm="confirmDeleteCompany"
    />
</template>
