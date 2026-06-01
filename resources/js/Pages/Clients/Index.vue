<script setup lang="ts">
import ClientDetailModal from '@/Components/Clients/ClientDetailModal.vue';
import type { ClientListItem } from '@/Components/Clients/clientProfileTypes';
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
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
    activeTab: 'clients' | 'companies';
    clients: PaginationPayload<ClientListItem>;
    companies: PaginationPayload<CompanyItem>;
    companyOptions: CompanyOption[];
    canManageCompanies: boolean;
}>();

const { show: showToast } = useToastStore();

const search = ref(props.search || '');
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

let timer: ReturnType<typeof setTimeout> | null = null;
watch(search, (q) => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
        visitClients({ search: q || undefined, clients_page: undefined, companies_page: undefined });
    }, 250);
});

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

function visitClients(overrides: Record<string, string | number | undefined>): void {
    router.get(
        route('clients.index'),
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

function openClient(c: ClientListItem): void {
    openedContactId.value = c.id;
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
            <header class="shrink-0 border-b px-4 py-4 sm:px-6" :style="{ borderColor: 'var(--ui-border)', background: 'var(--ui-surface)' }">
                <div class="mb-3">
                    <h1 class="text-lg font-semibold" :style="{ color: 'var(--ui-text)' }">Клиенты</h1>
                    <p class="text-sm opacity-70">Карточки клиентов, профиль и AI-сводка</p>
                </div>
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="rounded-full px-4 py-2 text-sm font-medium"
                            :style="{ background: activeTab === 'clients' ? 'var(--ui-accent)' : 'var(--ui-surface-muted)', color: activeTab === 'clients' ? '#fff' : 'var(--ui-text)' }"
                            @click="activeTab = 'clients'"
                        >
                            Клиенты · {{ total }}
                        </button>
                        <button
                            v-if="canManageCompanies"
                            type="button"
                            class="rounded-full px-4 py-2 text-sm font-medium"
                            :style="{ background: activeTab === 'companies' ? 'var(--ui-accent)' : 'var(--ui-surface-muted)', color: activeTab === 'companies' ? '#fff' : 'var(--ui-text)' }"
                            @click="activeTab = 'companies'"
                        >
                            Компании · {{ companiesTotal }}
                        </button>
                        <button
                            v-if="activeTab === 'companies' && canManageCompanies"
                            type="button"
                            class="rounded-full px-4 py-2 text-sm"
                            :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                            @click="openCompany()"
                        >
                            + Компания
                        </button>
                    </div>
                    <input
                        v-model="search"
                        type="search"
                        class="w-full min-w-[240px] rounded-full border-0 px-4 py-2 text-sm focus:ring-0 focus:outline-none lg:max-w-sm"
                        :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                        :placeholder="activeTab === 'clients' ? 'Поиск по имени, номеру, WhatsApp ID' : 'Поиск по компаниям'"
                    />
                </div>
            </header>

            <div class="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6">
                <div v-if="activeTab === 'clients'" class="space-y-4">
                    <div class="text-xs opacity-70">
                        Показано {{ props.clients.from || 0 }}–{{ props.clients.to || 0 }} из {{ props.clients.total }}
                    </div>

                    <div v-if="clients.length === 0" class="py-16 text-center text-sm opacity-70">Клиенты не найдены</div>

                    <div v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <button
                            v-for="c in clients"
                            :key="c.id"
                            type="button"
                            class="rounded-2xl border p-4 text-left transition hover:shadow-sm focus:outline-none focus-visible:ring-2"
                            :style="{ background: 'var(--ui-surface)', borderColor: 'var(--ui-border)' }"
                            @click="openClient(c)"
                        >
                            <div class="mb-3 flex items-start gap-3">
                                <UserAvatar :name="displayName(c)" :src="c.profile_picture_url" :size="40" />
                                <div class="min-w-0 flex-1">
                                    <div class="truncate font-medium">{{ displayName(c) }}</div>
                                    <div class="truncate text-xs opacity-70">{{ formatPhone(c.phone_number) || '—' }}</div>
                                </div>
                                <span
                                    v-if="c.unread_count > 0"
                                    class="shrink-0 min-w-[20px] rounded-full px-1.5 py-0.5 text-center text-[10px] font-semibold"
                                    :style="{ background: 'var(--wa-unread)', color: 'var(--wa-unread-text)' }"
                                >
                                    {{ c.unread_count > 99 ? '99+' : c.unread_count }}
                                </span>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    v-if="c.stage"
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :style="{ background: c.stage.color || 'var(--ui-accent)', color: '#fff' }"
                                >
                                    {{ c.stage.name }}
                                </span>
                                <span class="text-xs opacity-60">{{ dateLabel(c.last_chat_at) }}</span>
                            </div>
                        </button>
                    </div>

                    <div v-if="props.clients.last_page > 1" class="flex items-center justify-between gap-3 pt-2">
                        <button type="button" class="rounded-lg px-3 py-2 text-sm disabled:opacity-40" :style="{ background: 'var(--ui-surface-muted)' }" :disabled="props.clients.current_page <= 1" @click="goToPage('clients', props.clients.current_page - 1)">Назад</button>
                        <span class="text-xs opacity-70">Страница {{ props.clients.current_page }} из {{ props.clients.last_page }}</span>
                        <button type="button" class="rounded-lg px-3 py-2 text-sm disabled:opacity-40" :style="{ background: 'var(--ui-surface-muted)' }" :disabled="props.clients.current_page >= props.clients.last_page" @click="goToPage('clients', props.clients.current_page + 1)">Вперёд</button>
                    </div>
                </div>

                <div v-else-if="canManageCompanies" class="space-y-4">
                    <div class="text-xs opacity-70">Показано {{ props.companies.from || 0 }}–{{ props.companies.to || 0 }} из {{ props.companies.total }}</div>
                    <div
                        v-for="company in companies"
                        :key="company.id"
                        class="rounded-2xl border p-4"
                        :style="{ background: 'var(--ui-surface)', borderColor: 'var(--ui-border)' }"
                    >
                        <div class="mb-2 flex items-start justify-between gap-3">
                            <div>
                                <div class="font-medium">{{ company.name }}</div>
                                <div class="text-xs opacity-70">Клиентов: {{ company.clients_count }}</div>
                            </div>
                            <div class="flex gap-2">
                                <button type="button" class="rounded-lg px-2 py-1 text-xs" :style="{ background: 'var(--ui-surface-muted)' }" @click="openCompany(company)">Изменить</button>
                                <button type="button" class="rounded-lg px-2 py-1 text-xs text-red-600" @click="requestDeleteCompany(company)">Удалить</button>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 gap-1 text-xs opacity-80 sm:grid-cols-2">
                            <div v-if="company.phone">Тел: {{ company.phone }}</div>
                            <div v-if="company.email">Email: {{ company.email }}</div>
                            <div v-if="company.website" class="sm:col-span-2">Сайт: {{ company.website }}</div>
                        </div>
                    </div>
                    <div v-if="props.companies.last_page > 1" class="flex items-center justify-between gap-3 pt-2">
                        <button type="button" class="rounded-lg px-3 py-2 text-sm disabled:opacity-40" :disabled="props.companies.current_page <= 1" @click="goToPage('companies', props.companies.current_page - 1)">Назад</button>
                        <span class="text-xs opacity-70">Страница {{ props.companies.current_page }} из {{ props.companies.last_page }}</span>
                        <button type="button" class="rounded-lg px-3 py-2 text-sm disabled:opacity-40" :disabled="props.companies.current_page >= props.companies.last_page" @click="goToPage('companies', props.companies.current_page + 1)">Вперёд</button>
                    </div>
                </div>
            </div>
        </div>

        <ClientDetailModal :client="openedClient" @close="closeClient" @saved="onClientSaved" />

        <teleport to="body">
            <div v-if="openedCompanyId !== null" class="fixed inset-0 z-[460] overflow-y-auto px-4 py-4 sm:py-8" :style="{ background: 'rgba(0,0,0,.45)' }" @click.self="closeCompany">
                <div class="mx-auto flex min-h-[calc(100%-2rem)] max-w-[520px] items-center justify-center">
                    <div class="flex w-full max-h-[min(90dvh,calc(100dvh-2rem))] flex-col rounded-2xl border overflow-hidden" :style="{ background: 'var(--ui-surface)', borderColor: 'var(--ui-border)' }">
                        <div class="shrink-0 px-5 py-4 flex items-center justify-between" :style="{ background: 'var(--ui-surface-muted)' }">
                            <div class="text-sm font-medium">{{ openedCompanyId === 'new' ? 'Новая компания' : `Компания: ${openedCompany?.name || ''}` }}</div>
                            <button type="button" class="w-9 h-9 rounded-full" @click="closeCompany">✕</button>
                        </div>
                        <div class="min-h-0 flex-1 overflow-y-auto p-5 space-y-4 text-sm">
                            <div>
                                <div class="mb-1 text-xs opacity-70">Название</div>
                                <input v-model="companyForm.name" type="text" class="w-full rounded-lg border-0 px-3 py-2" :style="{ background: 'var(--ui-surface-muted)' }" />
                            </div>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div><div class="mb-1 text-xs opacity-70">Телефон</div><input v-model="companyForm.phone" type="text" class="w-full rounded-lg border-0 px-3 py-2" :style="{ background: 'var(--ui-surface-muted)' }" /></div>
                                <div><div class="mb-1 text-xs opacity-70">Email</div><input v-model="companyForm.email" type="email" class="w-full rounded-lg border-0 px-3 py-2" :style="{ background: 'var(--ui-surface-muted)' }" /></div>
                            </div>
                            <div><div class="mb-1 text-xs opacity-70">Сайт</div><input v-model="companyForm.website" type="text" class="w-full rounded-lg border-0 px-3 py-2" :style="{ background: 'var(--ui-surface-muted)' }" /></div>
                            <div><div class="mb-1 text-xs opacity-70">Описание</div><textarea v-model="companyForm.description" rows="3" class="w-full resize-none rounded-lg border-0 px-3 py-2" :style="{ background: 'var(--ui-surface-muted)' }" /></div>
                        </div>
                        <div class="shrink-0 flex justify-end gap-2 border-t px-5 py-4" :style="{ borderColor: 'var(--ui-border)' }">
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
