<script setup lang="ts">
import UiFilterField from '@/Components/Ui/UiFilterField.vue';
import UiFilterPanel from '@/Components/Ui/UiFilterPanel.vue';
import UiPagination from '@/Components/Ui/UiPagination.vue';
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import CompaniesIndexRow, { type CompanyIndexRow } from '@/Pages/SuperAdmin/Companies/Partials/CompaniesIndexRow.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Paginated<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

const props = defineProps<{
    companies: Paginated<CompanyIndexRow>;
    demoCompany: CompanyIndexRow | null;
    demoSlug: string;
    filters: {
        q: string;
        is_active: string;
        subscription_status: string;
        plan_id: string;
        sort: string;
    };
    plans: Array<{ id: number; name: string }>;
    isSandboxSuperAdmin?: boolean;
}>();

const page = usePage();
const rootDomain = computed(() => (page.props.rootDomain as string | undefined) ?? 'accel.kz');

const filterForm = useForm({
    q: props.filters.q,
    is_active: props.filters.is_active,
    subscription_status: props.filters.subscription_status,
    plan_id: props.filters.plan_id,
    sort: props.filters.sort,
});

function applyFilters(): void {
    filterForm.get('/companies', { preserveState: true, preserveScroll: true });
}

const toggleTarget = ref<CompanyIndexRow | null>(null);
const showToggleConfirm = ref(false);
const showSeedConfirm = ref(false);
const showPopulateDemoConfirm = ref(false);
const showDeleteAllConfirm = ref(false);
const bulkBusy = ref(false);

function requestToggle(c: CompanyIndexRow): void {
    toggleTarget.value = c;
    showToggleConfirm.value = true;
}

function confirmToggle(): void {
    const c = toggleTarget.value;
    if (!c) return;
    router.patch(`/companies/${c.id}/toggle-active`, {}, {
        preserveScroll: true,
        onFinish: () => {
            showToggleConfirm.value = false;
            toggleTarget.value = null;
        },
    });
}

const toggleConfirmDescription = computed(() => {
    const c = toggleTarget.value;
    if (!c) return '';
    return c.is_active
        ? `Отключить тенанта «${c.name}»? Поддомен ${c.slug}.${rootDomain.value} покажет страницу «Сайт отключён».`
        : `Включить тенанта «${c.name}»?`;
});

function populateDemoTenant(): void {
    bulkBusy.value = true;
    router.post('/companies/populate-demo', {}, {
        preserveScroll: true,
        onFinish: () => {
            bulkBusy.value = false;
            showPopulateDemoConfirm.value = false;
        },
    });
}

function seedTestData(): void {
    bulkBusy.value = true;
    router.post('/companies/seed-test-data', {}, {
        preserveScroll: true,
        onFinish: () => {
            bulkBusy.value = false;
            showSeedConfirm.value = false;
        },
    });
}

function deleteAllExceptDemo(): void {
    bulkBusy.value = true;
    router.delete('/companies/non-demo', {
        preserveScroll: true,
        onFinish: () => {
            bulkBusy.value = false;
            showDeleteAllConfirm.value = false;
        },
    });
}
</script>

<template>
    <SuperAdminLayout>
        <Head title="Компании" />
        <h1 class="mb-6 text-xl font-bold sm:text-2xl">Компании</h1>

        <section v-if="isSandboxSuperAdmin" class="mb-6 ui-panel p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-ui-text-secondary">
                Песочница продаж
            </h2>
            <p class="mt-1 text-sm text-ui-text-muted">
                Здесь только ваши тестовые компании. Создайте компанию, нажмите «Заполнить тестовыми данными» в карточке, затем «Войти в тенант» для проверки чатов и AI.
            </p>
        </section>

        <section v-else-if="demoCompany" class="mb-6">
            <div class="mb-2 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-ui-text-secondary">
                        Демо-тенант
                    </h2>
                    <p class="mt-0.5 text-sm text-ui-text-muted">
                        Постоянный тенант <span class="font-mono">{{ demoSlug }}.{{ rootDomain }}</span> — не удаляется массовыми операциями.
                    </p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    <button
                        type="button"
                        class="ui-btn ui-btn--primary ui-btn--sm"
                        :disabled="bulkBusy"
                        @click="showPopulateDemoConfirm = true"
                    >
                        Заполнить демо
                    </button>
                    <button
                        type="button"
                        class="ui-btn ui-btn--secondary ui-btn--sm"
                        :disabled="bulkBusy"
                        @click="showSeedConfirm = true"
                    >
                        + тестовые компании
                    </button>
                </div>
            </div>
            <div class="ui-panel ui-table-panel overflow-hidden p-0">
                <table class="min-w-[720px] w-full text-left text-sm">
                    <thead>
                        <tr>
                            <th>Название</th>
                            <th>Поддомен</th>
                            <th>Подписка</th>
                            <th>Тариф</th>
                            <th>Триал до</th>
                            <th class="text-right">Действия</th>
                            <th class="text-right">Активен</th>
                        </tr>
                    </thead>
                    <tbody>
                        <CompaniesIndexRow
                            :company="demoCompany"
                            :root-domain="rootDomain"
                            is-demo
                            @toggle="requestToggle"
                        />
                    </tbody>
                </table>
            </div>
        </section>

        <UiFilterPanel class="mb-4" @submit="applyFilters">
            <UiFilterField label="Поиск" wide>
                <input
                    v-model="filterForm.q"
                    type="search"
                    placeholder="Название, slug, email владельца"
                    class="ui-input"
                />
            </UiFilterField>
            <UiFilterField label="Активность">
                <select v-model="filterForm.is_active" class="ui-select">
                    <option value="">Все</option>
                    <option value="1">Активные</option>
                    <option value="0">Отключённые</option>
                </select>
            </UiFilterField>
            <UiFilterField label="Подписка">
                <select v-model="filterForm.subscription_status" class="ui-select">
                    <option value="">Все</option>
                    <option value="trial">Триал</option>
                    <option value="active">Активна</option>
                    <option value="past_due">Просрочена</option>
                    <option value="suspended">Приостановлена</option>
                    <option value="canceled">Отменена</option>
                </select>
            </UiFilterField>
            <UiFilterField label="Тариф">
                <select v-model="filterForm.plan_id" class="ui-select">
                    <option value="">Все</option>
                    <option v-for="p in plans" :key="p.id" :value="String(p.id)">{{ p.name }}</option>
                </select>
            </UiFilterField>
            <UiFilterField label="Сортировка">
                <select v-model="filterForm.sort" class="ui-select">
                    <option value="created_desc">Сначала новые</option>
                    <option value="created_asc">Сначала старые</option>
                    <option value="name">По названию</option>
                </select>
            </UiFilterField>
            <template #actions>
                <button type="submit" class="ui-btn ui-btn--secondary ui-btn--sm" :disabled="filterForm.processing">
                    Применить
                </button>
            </template>
        </UiFilterPanel>

        <div class="ui-panel ui-table-panel overflow-hidden p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ui-border px-4 py-2.5">
                <div class="text-sm font-medium text-ui-text-secondary">
                    Клиентские тенанты
                    <span v-if="companies.total > 0" class="text-ui-text-muted">({{ companies.total }})</span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-if="!isSandboxSuperAdmin"
                        type="button"
                        class="ui-btn ui-btn--danger-ghost ui-btn--sm"
                        :disabled="bulkBusy"
                        @click="showDeleteAllConfirm = true"
                    >
                        Удалить все
                    </button>
                    <Link href="/companies/create" class="ui-btn ui-btn--primary ui-btn--sm">Создать</Link>
                </div>
            </div>
            <table class="min-w-[720px] w-full text-left text-sm">
                <thead>
                    <tr>
                        <th>Название</th>
                        <th>Поддомен</th>
                        <th>Подписка</th>
                        <th>Тариф</th>
                        <th>Триал до</th>
                        <th class="text-right">Действия</th>
                        <th class="text-right">Активен</th>
                    </tr>
                </thead>
                <tbody>
                    <CompaniesIndexRow
                        v-for="c in companies.data"
                        :key="c.id"
                        :company="c"
                        :root-domain="rootDomain"
                        @toggle="requestToggle"
                    />
                    <tr v-if="companies.data.length === 0">
                        <td colspan="7" class="!py-8 text-center text-ui-text-muted">
                            {{ isSandboxSuperAdmin ? 'Создайте первую тестовую компанию' : 'Клиентские компании не найдены' }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <UiPagination
                :links="companies.links"
                :from="companies.from"
                :to="companies.to"
                :total="companies.total"
            />
        </div>

        <DangerConfirmModal
            :open="showToggleConfirm"
            title="Переключить тенант?"
            :description="toggleConfirmDescription"
            :confirm-label="toggleTarget?.is_active ? 'Отключить' : 'Включить'"
            confirm-variant="primary"
            @close="showToggleConfirm = false"
            @confirm="confirmToggle"
        />

        <DangerConfirmModal
            :open="showPopulateDemoConfirm"
            title="Заполнить демо-тенант?"
            :description="`Пересоздаст данные в «${demoSlug}.${rootDomain}»: отделы, воронку, каталог, 10 чатов с этапами, 3 WhatsApp-номера. Старые чаты демо будут удалены.`"
            confirm-label="Заполнить"
            confirm-variant="primary"
            :busy="bulkBusy"
            @close="showPopulateDemoConfirm = false"
            @confirm="populateDemoTenant"
        />

        <DangerConfirmModal
            :open="showSeedConfirm"
            title="Создать тестовые компании?"
            description="Будут созданы отдельные тенанты (кофейня, салон, медцентр и др.), если slug свободен. Демо-тенант не затрагивается."
            confirm-label="Создать"
            confirm-variant="primary"
            :busy="bulkBusy"
            @close="showSeedConfirm = false"
            @confirm="seedTestData"
        />

        <DangerConfirmModal
            :open="showDeleteAllConfirm"
            title="Удалить все компании?"
            :description="`Будут удалены все клиентские тенанты (${companies.total} в списке). Демо-тенант «${demoSlug}» останется.`"
            confirm-label="Удалить все"
            confirm-variant="danger"
            :busy="bulkBusy"
            @close="showDeleteAllConfirm = false"
            @confirm="deleteAllExceptDemo"
        />
    </SuperAdminLayout>
</template>
