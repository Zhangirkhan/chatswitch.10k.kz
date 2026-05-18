<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import OrganizationLayout from '@/Layouts/OrganizationLayout.vue';
import type { OrgDepartment } from './Partials/OrganizationSidebar.vue';

const props = defineProps<{
    departments: OrgDepartment[];
}>();

// ─── Filter ───────────────────────────────────────────────────────────────────

type FilterKey = 'all' | 'open' | 'in_progress' | 'done';

const activeFilter = ref<FilterKey>('all');

const totalOpen       = computed(() => props.departments.reduce((s, d) => s + d.open_count, 0));
const totalInProgress = computed(() => props.departments.reduce((s, d) => s + d.in_progress_count, 0));
const totalDone       = computed(() => props.departments.reduce((s, d) => s + d.done_count, 0));
const totalAll        = computed(() => totalOpen.value + totalInProgress.value + totalDone.value);

/** Отделы, прошедшие фильтр */
const filteredDepartments = computed(() => {
    if (activeFilter.value === 'all') return props.departments;
    return props.departments.filter((d) => {
        if (activeFilter.value === 'open')        return d.open_count > 0;
        if (activeFilter.value === 'in_progress') return d.in_progress_count > 0;
        if (activeFilter.value === 'done')        return d.done_count > 0;
        return true;
    });
});

/** Задач в текущем фильтре, суммарно */
const filteredTasksTotal = computed(() => {
    if (activeFilter.value === 'all')         return totalAll.value;
    if (activeFilter.value === 'open')        return totalOpen.value;
    if (activeFilter.value === 'in_progress') return totalInProgress.value;
    if (activeFilter.value === 'done')        return totalDone.value;
    return 0;
});

function setFilter(key: FilterKey) {
    activeFilter.value = key;
}

function initial(name: string): string {
    return name.trim().charAt(0).toUpperCase();
}
</script>

<template>
    <Head title="Организация" />
    <OrganizationLayout :departments="departments" :selected-department-id="null">
        <div class="org-index">

            <!-- ── KPI cards (кликабельные фильтры) ─────────────────── -->
            <div class="kpi-row">
                <button
                    type="button"
                    class="kpi-card"
                    :class="{ 'kpi-card-active': activeFilter === 'all' }"
                    @click="setFilter('all')"
                >
                    <div class="kpi-icon kpi-icon-all">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                        </svg>
                    </div>
                    <div class="kpi-value">{{ totalAll }}</div>
                    <div class="kpi-label">Всего задач</div>
                </button>

                <button
                    type="button"
                    class="kpi-card"
                    :class="{ 'kpi-card-active kpi-card-active-open': activeFilter === 'open' }"
                    @click="setFilter('open')"
                >
                    <div class="kpi-icon kpi-icon-open">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="9"/>
                        </svg>
                    </div>
                    <div class="kpi-value">{{ totalOpen }}</div>
                    <div class="kpi-label">Открытые</div>
                </button>

                <button
                    type="button"
                    class="kpi-card"
                    :class="{ 'kpi-card-active kpi-card-active-progress': activeFilter === 'in_progress' }"
                    @click="setFilter('in_progress')"
                >
                    <div class="kpi-icon kpi-icon-progress">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <div class="kpi-value">{{ totalInProgress }}</div>
                    <div class="kpi-label">В работе</div>
                </button>

                <button
                    type="button"
                    class="kpi-card"
                    :class="{ 'kpi-card-active kpi-card-active-done': activeFilter === 'done' }"
                    @click="setFilter('done')"
                >
                    <div class="kpi-icon kpi-icon-done">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
                    <div class="kpi-value">{{ totalDone }}</div>
                    <div class="kpi-label">Завершено</div>
                </button>
            </div>

            <!-- ── Строка-фильтр ─────────────────────────────────────── -->
            <div class="filter-bar">
                <div class="filter-bar-left">
                    <button
                        v-for="f in ([
                            { key: 'all',         label: 'Все',        color: '' },
                            { key: 'open',        label: 'Открытые',   color: 'open' },
                            { key: 'in_progress', label: 'В работе',   color: 'progress' },
                            { key: 'done',        label: 'Завершённые',color: 'done' },
                        ] as const)"
                        :key="f.key"
                        type="button"
                        class="filter-btn"
                        :class="[`filter-btn-${f.color || 'all'}`, { 'filter-btn-active': activeFilter === f.key }]"
                        @click="setFilter(f.key)"
                    >
                        {{ f.label }}
                    </button>
                </div>
                <div class="filter-bar-right">
                    <span v-if="activeFilter !== 'all'" class="filter-result-hint">
                        {{ filteredDepartments.length }} {{ filteredDepartments.length === 1 ? 'отдел' : filteredDepartments.length < 5 ? 'отдела' : 'отделов' }},
                        {{ filteredTasksTotal }} задач
                    </span>
                    <span v-else class="filter-result-hint">
                        {{ departments.length }} {{ departments.length === 1 ? 'отдел' : departments.length < 5 ? 'отдела' : 'отделов' }}
                    </span>
                </div>
            </div>

            <!-- Empty state (нет отделов) -->
            <div v-if="departments.length === 0" class="empty-state">
                <div class="empty-icon">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M5 21V7l7-4 7 4v14M9 21V12h6v9" />
                    </svg>
                </div>
                <p class="text-sm">Нет доступных отделов</p>
            </div>

            <!-- Empty state (фильтр ничего не нашёл) -->
            <div v-else-if="filteredDepartments.length === 0" class="empty-state">
                <div class="empty-icon">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                    </svg>
                </div>
                <p class="text-sm">Нет отделов с задачами в этом статусе</p>
                <button class="reset-filter-btn" @click="setFilter('all')">Сбросить фильтр</button>
            </div>

            <!-- ── Departments grid ───────────────────────────────────── -->
            <div v-else class="dept-grid">
                <Link
                    v-for="dept in filteredDepartments"
                    :key="dept.id"
                    :href="route('organization.departments.show', dept.id)"
                    class="dept-card"
                >
                    <!-- Card header -->
                    <div class="dept-card-header">
                        <div class="dept-avatar">{{ initial(dept.name) }}</div>
                        <div class="dept-card-title truncate">{{ dept.name }}</div>
                    </div>

                    <!-- Description -->
                    <div v-if="dept.description" class="dept-card-desc">{{ dept.description }}</div>

                    <!-- Task badges row -->
                    <div class="dept-card-badges">
                        <span
                            v-if="dept.in_progress_count > 0"
                            class="task-badge task-badge-progress"
                            :class="{ 'task-badge-highlighted': activeFilter === 'in_progress' }"
                            title="В работе"
                        >
                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
                            </svg>
                            {{ dept.in_progress_count }} в работе
                        </span>
                        <span
                            v-if="dept.open_count > 0"
                            class="task-badge task-badge-open"
                            :class="{ 'task-badge-highlighted': activeFilter === 'open' }"
                            title="Открыто"
                        >
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="9"/>
                            </svg>
                            {{ dept.open_count }} открыто
                        </span>
                        <span
                            v-if="dept.done_count > 0"
                            class="task-badge task-badge-done"
                            :class="{ 'task-badge-highlighted': activeFilter === 'done' }"
                            title="Завершено"
                        >
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                            {{ dept.done_count }} готово
                        </span>
                        <span
                            v-if="dept.open_count === 0 && dept.in_progress_count === 0 && dept.done_count === 0"
                            class="task-badge task-badge-empty"
                        >Нет задач</span>
                    </div>

                    <!-- Footer arrow -->
                    <div class="dept-card-footer">
                        <svg class="w-4 h-4 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                    </div>
                </Link>
            </div>

        </div>
    </OrganizationLayout>
</template>

<style scoped>
.org-index {
    flex: 1;
    overflow-y: auto;
    padding: 1.5rem 1.5rem 2rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

/* ── KPI row ─────────────────────────────────────────────────────────────── */
.kpi-row {
    display: flex;
    gap: 0.75rem;
}
.kpi-card {
    flex: 1;
    background: var(--wa-panel);
    border: 2px solid var(--wa-border);
    border-radius: 14px;
    padding: 1rem 1.1rem 0.9rem;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.2rem;
    cursor: pointer;
    text-align: left;
    transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
}
.kpi-card:hover {
    border-color: var(--wa-border-strong);
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
}
.kpi-card-active {
    border-color: var(--wa-accent) !important;
    background: color-mix(in srgb, var(--wa-accent) 8%, var(--wa-panel));
    box-shadow: 0 2px 12px rgba(0,0,0,0.1);
}
.kpi-card-active-open     { border-color: #f59e0b !important; background: color-mix(in srgb, #f59e0b 8%, var(--wa-panel)); }
.kpi-card-active-progress { border-color: #3b82f6 !important; background: color-mix(in srgb, #3b82f6 8%, var(--wa-panel)); }
.kpi-card-active-done     { border-color: var(--wa-accent) !important; background: color-mix(in srgb, var(--wa-accent) 8%, var(--wa-panel)); }

.kpi-icon {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 0.25rem;
}
.kpi-icon-all      { background: color-mix(in srgb, var(--wa-accent) 15%, var(--wa-panel-header)); color: var(--wa-accent); }
.kpi-icon-open     { background: color-mix(in srgb, #f59e0b 15%, var(--wa-panel-header)); color: #f59e0b; }
.kpi-icon-progress { background: color-mix(in srgb, #3b82f6 15%, var(--wa-panel-header)); color: #3b82f6; }
.kpi-icon-done     { background: color-mix(in srgb, var(--wa-accent) 15%, var(--wa-panel-header)); color: var(--wa-accent); }

.kpi-value {
    font-size: 1.65rem;
    font-weight: 700;
    line-height: 1;
    color: var(--wa-text);
}
.kpi-label {
    font-size: 0.75rem;
    color: var(--wa-text-secondary);
    font-weight: 500;
}

/* ── Filter bar ─────────────────────────────────────────────────────────── */
.filter-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.filter-bar-left {
    display: flex;
    gap: 0.35rem;
    flex-wrap: wrap;
}
.filter-bar-right {
    font-size: 0.78rem;
    color: var(--wa-text-secondary);
}
.filter-btn {
    padding: 0.3rem 0.85rem;
    border-radius: 999px;
    border: 1px solid var(--wa-border-strong);
    background: var(--wa-panel-header);
    color: var(--wa-text-secondary);
    font-size: 0.8rem;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.12s, border-color 0.12s, color 0.12s;
}
.filter-btn:hover { background: var(--wa-panel-hover); color: var(--wa-text); }
.filter-btn-active.filter-btn-all      { background: color-mix(in srgb, var(--wa-accent) 15%, var(--wa-panel-header)); border-color: var(--wa-accent); color: var(--wa-accent); font-weight: 700; }
.filter-btn-active.filter-btn-open     { background: color-mix(in srgb, #f59e0b 15%, var(--wa-panel-header)); border-color: #f59e0b; color: #f59e0b; font-weight: 700; }
.filter-btn-active.filter-btn-progress { background: color-mix(in srgb, #3b82f6 15%, var(--wa-panel-header)); border-color: #3b82f6; color: #3b82f6; font-weight: 700; }
.filter-btn-active.filter-btn-done     { background: color-mix(in srgb, var(--wa-accent) 15%, var(--wa-panel-header)); border-color: var(--wa-accent); color: var(--wa-accent); font-weight: 700; }

/* ── Empty states ────────────────────────────────────────────────────────── */
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    padding: 4rem 0;
    color: var(--wa-text-secondary);
}
.empty-icon {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: var(--wa-panel-header);
    color: var(--wa-icon);
    display: flex;
    align-items: center;
    justify-content: center;
}
.reset-filter-btn {
    padding: 0.4rem 1.1rem;
    border-radius: 999px;
    border: 1px solid var(--wa-border-strong);
    background: transparent;
    color: var(--wa-text-secondary);
    font-size: 0.82rem;
    cursor: pointer;
}
.reset-filter-btn:hover { background: var(--wa-panel-hover); color: var(--wa-text); }

/* ── Department grid ─────────────────────────────────────────────────────── */
.dept-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 0.85rem;
}
.dept-card {
    background: var(--wa-panel);
    border: 1px solid var(--wa-border);
    border-radius: 14px;
    padding: 1rem 1.1rem 0.85rem;
    text-decoration: none;
    color: var(--wa-text);
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    transition: border-color 0.15s, box-shadow 0.15s;
    cursor: pointer;
}
.dept-card:hover {
    border-color: color-mix(in srgb, var(--wa-accent) 55%, var(--wa-border));
    box-shadow: 0 2px 12px rgba(0,0,0,0.1);
}

.dept-card-header {
    display: flex;
    align-items: center;
    gap: 0.6rem;
}
.dept-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: color-mix(in srgb, var(--wa-accent) 20%, var(--wa-panel-header));
    color: var(--wa-text);
    font-size: 0.95rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.dept-card-title {
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--wa-text);
    min-width: 0;
}
.dept-card-desc {
    font-size: 0.8rem;
    color: var(--wa-text-secondary);
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* Task badges */
.dept-card-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    margin-top: 0.1rem;
}
.task-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.2rem 0.55rem;
    border-radius: 999px;
    font-size: 0.73rem;
    font-weight: 600;
    line-height: 1;
    transition: transform 0.1s;
}
.task-badge-highlighted {
    transform: scale(1.08);
    box-shadow: 0 0 0 2px currentColor;
}
.task-badge-progress {
    background: color-mix(in srgb, #3b82f6 18%, var(--wa-panel-header));
    color: #3b82f6;
    border: 1px solid color-mix(in srgb, #3b82f6 35%, var(--wa-border));
}
.task-badge-open {
    background: color-mix(in srgb, #f59e0b 18%, var(--wa-panel-header));
    color: #f59e0b;
    border: 1px solid color-mix(in srgb, #f59e0b 35%, var(--wa-border));
}
.task-badge-done {
    background: color-mix(in srgb, var(--wa-accent) 15%, var(--wa-panel-header));
    color: var(--wa-accent);
    border: 1px solid color-mix(in srgb, var(--wa-accent) 30%, var(--wa-border));
}
.task-badge-empty {
    background: var(--wa-panel-header);
    color: var(--wa-text-secondary);
    border: 1px solid var(--wa-border);
}

.dept-card-footer {
    display: flex;
    justify-content: flex-end;
    margin-top: auto;
}
</style>
