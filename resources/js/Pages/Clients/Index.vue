<script setup lang="ts">
import ClientDetailModal from '@/Components/Clients/ClientDetailModal.vue';
import ClientsFilterPanel, { type AssigneeOption, type FilterFieldDef, type FunnelStageOption } from '@/Components/Clients/ClientsFilterPanel.vue';
import type { ClientListItem } from '@/Components/Clients/clientProfileTypes';
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import UiPillNav from '@/Components/Ui/UiPillNav.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import { formatPhone } from '@/utils/phone';
import { useToastStore } from '@/stores/toast';

type CompanyItem = {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    website: string | null;
    description: string | null;
    clients_count: number;
    clients: Array<{ id: number; name: string; phone_number: string | null; position: string | null }>;
};

type CompanyOption = { id: number; name: string };

type PaginationPayload<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

const props = defineProps<{
    search: string;
    filters: Record<string, string>;
    filterFields: FilterFieldDef[];
    funnelStages: FunnelStageOption[];
    assigneeOptions: AssigneeOption[];
    activeTab: 'clients' | 'companies';
    clients: PaginationPayload<ClientListItem>;
    companies: PaginationPayload<CompanyItem>;
    companyOptions: CompanyOption[];
    canManageCompanies: boolean;
    canManageContactFields: boolean;
}>();

const { show: showToast } = useToastStore();

const search = ref(props.search || '');
const filters = ref<Record<string, string>>({ ...props.filters });
const clients = ref<ClientListItem[]>([...props.clients.data]);
const companies = ref<CompanyItem[]>([...props.companies.data]);
const activeTab = ref<'clients' | 'companies'>(props.activeTab || 'clients');
const openedContactId = ref<number | null>(null);
const companySaving = ref(false);
const companyDeleteDialogOpen = ref(false);
const companyDeleteTarget = ref<CompanyItem | null>(null);
const companyDeleting = ref(false);
const openedCompanyId = ref<number | 'new' | null>(null);
const companyForm = ref({ name: '', phone: '', email: '', website: '', description: '' });

watch(() => props.clients, (next) => { clients.value = [...next.data]; });
watch(() => props.companies, (next) => { companies.value = [...next.data]; });

watch(() => props.filters, (next) => { filters.value = { ...next }; });

let timer: ReturnType<typeof setTimeout> | null = null;
let filterTimer: ReturnType<typeof setTimeout> | null = null;
watch(search, (q) => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
        visitClients({ search: q || undefined, clients_page: undefined, companies_page: undefined });
    }, 250);
});

function onFiltersApply(): void {
    if (filterTimer) clearTimeout(filterTimer);
    filterTimer = setTimeout(() => {
        visitClients({ clients_page: undefined, companies_page: undefined });
    }, 300);
}

function resetFilters(): void {
    filters.value = {};
    visitClients({ clients_page: undefined, companies_page: undefined });
}

watch(activeTab, (tab) => {
    if (tab === 'companies' && !props.canManageCompanies) {
        activeTab.value = 'clients';
        return;
    }
    visitClients({ tab, clients_page: undefined, companies_page: undefined });
});

const openedClient = computed(() => clients.value.find((c) => c.id === openedContactId.value) || null);
const total = computed(() => props.clients.total);
const companiesTotal = computed(() => props.companies.total);

function displayName(c: ClientListItem): string {
    return (
        (c.name || '').trim()
        || (c.push_name || '').trim()
        || (c.last_chat_name || '').trim()
        || formatPhone(c.phone_number)
        || 'Без имени'
    );
}

function dateLabel(v: string | null): string {
    if (!v) return '—';
    const d = new Date(v);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}

function activeFilterParams(): Record<string, string> | undefined {
    const active = Object.fromEntries(
        Object.entries(filters.value).filter(([, value]) => String(value).trim() !== ''),
    );

    return Object.keys(active).length > 0 ? active : undefined;
}

function visitClients(overrides: Record<string, string | number | Record<string, string> | undefined>): void {
    router.get(
        route('clients.index'),
        {
            search: search.value.trim() || undefined,
            filters: activeFilterParams(),
            tab: activeTab.value,
            clients_page: props.clients.current_page > 1 ? props.clients.current_page : undefined,
            companies_page: props.companies.current_page > 1 ? props.companies.current_page : undefined,
            ...overrides,
        },
        { preserveState: true, replace: true },
    );
}

function openClient(c: ClientListItem): void {
    openedContactId.value = c.id;
}

onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    const contactParam = params.get('contact');
    if (contactParam) {
        const id = Number(contactParam);
        if (!Number.isNaN(id) && id > 0) {
            openedContactId.value = id;
        }
    }
});

function onPhotoUpdated(clientId: number, url: string | null): void {
    const row = clients.value.find((c) => c.id === clientId);
    if (row) {
        row.profile_picture_url = url;
    }
}

function closeClient(): void {
    openedContactId.value = null;
}

function onClientSaved(clientId: number, name: string | null): void {
    const idx = clients.value.findIndex((c) => c.id === clientId);
    if (idx !== -1) {
        clients.value[idx] = { ...clients.value[idx], name };
    }
}

function goToPage(kind: 'clients' | 'companies', page: number): void {
    const meta = kind === 'clients' ? props.clients : props.companies;
    if (page < 1 || page > meta.last_page || page === meta.current_page) return;
    visitClients({ tab: kind, [kind === 'clients' ? 'clients_page' : 'companies_page']: page });
}

const openedCompany = computed(() => {
    if (openedCompanyId.value === null || openedCompanyId.value === 'new') return null;
    return companies.value.find((company) => company.id === openedCompanyId.value) || null;
});

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
    companyForm.value = { name: '', phone: '', email: '', website: '', description: '' };
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
            showToast({ message: 'Компания создана' });
        } else if (openedCompanyId.value) {
            await axios.put(route('settings.companies.update', openedCompanyId.value), payload);
            showToast({ message: 'Компания обновлена' });
        }
        closeCompany();
        router.reload({ only: ['clients', 'companies', 'companyOptions'] });
    } catch (e: any) {
        showToast({ message: e?.response?.data?.message || 'Не удалось сохранить компанию' });
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
        showToast({ message: 'Компания удалена' });
        companyDeleteDialogOpen.value = false;
        companyDeleteTarget.value = null;
        router.reload({ only: ['clients', 'companies', 'companyOptions'] });
    } catch (e: any) {
        showToast({ message: e?.response?.data?.message || 'Не удалось удалить компанию' });
    } finally {
        companyDeleting.value = false;
    }
}

const companyDeleteDescription = computed(() => {
    const c = companyDeleteTarget.value;
    if (!c) return '';
    return `Удалить компанию «${c.name}»? Связи с клиентами тоже будут удалены.`;
});
</script>

<template>
    <Head title="Клиенты" />

    <AuthenticatedLayout>
        <div class="flex h-full min-h-0 flex-col overflow-hidden">
            <header class="shrink-0 border-b border-[var(--ui-border)] bg-[var(--ui-surface)] px-4 py-4 sm:px-6">
                <div class="mb-3">
                    <h1 class="text-lg font-semibold text-[var(--ui-text)]">Клиенты</h1>
                    <p class="text-sm text-[var(--ui-text-secondary)]">Карточки клиентов, профиль и AI-сводка</p>
                </div>
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex flex-wrap items-center gap-[var(--primitive-gap-sm)]">
                        <UiPillNav class="ui-pill-nav--lg w-full sm:w-auto sm:min-w-[22rem]">
                            <button
                                type="button"
                                class="ui-pill-nav__item"
                                :class="{ 'is-active': activeTab === 'clients' }"
                                @click="activeTab = 'clients'"
                            >
                                <span class="truncate">Клиенты</span>
                                <span class="ui-tab-badge ui-tab-badge--neutral">{{ total }}</span>
                            </button>
                            <button
                                v-if="canManageCompanies"
                                type="button"
                                class="ui-pill-nav__item"
                                :class="{ 'is-active': activeTab === 'companies' }"
                                @click="activeTab = 'companies'"
                            >
                                <span class="truncate">Компании</span>
                                <span class="ui-tab-badge ui-tab-badge--neutral">{{ companiesTotal }}</span>
                            </button>
                        </UiPillNav>
                        <button
                            v-if="activeTab === 'companies' && canManageCompanies"
                            type="button"
                            class="ui-btn ui-btn--primary ui-btn--sm"
                            @click="openCompany()"
                        >
                            + Компания
                        </button>
                    </div>
                    <input
                        v-model="search"
                        type="search"
                        class="ui-input w-full min-w-[240px] !rounded-[var(--primitive-radius-pill)] lg:max-w-sm"
                        :placeholder="activeTab === 'clients' ? 'Поиск по имени, номеру, WhatsApp ID' : 'Поиск по компаниям'"
                    />
                </div>
            </header>

            <div class="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6">
                <div v-if="activeTab === 'clients'" class="space-y-4">
                    <ClientsFilterPanel
                        v-model="filters"
                        :fields="filterFields"
                        :funnel-stages="funnelStages"
                        :assignee-options="assigneeOptions"
                        @apply="onFiltersApply"
                        @reset="resetFilters"
                    />

                    <div class="text-xs text-[var(--ui-text-secondary)]">
                        Показано {{ props.clients.from || 0 }}–{{ props.clients.to || 0 }} из {{ props.clients.total }}
                    </div>

                    <div v-if="clients.length === 0" class="py-16 text-center text-sm text-[var(--ui-text-secondary)]">
                        {{ activeFilterParams() ? 'По выбранным фильтрам никого не найдено' : 'Клиенты не найдены' }}
                    </div>

                    <div v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <button
                            v-for="c in clients"
                            :key="c.id"
                            type="button"
                            class="ui-panel rounded-2xl p-4 text-left transition hover:shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-[color-mix(in_srgb,var(--ui-accent)_35%,transparent)]"
                            @click="openClient(c)"
                        >
                            <div class="mb-3 flex items-start gap-3">
                                <UserAvatar :name="displayName(c)" :src="c.profile_picture_url" :size="40" />
                                <div class="min-w-0 flex-1">
                                    <div class="truncate font-medium text-[var(--ui-text)]">{{ displayName(c) }}</div>
                                    <div class="truncate text-xs text-[var(--ui-text-secondary)]">{{ c.phone_display || formatPhone(c.phone_number) || '—' }}</div>
                                </div>
                                <span
                                    v-if="c.unread_count > 0"
                                    class="ui-tab-badge shrink-0"
                                >
                                    {{ c.unread_count > 99 ? '99+' : c.unread_count }}
                                </span>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    v-if="c.stage"
                                    class="ui-badge rounded-full px-2 py-0.5 text-[11px]"
                                    :style="{ background: c.stage.color || 'var(--ui-accent)', color: 'var(--ui-accent-on)', borderColor: 'transparent' }"
                                >
                                    {{ c.stage.name }}
                                </span>
                                <span class="text-xs text-[var(--ui-text-muted)]">{{ dateLabel(c.last_chat_at) }}</span>
                            </div>
                        </button>
                    </div>

                    <div v-if="props.clients.last_page > 1" class="flex items-center justify-between gap-3 pt-2">
                        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" :disabled="props.clients.current_page <= 1" @click="goToPage('clients', props.clients.current_page - 1)">Назад</button>
                        <span class="text-xs text-[var(--ui-text-secondary)]">Страница {{ props.clients.current_page }} из {{ props.clients.last_page }}</span>
                        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" :disabled="props.clients.current_page >= props.clients.last_page" @click="goToPage('clients', props.clients.current_page + 1)">Вперёд</button>
                    </div>
                </div>

                <div v-else-if="canManageCompanies" class="space-y-4">
                    <div class="text-xs text-[var(--ui-text-secondary)]">Показано {{ props.companies.from || 0 }}–{{ props.companies.to || 0 }} из {{ props.companies.total }}</div>
                    <div
                        v-for="company in companies"
                        :key="company.id"
                        class="ui-panel rounded-2xl p-4"
                    >
                        <div class="mb-2 flex items-start justify-between gap-3">
                            <div>
                                <div class="font-medium text-[var(--ui-text)]">{{ company.name }}</div>
                                <div class="text-xs text-[var(--ui-text-secondary)]">Клиентов: {{ company.clients_count }}</div>
                            </div>
                            <div class="flex gap-[var(--primitive-gap-sm)]">
                                <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="openCompany(company)">Изменить</button>
                                <button type="button" class="ui-btn ui-btn--danger-ghost ui-btn--sm" @click="requestDeleteCompany(company)">Удалить</button>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 gap-1 text-xs text-[var(--ui-text-secondary)] sm:grid-cols-2">
                            <div v-if="company.phone">Тел: {{ company.phone }}</div>
                            <div v-if="company.email">Email: {{ company.email }}</div>
                            <div v-if="company.website" class="sm:col-span-2">Сайт: {{ company.website }}</div>
                        </div>
                    </div>
                    <div v-if="props.companies.last_page > 1" class="flex items-center justify-between gap-3 pt-2">
                        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" :disabled="props.companies.current_page <= 1" @click="goToPage('companies', props.companies.current_page - 1)">Назад</button>
                        <span class="text-xs text-[var(--ui-text-secondary)]">Страница {{ props.companies.current_page }} из {{ props.companies.last_page }}</span>
                        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" :disabled="props.companies.current_page >= props.companies.last_page" @click="goToPage('companies', props.companies.current_page + 1)">Вперёд</button>
                    </div>
                </div>
            </div>
        </div>

        <ClientDetailModal
            :client="openedClient"
            :can-manage-contact-fields="canManageContactFields"
            @close="closeClient"
            @saved="onClientSaved"
            @photo-updated="onPhotoUpdated"
        />

        <teleport to="body">
            <div v-if="openedCompanyId !== null" class="fixed inset-0 z-[460] overflow-y-auto px-4 py-4 sm:py-8" :style="{ background: 'rgba(0,0,0,.45)' }" @click.self="closeCompany">
                <div class="mx-auto flex min-h-[calc(100%-2rem)] max-w-[520px] items-center justify-center">
                    <div class="flex w-full max-h-[min(90dvh,calc(100dvh-2rem))] flex-col overflow-hidden rounded-2xl border border-[var(--ui-border)] bg-[var(--ui-surface)]">
                        <div class="flex shrink-0 items-center justify-between bg-[var(--ui-surface-muted)] px-5 py-4">
                            <div class="text-sm font-medium text-[var(--ui-text)]">{{ openedCompanyId === 'new' ? 'Новая компания' : `Компания: ${openedCompany?.name || ''}` }}</div>
                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm text-base leading-none" aria-label="Закрыть" @click="closeCompany">✕</button>
                        </div>
                        <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-5 text-sm">
                            <div>
                                <label class="ui-filter-field__label">Название</label>
                                <input v-model="companyForm.name" type="text" class="ui-input" />
                            </div>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="ui-filter-field__label">Телефон</label>
                                    <input v-model="companyForm.phone" type="text" class="ui-input" />
                                </div>
                                <div>
                                    <label class="ui-filter-field__label">Email</label>
                                    <input v-model="companyForm.email" type="email" class="ui-input" />
                                </div>
                            </div>
                            <div>
                                <label class="ui-filter-field__label">Сайт</label>
                                <input v-model="companyForm.website" type="text" class="ui-input" />
                            </div>
                            <div>
                                <label class="ui-filter-field__label">Описание</label>
                                <textarea v-model="companyForm.description" rows="3" class="ui-input" />
                            </div>
                        </div>
                        <div class="flex shrink-0 justify-end gap-[var(--primitive-gap-sm)] border-t border-[var(--ui-border)] px-5 py-4">
                            <button type="button" class="ui-btn ui-btn--secondary" @click="closeCompany">Закрыть</button>
                            <button type="button" class="ui-btn ui-btn--primary" :disabled="companySaving || !companyForm.name.trim()" @click="saveCompany">Сохранить</button>
                        </div>
                    </div>
                </div>
            </div>
        </teleport>

        <DangerConfirmModal
            :open="companyDeleteDialogOpen"
            title="Удалить компанию?"
            :description="companyDeleteDescription"
            confirm-label="Удалить"
            :busy="companyDeleting"
            confirm-variant="danger"
            @close="closeCompanyDeleteDialog"
            @confirm="confirmDeleteCompany"
        />
    </AuthenticatedLayout>
</template>
