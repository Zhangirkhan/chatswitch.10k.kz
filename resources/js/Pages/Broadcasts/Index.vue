<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import UiPillNav from '@/Components/Ui/UiPillNav.vue';
import UiViewTransition from '@/Components/Ui/UiViewTransition.vue';
import { formatPhone } from '@/utils/phone';

type SessionOpt = { id: number; label: string; phone_number: string | null; status: string };
type SenderOpt = { id: number; name: string };
type PreviewRow = {
    row: number;
    phone: string;
    message: string;
    status: 'ready' | 'skipped';
    contact_id: number | null;
    chat_id: number | null;
    contact_name: string | null;
    skip_reason: string | null;
};
type Campaign = {
    id: number;
    status: string;
    sent_count: number;
    skipped_count: number;
    failed_count: number;
    ready_count: number;
    total_rows: number;
    delay_seconds: number;
    started_at: string | null;
    completed_at: string | null;
    session: { id: number; label: string } | null;
    sender: { id: number; name: string } | null;
};

type RateLimitInfo = {
    max_per_day: number;
    delay_seconds: number;
    delay_seconds_min: number;
    delay_seconds_max: number;
    sent_last_day?: number;
    remaining?: number;
};

const props = defineProps<{
    sessions: SessionOpt[];
    senders: SenderOpt[];
    campaigns: Campaign[];
    rateLimit: RateLimitInfo;
}>();

const page = usePage<any>();
const isAdmin = computed(() => page.props.auth?.user?.roles?.includes('administrator'));

const mode = ref<'excel' | 'filters'>('excel');
const sessionId = ref<number | ''>(props.sessions[0]?.id ?? '');
const senderId = ref<number | ''>(props.senders[0]?.id ?? '');
const rateLimit = ref<RateLimitInfo>({ ...props.rateLimit });
const file = ref<File | null>(null);
const filterSearch = ref('');
const filterCompany = ref('');
const filterMessage = ref('');
const previewRows = ref<PreviewRow[]>([]);
const previewSummary = ref({ total: 0, ready: 0, skipped: 0 });
const campaigns = ref<Campaign[]>([...props.campaigns]);
const loadingPreview = ref(false);
const starting = ref(false);
const error = ref<string | null>(null);
const activeCampaignId = ref<number | null>(null);
const previewFetched = ref(false);
let pollTimer: ReturnType<typeof setInterval> | null = null;
let previewDebounceTimer: ReturnType<typeof setTimeout> | null = null;
let previewRequestId = 0;

const canPreview = computed(() => {
    if (!sessionId.value) {
        return false;
    }
    if (mode.value === 'excel') {
        return file.value !== null;
    }
    return filterMessage.value.trim() !== '';
});

function onFileChange(e: Event): void {
    const input = e.target as HTMLInputElement;
    file.value = input.files?.[0] ?? null;
    resetPreview();
}

function resetPreview(): void {
    previewRows.value = [];
    previewSummary.value = { total: 0, ready: 0, skipped: 0 };
    previewFetched.value = false;
}

function scheduleAutoPreview(): void {
    if (mode.value !== 'filters') {
        return;
    }
    resetPreview();
    if (!canPreview.value) {
        return;
    }
    if (previewDebounceTimer) {
        clearTimeout(previewDebounceTimer);
    }
    previewDebounceTimer = setTimeout(() => {
        void runPreview();
    }, 450);
}

watch([mode, sessionId, filterMessage, filterSearch, filterCompany], () => {
    if (mode.value === 'filters') {
        scheduleAutoPreview();
    } else {
        resetPreview();
    }
});

async function runPreview(): Promise<void> {
    if (!canPreview.value) {
        return;
    }
    error.value = null;
    loadingPreview.value = true;
    const requestId = ++previewRequestId;
    const form = new FormData();
    form.append('source', mode.value);
    form.append('whatsapp_session_id', String(sessionId.value));
    if (mode.value === 'excel' && file.value) {
        form.append('file', file.value);
    } else {
        form.append('filter_message', filterMessage.value);
        form.append('filters[search]', filterSearch.value);
        form.append('filters[company_name]', filterCompany.value);
    }

    try {
        const { data } = await axios.post(route('broadcasts.preview'), form, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (requestId !== previewRequestId) {
            return;
        }
        previewRows.value = data.rows ?? [];
        previewSummary.value = data.summary ?? { total: 0, ready: 0, skipped: 0 };
        previewFetched.value = true;
        if (data.rate_limit) {
            rateLimit.value = data.rate_limit;
        }
    } catch (e: unknown) {
        if (requestId !== previewRequestId) {
            return;
        }
        error.value = axios.isAxiosError(e) && typeof e.response?.data?.message === 'string'
            ? e.response.data.message
            : (e as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors
                ? Object.values((e as any).response.data.errors).flat().join(' ')
                : 'Не удалось построить предпросмотр.';
        resetPreview();
    } finally {
        if (requestId === previewRequestId) {
            loadingPreview.value = false;
        }
    }
}

async function startBroadcast(): Promise<void> {
    if (previewSummary.value.ready === 0) {
        error.value = 'Нет получателей для отправки. Сначала проверьте предпросмотр.';
        return;
    }
    error.value = null;
    starting.value = true;
    const form = new FormData();
    form.append('source', mode.value);
    form.append('whatsapp_session_id', String(sessionId.value));
    form.append('sender_user_id', String(senderId.value));
    if (mode.value === 'excel' && file.value) {
        form.append('file', file.value);
    } else {
        form.append('filter_message', filterMessage.value);
        form.append('filters[search]', filterSearch.value);
        form.append('filters[company_name]', filterCompany.value);
    }

    try {
        const { data } = await axios.post(route('broadcasts.store'), form, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (data.campaign) {
            campaigns.value = [data.campaign, ...campaigns.value];
            activeCampaignId.value = data.campaign.id;
            startPolling(data.campaign.id);
        }
        previewRows.value = [];
    } catch (e: unknown) {
        error.value = axios.isAxiosError(e)
            ? (e.response?.data?.message
                ?? Object.values(e.response?.data?.errors ?? {}).flat().join(' '))
            : 'Не удалось запустить рассылку.';
    } finally {
        starting.value = false;
    }
}

function startPolling(id: number): void {
    stopPolling();
    void refreshCampaign(id);
    pollTimer = setInterval(() => void refreshCampaign(id), 4000);
}

function stopPolling(): void {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

async function refreshCampaign(id: number): Promise<void> {
    try {
        const { data } = await axios.get(route('broadcasts.show', id));
        const c = data.campaign as Campaign;
        const idx = campaigns.value.findIndex((x) => x.id === id);
        if (idx >= 0) {
            campaigns.value[idx] = c;
        }
        if (c.status === 'completed') {
            stopPolling();
        }
    } catch {
        /* ignore poll errors */
    }
}

function statusLabel(status: string): string {
    return ({
        pending: 'Ожидает',
        running: 'Идёт отправка',
        completed: 'Завершена',
        cancelled: 'Отменена',
    } as Record<string, string>)[status] ?? status;
}
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Рассылки" />

        <div class="app-page">
            <div class="app-page__scroll wa-scrollbar">
            <div class="app-page__content max-w-4xl space-y-6">
                <header>
                    <h1 class="text-xl font-semibold text-[var(--wa-text)]">Рассылки</h1>
                    <p class="text-sm text-[var(--wa-text-secondary)] mt-1">
                        Отправка только в <strong>закрытые (архивные)</strong> чаты с найденными клиентами.
                        Excel: столбец 1 — номер, столбец 2 — текст. Между сообщениями — автозадержка.
                    </p>
                </header>

                <p v-if="error" class="text-sm text-red-500 rounded-lg border border-red-500/30 px-3 py-2">{{ error }}</p>

                <section class="ui-panel p-4 space-y-4">
                    <UiPillNav class="max-w-md">
                        <button
                            type="button"
                            class="ui-pill-nav__item"
                            :class="{ 'is-active': mode === 'excel' }"
                            @click="mode = 'excel'"
                        >
                            Файл Excel/CSV
                        </button>
                        <button
                            type="button"
                            class="ui-pill-nav__item"
                            :class="{ 'is-active': mode === 'filters' }"
                            @click="mode = 'filters'"
                        >
                            По фильтрам
                        </button>
                    </UiPillNav>

                    <div class="grid md:grid-cols-2 gap-4">
                        <label class="block text-sm">
                            <span class="text-[var(--wa-text-secondary)]">WhatsApp-номер (от кого)</span>
                            <select
                                v-model="sessionId"
                                class="ui-input mt-1"
                            >
                                <option v-for="s in sessions" :key="s.id" :value="s.id">{{ s.label }}</option>
                            </select>
                        </label>
                        <label class="block text-sm">
                            <span class="text-[var(--wa-text-secondary)]">От имени сотрудника</span>
                            <select
                                v-model="senderId"
                                class="ui-input mt-1"
                                :disabled="!isAdmin"
                            >
                                <option v-for="u in senders" :key="u.id" :value="u.id">{{ u.name }}</option>
                            </select>
                        </label>
                        <div
                            class="md:col-span-2 text-sm rounded-lg border px-3 py-2.5"
                            :style="{ borderColor: 'var(--wa-border)', color: 'var(--wa-text-secondary)' }"
                        >
                            <p class="font-medium text-[var(--wa-text)]">Скорость отправки — автоматически</p>
                            <p class="mt-1">
                                Случайная пауза {{ rateLimit.delay_seconds_min }}–{{ rateLimit.delay_seconds_max }} сек между сообщениями
                                (не более {{ rateLimit.max_per_day }} в сутки с одного WhatsApp-номера).
                            </p>
                            <p v-if="rateLimit.remaining !== undefined" class="mt-1">
                                Сейчас можно отправить ещё: <strong>{{ rateLimit.remaining }}</strong>
                                <span v-if="rateLimit.sent_last_day !== undefined">
                                    (уже {{ rateLimit.sent_last_day }} за последние 24 часа)
                                </span>
                            </p>
                        </div>
                    </div>

                    <UiViewTransition :transition-key="mode">
                        <div v-if="mode === 'excel'">
                            <label class="block text-sm text-[var(--wa-text-secondary)]">
                                Файл .xlsx или .csv (номер + текст)
                                <input
                                    type="file"
                                    accept=".xlsx,.csv"
                                    class="mt-2 block w-full text-sm"
                                    @change="onFileChange"
                                />
                            </label>
                        </div>

                        <div v-else class="space-y-3">
                            <label class="block text-sm">
                                <span class="text-[var(--wa-text-secondary)]">Текст для всех</span>
                                <textarea
                                    v-model="filterMessage"
                                    rows="3"
                                    class="ui-input mt-1 w-full resize-y min-h-[4.5rem]"
                                    placeholder="Сообщение для отфильтрованных закрытых чатов"
                                />
                            </label>
                            <div class="grid md:grid-cols-2 gap-3">
                                <input
                                    v-model="filterSearch"
                                    type="text"
                                    placeholder="Поиск по имени/телефону"
                                    class="ui-input"
                                />
                                <input
                                    v-model="filterCompany"
                                    type="text"
                                    placeholder="Компания"
                                    class="ui-input"
                                />
                            </div>
                        </div>
                    </UiViewTransition>

                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            v-if="mode === 'excel'"
                            type="button"
                            class="ui-btn ui-btn--secondary"
                            :disabled="!canPreview || loadingPreview"
                            @click="runPreview"
                        >
                            {{ loadingPreview ? 'Проверяю…' : 'Предпросмотр' }}
                        </button>
                        <button
                            type="button"
                            class="ui-btn ui-btn--primary"
                            :disabled="starting || loadingPreview || previewSummary.ready === 0"
                            @click="startBroadcast"
                        >
                            {{ starting ? 'Запуск…' : loadingPreview ? 'Считаю…' : `Отправить (${previewSummary.ready})` }}
                        </button>
                        <span
                            v-if="mode === 'filters' && loadingPreview"
                            class="text-xs text-[var(--wa-text-secondary)]"
                        >
                            Ищем в архиве…
                        </span>
                    </div>

                    <p
                        v-if="mode === 'filters' && previewFetched && !loadingPreview && previewSummary.total === 0"
                        class="text-sm rounded-lg border px-3 py-2"
                        :style="{ borderColor: 'var(--wa-border)', color: 'var(--wa-text-secondary)' }"
                    >
                        Не найдено закрытых чатов по фильтрам на выбранном WhatsApp-номере.
                        Оставьте поиск пустым, чтобы увидеть все архивные чаты.
                    </p>
                </section>

                <section v-if="previewRows.length" class="ui-panel ui-table-panel overflow-hidden p-0">
                    <div class="px-4 py-2 text-sm border-b" :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel-header)' }">
                        Предпросмотр: {{ previewSummary.ready }} к отправке, {{ previewSummary.skipped }} пропущено
                    </div>
                    <div class="max-h-80 overflow-y-auto text-sm">
                        <table class="w-full">
                            <thead class="text-left text-xs text-[var(--wa-text-secondary)] sticky top-0" :style="{ background: 'var(--wa-sidebar-bg)' }">
                                <tr>
                                    <th class="px-3 py-2">#</th>
                                    <th class="px-3 py-2">Номер</th>
                                    <th class="px-3 py-2">Клиент</th>
                                    <th class="px-3 py-2">Статус</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="r in previewRows"
                                    :key="r.row"
                                    class="border-t"
                                    :style="{ borderColor: 'var(--wa-border)' }"
                                >
                                    <td class="px-3 py-2">{{ r.row }}</td>
                                    <td class="px-3 py-2">{{ formatPhone(r.phone) || r.phone }}</td>
                                    <td class="px-3 py-2">{{ r.contact_name || '—' }}</td>
                                    <td class="px-3 py-2">
                                        <span v-if="r.status === 'ready'" class="text-green-600">Отправим</span>
                                        <span v-else class="text-[var(--wa-text-secondary)]" :title="r.skip_reason ?? ''">Пропуск</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section v-if="campaigns.length" class="space-y-3">
                    <h2 class="text-sm font-semibold text-[var(--wa-text)]">Последние рассылки</h2>
                    <div
                        v-for="c in campaigns"
                        :key="c.id"
                        class="ui-panel p-4 text-sm"
                    >
                        <div class="flex justify-between gap-2 flex-wrap">
                            <span class="font-medium">#{{ c.id }} · {{ statusLabel(c.status) }}</span>
                            <span class="text-[var(--wa-text-secondary)]">{{ c.session?.label }} · {{ c.sender?.name }}</span>
                        </div>
                        <p class="mt-2 text-[var(--wa-text-secondary)]">
                            Отправлено: {{ c.sent_count }} / {{ c.ready_count }},
                            пропущено: {{ c.skipped_count }},
                            ошибок: {{ c.failed_count }}
                            <span v-if="c.delay_seconds"> · случайная пауза ~{{ c.delay_seconds }}с</span>
                        </p>
                        <button
                            v-if="c.status === 'running'"
                            type="button"
                            class="mt-2 text-xs underline"
                            :style="{ color: 'var(--wa-accent)' }"
                            @click="startPolling(c.id)"
                        >
                            Обновить статус
                        </button>
                    </div>
                </section>
            </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

