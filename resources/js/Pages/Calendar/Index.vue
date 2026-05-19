<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';

// ─── Types ───────────────────────────────────────────────────────────────────

type Recurrence = 'daily' | 'weekly' | 'monthly' | 'yearly' | null;
type ViewMode   = 'month' | 'week';
type ListFilter = 'all' | 'mine' | 'assigned_to_me';

interface CalUserRef {
    id: number;
    name: string;
}

interface CalEvent {
    id: number;
    user_id: number;
    owner: CalUserRef | null;
    assignee_user_id: number | null;
    assignee: CalUserRef | null;
    title: string;
    description: string | null;
    color: string;
    starts_at: string;
    ends_at: string;
    all_day: boolean;
    recurrence: Recurrence;
    recurrence_ends_at: string | null;
    recurrence_instance?: boolean;
    source?: string | null;
    chat?: { id: number; name: string | null } | null;
    contact?: { id: number; name: string | null; phone_number: string | null } | null;
}

const page = usePage<any>();
const assignableUsers = computed<CalUserRef[]>(() => page.props.assignableUsers ?? []);

// ─── State ───────────────────────────────────────────────────────────────────

const view      = ref<ViewMode>('month');
const today     = new Date();
const cursor    = ref(new Date(today.getFullYear(), today.getMonth(), 1));
const events    = ref<CalEvent[]>([]);
const loading   = ref(false);

const listFilter = ref<ListFilter>('all');
const filterAuthorId = ref('');
const filterAssigneeId = ref('');

// ─── Modal form ──────────────────────────────────────────────────────────────

const PALETTE = ['#01b964','#34d399','#22d3ee','#3b82f6','#6366f1','#8b5cf6','#ec4899','#ef4444','#f97316','#f59e0b','#84cc16'];

const showModal  = ref(false);
const editingId  = ref<number | null>(null);
const deleteDialogOpen = ref(false);
/** Метаданные открытой записи (AI, чат, контакт) — только для подсказки в модалке. */
const editingMeta = ref<{ source?: string | null; chat?: CalEvent['chat']; contact?: CalEvent['contact'] } | null>(null);
const saving     = ref(false);
const formError  = ref<string | null>(null);

const form = ref({
    title: '',
    description: '',
    color: '#01b964',
    starts_at: '',
    ends_at: '',
    all_day: false,
    recurrence: '' as Recurrence | '',
    recurrence_ends_at: '',
    assignee_user_id: '' as string,
});

function emptyForm(date?: Date) {
    const d = date ?? new Date();
    const pad = (n: number) => String(n).padStart(2, '0');
    const local = (dt: Date) =>
        `${dt.getFullYear()}-${pad(dt.getMonth()+1)}-${pad(dt.getDate())}T${pad(dt.getHours())}:${pad(dt.getMinutes())}`;

    const start = new Date(d);
    start.setMinutes(0, 0, 0);
    const end = new Date(start);
    end.setHours(end.getHours() + 1);

    return {
        title: '',
        description: '',
        color: '#01b964',
        starts_at: local(start),
        ends_at: local(end),
        all_day: false,
        recurrence: '' as Recurrence | '',
        recurrence_ends_at: '',
        assignee_user_id: '',
    };
}

function openCreate(date?: Date) {
    editingId.value = null;
    editingMeta.value = null;
    form.value = emptyForm(date);
    formError.value = null;
    showModal.value = true;
}

function openEdit(ev: CalEvent) {
    editingId.value = ev.recurrence_instance ? null : ev.id;
    editingMeta.value = { source: ev.source ?? null, chat: ev.chat, contact: ev.contact };
    const toLocal = (iso: string) => {
        const d = new Date(iso);
        const pad = (n: number) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    };
    form.value = {
        title: ev.title,
        description: ev.description ?? '',
        color: ev.color,
        starts_at: toLocal(ev.starts_at),
        ends_at: toLocal(ev.ends_at),
        all_day: ev.all_day,
        recurrence: ev.recurrence ?? '',
        recurrence_ends_at: ev.recurrence_ends_at ?? '',
        assignee_user_id: ev.assignee_user_id != null ? String(ev.assignee_user_id) : '',
    };
    formError.value = null;
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
}

async function saveEvent() {
    if (saving.value) return;
    if (!form.value.title.trim()) { formError.value = 'Введите название.'; return; }
    saving.value = true;
    formError.value = null;

    const payload = {
        ...form.value,
        recurrence: form.value.recurrence || null,
        recurrence_ends_at: form.value.recurrence_ends_at || null,
        assignee_user_id: form.value.assignee_user_id === '' ? null : Number(form.value.assignee_user_id),
    };

    try {
        if (editingId.value) {
            await axios.put(route('calendar.events.update', editingId.value), payload);
        } else {
            await axios.post(route('calendar.events.store'), payload);
        }
        closeModal();
        await loadEvents();
    } catch (e: any) {
        formError.value = e?.response?.data?.message || 'Ошибка сохранения.';
    } finally {
        saving.value = false;
    }
}

function requestDeleteEvent(): void {
    if (!editingId.value) return;
    deleteDialogOpen.value = true;
}

async function confirmDeleteEvent(): Promise<void> {
    const id = editingId.value;
    if (!id) return;
    deleteDialogOpen.value = false;
    try {
        await axios.delete(route('calendar.events.destroy', id));
        closeModal();
        await loadEvents();
    } catch {
        alert('Не удалось удалить запись.');
    }
}

// ─── Date helpers ─────────────────────────────────────────────────────────────

const MONTHS_RU = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
const DAYS_SHORT = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];

function isSameDay(a: Date, b: Date) {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

function isToday(d: Date) { return isSameDay(d, today); }

function startOfWeek(d: Date): Date {
    const result = new Date(d);
    const dow = result.getDay();
    const diff = dow === 0 ? -6 : 1 - dow; // Mon first
    result.setDate(result.getDate() + diff);
    result.setHours(0, 0, 0, 0);
    return result;
}

function formatTime(iso: string): string {
    const d = new Date(iso);
    return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

function formatHour(h: number): string {
    return `${String(h).padStart(2,'0')}:00`;
}

// ─── Month view ───────────────────────────────────────────────────────────────

const monthTitle = computed(() => `${MONTHS_RU[cursor.value.getMonth()]} ${cursor.value.getFullYear()}`);

const monthDays = computed<Date[]>(() => {
    const year = cursor.value.getFullYear();
    const month = cursor.value.getMonth();

    const firstDay = new Date(year, month, 1);
    const lastDay  = new Date(year, month + 1, 0);

    // Fill leading days from prev month (Mon-first grid)
    const leadDow = firstDay.getDay() === 0 ? 6 : firstDay.getDay() - 1;
    const days: Date[] = [];
    for (let i = leadDow; i > 0; i--) {
        days.push(new Date(year, month, 1 - i));
    }
    for (let d = 1; d <= lastDay.getDate(); d++) {
        days.push(new Date(year, month, d));
    }
    // Fill trailing days to complete 6-week grid
    const trail = 42 - days.length;
    for (let d = 1; d <= trail; d++) {
        days.push(new Date(year, month + 1, d));
    }
    return days;
});

function eventsOnDay(day: Date): CalEvent[] {
    return events.value.filter((ev) => {
        const s = new Date(ev.starts_at);
        const e = new Date(ev.ends_at);
        const dayStart = new Date(day); dayStart.setHours(0, 0, 0, 0);
        const dayEnd   = new Date(day); dayEnd.setHours(23, 59, 59, 999);
        return s <= dayEnd && e >= dayStart;
    }).sort((a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime());
}

function prevMonth() {
    const d = new Date(cursor.value);
    d.setMonth(d.getMonth() - 1);
    cursor.value = d;
}
function nextMonth() {
    const d = new Date(cursor.value);
    d.setMonth(d.getMonth() + 1);
    cursor.value = d;
}
function goToday() {
    cursor.value = new Date(today.getFullYear(), today.getMonth(), 1);
}

// ─── Week view ────────────────────────────────────────────────────────────────

const HOURS = Array.from({ length: 24 }, (_, i) => i);
const CELL_H = 60; // px per hour

const weekStart = computed<Date>(() => {
    if (view.value === 'week') return startOfWeek(cursor.value);
    return startOfWeek(new Date(today));
});

const weekDays = computed<Date[]>(() => {
    const out: Date[] = [];
    for (let i = 0; i < 7; i++) {
        const d = new Date(weekStart.value);
        d.setDate(d.getDate() + i);
        out.push(d);
    }
    return out;
});

const weekTitle = computed(() => {
    const s = weekDays.value[0];
    const e = weekDays.value[6];
    if (s.getMonth() === e.getMonth()) {
        return `${s.getDate()}–${e.getDate()} ${MONTHS_RU[s.getMonth()]} ${s.getFullYear()}`;
    }
    return `${s.getDate()} ${MONTHS_RU[s.getMonth()]} – ${e.getDate()} ${MONTHS_RU[e.getMonth()]} ${e.getFullYear()}`;
});

function prevWeek() {
    const d = new Date(cursor.value);
    d.setDate(d.getDate() - 7);
    cursor.value = d;
}
function nextWeek() {
    const d = new Date(cursor.value);
    d.setDate(d.getDate() + 7);
    cursor.value = d;
}

/** Events overlapping a specific hour on a specific day (week view). */
function eventsInHour(day: Date, hour: number): CalEvent[] {
    return events.value.filter((ev) => {
        if (ev.all_day) return false;
        const s = new Date(ev.starts_at);
        const e = new Date(ev.ends_at);
        const cellStart = new Date(day); cellStart.setHours(hour, 0, 0, 0);
        const cellEnd   = new Date(day); cellEnd.setHours(hour, 59, 59, 999);
        return isSameDay(s, day) && s <= cellEnd && e >= cellStart;
    });
}

/** Top offset (px) and height (px) for an event chip in week view */
function eventStyle(ev: CalEvent, hour: number): Record<string, string> {
    const s = new Date(ev.starts_at);
    const e = new Date(ev.ends_at);
    const startMin = s.getHours() * 60 + s.getMinutes();
    const endMin   = Math.min(e.getHours() * 60 + e.getMinutes(), hour * 60 + 59);
    const top    = ((startMin - hour * 60) / 60) * CELL_H;
    const height = Math.max(((endMin - startMin) / 60) * CELL_H, 20);
    return { top: `${top}px`, height: `${height}px` };
}

// ─── Data loading ─────────────────────────────────────────────────────────────

const rangeStart = computed<string>(() => {
    if (view.value === 'month') {
        // Include leading/trailing days visible in the grid
        const d = new Date(cursor.value.getFullYear(), cursor.value.getMonth(), 1);
        const leadDow = d.getDay() === 0 ? 6 : d.getDay() - 1;
        d.setDate(d.getDate() - leadDow);
        return d.toISOString().slice(0, 10);
    }
    return weekStart.value.toISOString().slice(0, 10);
});

const rangeEnd = computed<string>(() => {
    if (view.value === 'month') {
        const d = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + 1, 0);
        const leadDow = d.getDay() === 0 ? 6 : d.getDay() - 1;
        d.setDate(d.getDate() + (6 - leadDow));
        return d.toISOString().slice(0, 10);
    }
    const end = new Date(weekStart.value);
    end.setDate(end.getDate() + 6);
    return end.toISOString().slice(0, 10);
});

async function loadEvents() {
    loading.value = true;
    try {
        const params: Record<string, string> = {
            start: rangeStart.value,
            end: rangeEnd.value,
        };
        if (listFilter.value !== 'all') {
            params.filter = listFilter.value;
        }
        if (filterAuthorId.value) {
            params.author_id = filterAuthorId.value;
        }
        if (filterAssigneeId.value) {
            params.assignee_id = filterAssigneeId.value;
        }
        const { data } = await axios.get(route('calendar.events'), { params });
        events.value = data;
    } catch {
        //
    } finally {
        loading.value = false;
    }
}

watch([rangeStart, rangeEnd], loadEvents);
watch([listFilter, filterAuthorId, filterAssigneeId], loadEvents);
onMounted(() => {
    loadEvents();
    const params = new URLSearchParams(window.location.search);
    if (params.get('create') !== '1') {
        return;
    }
    const f = emptyForm();
    const title = params.get('title');
    const description = params.get('description');
    const assignee = params.get('assignee_user_id');
    if (title) {
        f.title = title;
    }
    if (description) {
        f.description = description;
    }
    if (assignee) {
        f.assignee_user_id = assignee;
    }
    editingId.value = null;
    editingMeta.value = null;
    form.value = f;
    formError.value = null;
    showModal.value = true;
});

// Switch view: set cursor to week containing today (or current month start)
function switchView(v: ViewMode) {
    view.value = v;
    if (v === 'week') {
        cursor.value = startOfWeek(today);
    } else {
        cursor.value = new Date(today.getFullYear(), today.getMonth(), 1);
    }
}

// Recurrence label
const recurrenceLabels: Record<string, string> = {
    daily: 'Каждый день',
    weekly: 'Каждую неделю',
    monthly: 'Каждый месяц',
    yearly: 'Каждый год',
};
</script>

<template>
    <Head title="Календарь" />
    <AuthenticatedLayout>
        <div class="cal-wrapper">

            <!-- ── Toolbar ───────────────────────────────────────────── -->
            <div class="cal-toolbar">
                <div class="cal-toolbar-left">
                    <button class="cal-btn-today" @click="goToday">Сегодня</button>
                    <button class="cal-nav-btn" @click="view === 'month' ? prevMonth() : prevWeek()">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </button>
                    <button class="cal-nav-btn" @click="view === 'month' ? nextMonth() : nextWeek()">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                    </button>
                    <span class="cal-title">{{ view === 'month' ? monthTitle : weekTitle }}</span>
                    <div v-if="loading" class="cal-loading">
                        <svg class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" d="M21 12a9 9 0 11-9-9"/>
                        </svg>
                    </div>
                </div>
                <div class="cal-toolbar-right">
                    <div class="cal-view-switch">
                        <button :class="['cal-view-btn', view === 'month' ? 'active' : '']" @click="switchView('month')">Месяц</button>
                        <button :class="['cal-view-btn', view === 'week' ? 'active' : '']" @click="switchView('week')">Неделя</button>
                    </div>
                    <button class="cal-add-btn" @click="openCreate()">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                        </svg>
                        Запись
                    </button>
                </div>
            </div>

            <div class="cal-filters">
                <label class="cal-filter">
                    <span class="cal-filter-label">Обзор</span>
                    <select v-model="listFilter" class="cal-filter-select">
                        <option value="all">Все доступные</option>
                        <option value="mine">Мои записи</option>
                        <option value="assigned_to_me">Я ответственный</option>
                    </select>
                </label>
                <label class="cal-filter">
                    <span class="cal-filter-label">Автор</span>
                    <select v-model="filterAuthorId" class="cal-filter-select">
                        <option value="">Все авторы</option>
                        <option v-for="u in assignableUsers" :key="'af-' + u.id" :value="String(u.id)">{{ u.name }}</option>
                    </select>
                </label>
                <label class="cal-filter">
                    <span class="cal-filter-label">Ответственный</span>
                    <select v-model="filterAssigneeId" class="cal-filter-select">
                        <option value="">Все</option>
                        <option v-for="u in assignableUsers" :key="'gf-' + u.id" :value="String(u.id)">{{ u.name }}</option>
                    </select>
                </label>
            </div>

            <!-- ── Month view ────────────────────────────────────────── -->
            <div v-if="view === 'month'" class="cal-month">
                <!-- Day headers -->
                <div class="cal-month-header">
                    <div v-for="d in DAYS_SHORT" :key="d" class="cal-month-dayname">{{ d }}</div>
                </div>
                <!-- Grid -->
                <div class="cal-month-grid">
                    <div
                        v-for="(day, idx) in monthDays"
                        :key="idx"
                        class="cal-month-cell"
                        :class="{
                            'is-today': isToday(day),
                            'is-other-month': day.getMonth() !== cursor.getMonth(),
                            'is-weekend': day.getDay() === 0 || day.getDay() === 6,
                        }"
                        @click="openCreate(day)"
                    >
                        <div class="cal-day-num">{{ day.getDate() }}</div>
                        <div class="cal-day-events">
                            <div
                                v-for="ev in eventsOnDay(day).slice(0, 3)"
                                :key="`${ev.id}-${ev.starts_at}`"
                                class="cal-event-chip"
                                :style="{ background: ev.color + '33', borderColor: ev.color, color: ev.color }"
                                @click.stop="openEdit(ev)"
                            >
                                <span class="cal-event-dot" :style="{ background: ev.color }"></span>
                                <span class="cal-event-chip-title">
                                    <span v-if="ev.source === 'ai_auto'" class="cal-ai-badge" title="Запись из WhatsApp (AI)">AI</span>
                                    <template v-if="!ev.all_day">{{ formatTime(ev.starts_at) }} </template>{{ ev.title }}
                                    <span v-if="ev.assignee" class="cal-event-assignee"> · {{ ev.assignee.name }}</span>
                                </span>
                                <svg v-if="ev.recurrence" class="w-2.5 h-2.5 shrink-0 opacity-70" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                </svg>
                            </div>
                            <div v-if="eventsOnDay(day).length > 3" class="cal-event-more">
                                +{{ eventsOnDay(day).length - 3 }} ещё
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Week view ─────────────────────────────────────────── -->
            <div v-else class="cal-week">
                <!-- Header row -->
                <div class="cal-week-header">
                    <div class="cal-week-gutter"></div>
                    <div
                        v-for="day in weekDays"
                        :key="day.toISOString()"
                        class="cal-week-col-head"
                        :class="{ 'is-today': isToday(day) }"
                    >
                        <div class="cal-week-dow">{{ DAYS_SHORT[(day.getDay() + 6) % 7] }}</div>
                        <div class="cal-week-date" :class="{ 'is-today-bubble': isToday(day) }">{{ day.getDate() }}</div>
                    </div>
                </div>

                <!-- All-day events row -->
                <div class="cal-week-allday-row">
                    <div class="cal-week-gutter cal-allday-label">Весь день</div>
                    <div v-for="day in weekDays" :key="`ad-${day.toISOString()}`" class="cal-week-allday-cell" @click="openCreate(day)">
                        <div
                            v-for="ev in events.filter(e => e.all_day && isSameDay(new Date(e.starts_at), day))"
                            :key="`${ev.id}-${ev.starts_at}`"
                            class="cal-allday-chip"
                            :style="{ background: ev.color + '33', borderColor: ev.color, color: ev.color }"
                            @click.stop="openEdit(ev)"
                        ><span v-if="ev.source === 'ai_auto'" class="cal-ai-badge cal-ai-badge-sm" title="Запись из WhatsApp (AI)">AI</span>{{ ev.title }}<span v-if="ev.assignee" class="cal-event-assignee"> · {{ ev.assignee.name }}</span></div>
                    </div>
                </div>

                <!-- Time grid -->
                <div class="cal-week-body">
                    <div class="cal-week-time-col">
                        <div v-for="h in HOURS" :key="h" class="cal-hour-label">
                            {{ formatHour(h) }}
                        </div>
                    </div>
                    <div class="cal-week-days">
                        <div
                            v-for="day in weekDays"
                            :key="`col-${day.toISOString()}`"
                            class="cal-week-day-col"
                            :class="{ 'is-today-col': isToday(day) }"
                        >
                            <div
                                v-for="h in HOURS"
                                :key="h"
                                class="cal-hour-cell"
                                :style="{ height: `${CELL_H}px` }"
                                @click="openCreate(new Date(day.getFullYear(), day.getMonth(), day.getDate(), h))"
                            >
                                <!-- Events that start in this hour -->
                                <div
                                    v-for="ev in eventsInHour(day, h).filter(e => new Date(e.starts_at).getHours() === h)"
                                    :key="`${ev.id}-${ev.starts_at}`"
                                    class="cal-week-event"
                                    :style="{ ...eventStyle(ev, h), background: ev.color + '33', borderColor: ev.color, color: ev.color }"
                                    @click.stop="openEdit(ev)"
                                >
                                    <div class="cal-week-event-title">
                                        <span v-if="ev.source === 'ai_auto'" class="cal-ai-badge cal-ai-badge-sm" title="Запись из WhatsApp (AI)">AI</span>{{ ev.title }}<span v-if="ev.assignee" class="cal-event-assignee"> · {{ ev.assignee.name }}</span>
                                    </div>
                                    <div class="cal-week-event-time">{{ formatTime(ev.starts_at) }}–{{ formatTime(ev.ends_at) }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── Event modal ───────────────────────────────────────────── -->
        <Teleport to="body">
            <div v-if="showModal" class="cal-modal-backdrop" @click.self="closeModal">
                <div class="cal-modal">
                    <div class="cal-modal-header">
                        <h3>{{ editingId ? 'Редактировать запись' : 'Новая запись' }}</h3>
                        <button class="cal-modal-close" @click="closeModal">×</button>
                    </div>
                    <form @submit.prevent="saveEvent" class="cal-modal-body">

                        <div v-if="editingMeta?.source === 'ai_auto'" class="cal-ai-hint">
                            <strong>Запись из чата (AI).</strong>
                            <span v-if="editingMeta.contact"> Клиент: {{ editingMeta.contact.name || '—' }}<template v-if="editingMeta.contact.phone_number">, {{ editingMeta.contact.phone_number }}</template>.</span>
                            <span v-else-if="editingMeta.chat?.name"> Чат: {{ editingMeta.chat.name }}.</span>
                            Клиенту уйдёт напоминание в WhatsApp от имени ответственного сотрудника (по настройкам напоминаний).
                        </div>

                        <!-- Title -->
                        <label class="form-row">
                            <span>Название</span>
                            <input v-model="form.title" type="text" maxlength="255" placeholder="Название записи" autofocus />
                        </label>

                        <label class="form-row">
                            <span>Ответственный</span>
                            <select v-model="form.assignee_user_id" class="cal-filter-select w-full">
                                <option value="">Не назначен</option>
                                <option v-for="u in assignableUsers" :key="'as-' + u.id" :value="String(u.id)">{{ u.name }}</option>
                            </select>
                        </label>

                        <!-- Color -->
                        <div class="form-row">
                            <span>Цвет</span>
                            <div class="color-palette">
                                <button
                                    v-for="c in PALETTE"
                                    :key="c"
                                    type="button"
                                    class="color-swatch"
                                    :class="{ 'color-swatch-active': form.color === c }"
                                    :style="{ background: c }"
                                    @click="form.color = c"
                                ></button>
                            </div>
                        </div>

                        <!-- All day toggle -->
                        <label class="form-row form-row-inline">
                            <span>Весь день</span>
                            <div class="toggle" :class="{ 'toggle-on': form.all_day }" @click="form.all_day = !form.all_day">
                                <div class="toggle-thumb"></div>
                            </div>
                        </label>

                        <!-- Start / End -->
                        <div class="grid grid-cols-2 gap-3">
                            <label class="form-row">
                                <span>Начало</span>
                                <input
                                    v-model="form.starts_at"
                                    :type="form.all_day ? 'date' : 'datetime-local'"
                                />
                            </label>
                            <label class="form-row">
                                <span>Конец</span>
                                <input
                                    v-model="form.ends_at"
                                    :type="form.all_day ? 'date' : 'datetime-local'"
                                />
                            </label>
                        </div>

                        <!-- Recurrence -->
                        <label class="form-row">
                            <span>Повторение</span>
                            <select v-model="form.recurrence">
                                <option value="">Без повторения</option>
                                <option value="daily">Каждый день</option>
                                <option value="weekly">Каждую неделю</option>
                                <option value="monthly">Каждый месяц</option>
                                <option value="yearly">Каждый год</option>
                            </select>
                        </label>
                        <label v-if="form.recurrence" class="form-row">
                            <span>Повторять до (необязательно)</span>
                            <input v-model="form.recurrence_ends_at" type="date" />
                        </label>

                        <!-- Description -->
                        <label class="form-row">
                            <span>Описание</span>
                            <textarea v-model="form.description" rows="3" maxlength="5000" placeholder="Заметки к записи"></textarea>
                        </label>

                        <div v-if="formError" class="text-sm" :style="{ color: 'var(--wa-danger)' }">{{ formError }}</div>

                        <div class="cal-modal-actions">
                            <button
                                v-if="editingId && !form.title.includes('(копия)')"
                                type="button"
                                class="cal-btn-delete"
                                @click="requestDeleteEvent"
                            >Удалить</button>
                            <div class="flex gap-2 ml-auto">
                                <button type="button" class="cal-btn-secondary" @click="closeModal">Отмена</button>
                                <button type="submit" class="cal-btn-primary" :disabled="saving">
                                    {{ saving ? 'Сохранение…' : (editingId ? 'Сохранить' : 'Создать') }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <DangerConfirmModal
            :open="deleteDialogOpen"
            title="Удалить запись?"
            description="Событие будет удалено из календаря без возможности восстановления."
            confirm-label="Удалить"
            confirm-variant="danger"
            @close="deleteDialogOpen = false"
            @confirm="confirmDeleteEvent"
        />
    </AuthenticatedLayout>
</template>

<style scoped>
/* ── Layout ─────────────────────────────────────────────────────────────── */
.cal-wrapper {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
    background: var(--wa-bg);
    color: var(--wa-text);
}

/* ── Toolbar ────────────────────────────────────────────────────────────── */
.cal-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.65rem 1rem;
    border-bottom: 1px solid var(--wa-border);
    background: var(--wa-panel-header);
    flex-shrink: 0;
    gap: 0.75rem;
    flex-wrap: wrap;
}
.cal-toolbar-left, .cal-toolbar-right {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.cal-title {
    font-size: 1.05rem;
    font-weight: 600;
    color: var(--wa-text);
    white-space: nowrap;
}
.cal-loading { color: var(--wa-text-secondary); }

.cal-btn-today {
    padding: 0.35rem 0.85rem;
    border-radius: 999px;
    border: 1px solid var(--wa-border-strong);
    background: transparent;
    color: var(--wa-text);
    font-size: 0.82rem;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.1s;
}
.cal-btn-today:hover { background: var(--wa-panel-hover); }

.cal-nav-btn {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    border: none;
    background: transparent;
    color: var(--wa-icon);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.1s;
}
.cal-nav-btn:hover { background: var(--wa-panel-hover); }

.cal-view-switch {
    display: flex;
    border: 1px solid var(--wa-border-strong);
    border-radius: 8px;
    overflow: hidden;
}
.cal-view-btn {
    padding: 0.3rem 0.85rem;
    border: none;
    background: transparent;
    color: var(--wa-text-secondary);
    font-size: 0.82rem;
    cursor: pointer;
    transition: background 0.1s, color 0.1s;
}
.cal-view-btn:hover { background: var(--wa-panel-hover); color: var(--wa-text); }
.cal-view-btn.active { background: var(--wa-accent); color: var(--wa-unread-text, #0b0d0e); font-weight: 600; }

.cal-add-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.38rem 0.9rem;
    border-radius: 999px;
    background: var(--wa-accent);
    color: var(--wa-unread-text, #0b0d0e);
    font-size: 0.82rem;
    font-weight: 700;
    border: none;
    cursor: pointer;
    transition: filter 0.12s;
}
.cal-add-btn:hover { filter: brightness(1.08); }

/* ── Filters ─────────────────────────────────────────────────────────────── */
.cal-filters {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 0.85rem 1rem;
    padding: 0.5rem 1rem;
    border-bottom: 1px solid var(--wa-border);
    background: var(--wa-panel);
    flex-shrink: 0;
}
.cal-filter {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    min-width: 140px;
    flex: 0 1 auto;
}
.cal-filter-label {
    font-size: 0.68rem;
    font-weight: 600;
    color: var(--wa-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.cal-filter-select {
    font-size: 0.82rem;
    padding: 0.35rem 0.6rem;
    border-radius: 8px;
    border: 1px solid var(--wa-border-strong);
    background: var(--wa-panel-header);
    color: var(--wa-text);
    min-width: 160px;
}
.cal-event-assignee {
    font-weight: 500;
    opacity: 0.88;
}

/* ── Month view ─────────────────────────────────────────────────────────── */
.cal-month {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    min-height: 0;
}
.cal-month-header {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    border-bottom: 1px solid var(--wa-border);
    flex-shrink: 0;
}
.cal-month-dayname {
    text-align: center;
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--wa-text-secondary);
    padding: 0.45rem 0;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.cal-month-grid {
    flex: 1;
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    grid-template-rows: repeat(6, 1fr);
    overflow-y: auto;
    min-height: 0;
}
.cal-month-cell {
    border-right: 1px solid var(--wa-border);
    border-bottom: 1px solid var(--wa-border);
    padding: 0.35rem 0.4rem;
    cursor: pointer;
    min-height: 90px;
    transition: background 0.08s;
    overflow: hidden;
}
.cal-month-cell:hover { background: var(--wa-panel-hover); }
.cal-month-cell.is-today { background: color-mix(in srgb, var(--wa-accent) 7%, var(--wa-bg)); }
.cal-month-cell.is-other-month { opacity: 0.4; }
.cal-month-cell.is-weekend .cal-day-num { color: var(--wa-text-secondary); }

.cal-day-num {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--wa-text);
    line-height: 1;
    margin-bottom: 0.3rem;
}
.cal-month-cell.is-today .cal-day-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: var(--wa-accent);
    color: var(--wa-unread-text, #0b0d0e);
}

.cal-day-events { display: flex; flex-direction: column; gap: 2px; }

.cal-event-chip {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 2px 5px;
    border-radius: 5px;
    border-left: 3px solid;
    font-size: 0.68rem;
    font-weight: 500;
    cursor: pointer;
    white-space: nowrap;
    overflow: hidden;
    transition: filter 0.1s;
}
.cal-event-chip:hover { filter: brightness(0.92); }
.cal-event-dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    flex-shrink: 0;
}
.cal-event-chip-title { overflow: hidden; text-overflow: ellipsis; flex: 1; display: inline-flex; align-items: center; gap: 4px; min-width: 0; }
.cal-ai-badge {
    flex-shrink: 0;
    font-size: 0.58rem;
    font-weight: 800;
    letter-spacing: 0.02em;
    padding: 1px 4px;
    border-radius: 4px;
    background: color-mix(in srgb, var(--wa-accent) 35%, transparent);
    color: var(--wa-text);
    line-height: 1.1;
}
.cal-ai-badge-sm { font-size: 0.55rem; padding: 0 3px; }
.cal-ai-hint {
    font-size: 0.78rem;
    line-height: 1.45;
    color: var(--wa-text-secondary);
    background: color-mix(in srgb, var(--wa-accent) 12%, transparent);
    border: 1px solid color-mix(in srgb, var(--wa-accent) 28%, transparent);
    border-radius: 10px;
    padding: 0.55rem 0.65rem;
}
.cal-ai-hint strong { color: var(--wa-text); font-weight: 600; }
.cal-event-more {
    font-size: 0.65rem;
    color: var(--wa-text-secondary);
    padding: 1px 4px;
}

/* ── Week view ──────────────────────────────────────────────────────────── */
.cal-week {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    min-height: 0;
}
.cal-week-header {
    display: flex;
    border-bottom: 1px solid var(--wa-border);
    flex-shrink: 0;
}
.cal-week-gutter {
    width: 52px;
    flex-shrink: 0;
}
.cal-allday-label {
    font-size: 0.65rem;
    color: var(--wa-text-secondary);
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding-right: 6px;
}
.cal-week-col-head {
    flex: 1;
    text-align: center;
    padding: 0.4rem 0 0.5rem;
}
.cal-week-col-head.is-today { color: var(--wa-accent); }
.cal-week-dow { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--wa-text-secondary); }
.cal-week-date {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--wa-text);
    margin-top: 2px;
}
.cal-week-col-head.is-today .cal-week-date { color: var(--wa-accent); }
.is-today-bubble {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: var(--wa-accent);
    color: var(--wa-unread-text, #0b0d0e) !important;
}

/* All-day strip */
.cal-week-allday-row {
    display: flex;
    border-bottom: 1px solid var(--wa-border);
    min-height: 32px;
    flex-shrink: 0;
}
.cal-week-allday-cell {
    flex: 1;
    padding: 3px;
    display: flex;
    flex-direction: column;
    gap: 2px;
    border-left: 1px solid var(--wa-border);
    cursor: pointer;
}
.cal-allday-chip {
    padding: 2px 6px;
    border-radius: 4px;
    border-left: 3px solid;
    font-size: 0.7rem;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    cursor: pointer;
}

/* Time grid */
.cal-week-body {
    flex: 1;
    display: flex;
    overflow-y: auto;
    min-height: 0;
}
.cal-week-time-col {
    width: 52px;
    flex-shrink: 0;
}
.cal-hour-label {
    height: 60px;
    font-size: 0.62rem;
    color: var(--wa-text-secondary);
    padding-right: 6px;
    text-align: right;
    padding-top: 4px;
    border-bottom: 1px solid var(--wa-border);
}
.cal-week-days {
    flex: 1;
    display: flex;
}
.cal-week-day-col {
    flex: 1;
    border-left: 1px solid var(--wa-border);
}
.cal-week-day-col.is-today-col { background: color-mix(in srgb, var(--wa-accent) 4%, transparent); }
.cal-hour-cell {
    border-bottom: 1px solid var(--wa-border);
    position: relative;
    cursor: pointer;
    transition: background 0.08s;
}
.cal-hour-cell:hover { background: var(--wa-panel-hover); }

.cal-week-event {
    position: absolute;
    left: 2px;
    right: 2px;
    border-radius: 5px;
    border-left: 3px solid;
    padding: 2px 5px;
    overflow: hidden;
    cursor: pointer;
    z-index: 1;
    transition: filter 0.1s;
}
.cal-week-event:hover { filter: brightness(0.92); z-index: 2; }
.cal-week-event-title { font-size: 0.72rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cal-week-event-time { font-size: 0.62rem; opacity: 0.8; }

/* ── Modal ──────────────────────────────────────────────────────────────── */
.cal-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.55);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 200;
    padding: 1rem;
}
.cal-modal {
    background: var(--wa-panel);
    border-radius: 16px;
    width: 100%;
    max-width: 480px;
    max-height: 92vh;
    overflow-y: auto;
    border: 1px solid var(--wa-border-strong);
    box-shadow: 0 24px 64px rgba(0,0,0,0.5);
    color: var(--wa-text);
}
.cal-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.9rem 1.2rem;
    border-bottom: 1px solid var(--wa-border);
    position: sticky;
    top: 0;
    background: var(--wa-panel);
    z-index: 1;
}
.cal-modal-header h3 { font-size: 1rem; font-weight: 600; margin: 0; }
.cal-modal-close {
    background: transparent;
    border: none;
    color: var(--wa-text-secondary);
    font-size: 1.5rem;
    line-height: 1;
    cursor: pointer;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}
.cal-modal-close:hover { background: var(--wa-panel-hover); }

.cal-modal-body {
    padding: 1rem 1.2rem 1.2rem;
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}
.form-row {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
    font-size: 0.83rem;
    color: var(--wa-text-secondary);
}
.form-row-inline {
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
}
.form-row input,
.form-row select,
.form-row textarea {
    background: var(--wa-panel-header);
    color: var(--wa-text);
    border: 1px solid var(--wa-border-strong);
    border-radius: 10px;
    padding: 0.5rem 0.75rem;
    font-size: 0.9rem;
    width: 100%;
    font-family: inherit;
    resize: vertical;
}
.form-row textarea { min-height: 72px; }

/* Color palette */
.color-palette {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.color-swatch {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    border: 2px solid transparent;
    cursor: pointer;
    transition: transform 0.1s, border-color 0.1s;
}
.color-swatch:hover { transform: scale(1.15); }
.color-swatch-active { border-color: var(--wa-text) !important; transform: scale(1.15); }

/* Toggle */
.toggle {
    width: 40px;
    height: 22px;
    border-radius: 999px;
    background: var(--wa-border-strong);
    cursor: pointer;
    position: relative;
    transition: background 0.2s;
    flex-shrink: 0;
}
.toggle.toggle-on { background: var(--wa-accent); }
.toggle-thumb {
    position: absolute;
    top: 3px;
    left: 3px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: white;
    transition: transform 0.2s;
}
.toggle.toggle-on .toggle-thumb { transform: translateX(18px); }

/* Modal actions */
.cal-modal-actions {
    display: flex;
    align-items: center;
    padding-top: 0.3rem;
}
.cal-btn-delete {
    padding: 0.4rem 0.9rem;
    border-radius: 999px;
    border: 1px solid color-mix(in srgb, var(--wa-danger) 60%, transparent);
    background: transparent;
    color: var(--wa-danger);
    font-size: 0.82rem;
    cursor: pointer;
}
.cal-btn-delete:hover { background: color-mix(in srgb, var(--wa-danger) 10%, transparent); }
.cal-btn-secondary {
    padding: 0.4rem 0.9rem;
    border-radius: 999px;
    border: 1px solid var(--wa-border-strong);
    background: transparent;
    color: var(--wa-text);
    font-size: 0.82rem;
    cursor: pointer;
}
.cal-btn-secondary:hover { background: var(--wa-panel-hover); }
.cal-btn-primary {
    padding: 0.4rem 1.1rem;
    border-radius: 999px;
    background: var(--wa-accent);
    color: var(--wa-unread-text, #0b0d0e);
    font-size: 0.82rem;
    font-weight: 700;
    border: none;
    cursor: pointer;
    transition: filter 0.1s;
}
.cal-btn-primary:hover:not(:disabled) { filter: brightness(1.08); }
.cal-btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
