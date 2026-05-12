<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import SidebarSectionTabs from '@/Components/SidebarSectionTabs.vue';

export interface OrgDepartment {
    id: number;
    name: string;
    description: string | null;
    parent_id: number | null;
    open_count: number;
    in_progress_count: number;
    done_count: number;
    posts_count: number;
    archived_posts_count: number;
}

const props = defineProps<{
    departments: OrgDepartment[];
    selectedDepartmentId?: number | null;
    archiveActive?: boolean;
}>();

const totalArchived = computed<number>(() =>
    props.departments.reduce((sum, d) => sum + (d.archived_posts_count ?? 0), 0),
);

const search = ref('');

interface DeptNode extends OrgDepartment {
    children: DeptNode[];
    depth: number;
}

const tree = computed<DeptNode[]>(() => {
    const byParent = new Map<number | null, OrgDepartment[]>();
    props.departments.forEach((d) => {
        const key = d.parent_id ?? null;
        if (!byParent.has(key)) byParent.set(key, []);
        byParent.get(key)!.push(d);
    });

    const allowedIds = new Set<number>(props.departments.map((d) => d.id));

    const build = (parentId: number | null, depth: number): DeptNode[] => {
        const list = byParent.get(parentId) || [];
        return list.map((d) => ({
            ...d,
            depth,
            children: build(d.id, depth + 1),
        }));
    };

    // Корневыми считаем те, у кого нет parent_id ИЛИ чей parent отсутствует
    // в выдаче (например, скрыт из-за прав доступа).
    const roots: DeptNode[] = [];
    const seenIds = new Set<number>();
    const walk = (parentId: number | null, depth: number): DeptNode[] => {
        const out: DeptNode[] = [];
        for (const d of byParent.get(parentId) || []) {
            if (seenIds.has(d.id)) continue;
            seenIds.add(d.id);
            out.push({ ...d, depth, children: walk(d.id, depth + 1) });
        }
        return out;
    };

    roots.push(...walk(null, 0));
    // Те, чей parent есть, но недоступен — поднимаем в корень.
    for (const d of props.departments) {
        if (seenIds.has(d.id)) continue;
        if (d.parent_id !== null && !allowedIds.has(d.parent_id)) {
            seenIds.add(d.id);
            roots.push({ ...d, depth: 0, children: build(d.id, 1) });
        }
    }

    return roots;
});

function flattenFiltered(nodes: DeptNode[]): DeptNode[] {
    const q = search.value.trim().toLowerCase();
    const out: DeptNode[] = [];
    const visit = (list: DeptNode[]) => {
        for (const node of list) {
            const matches = !q
                || node.name.toLowerCase().includes(q)
                || (node.description || '').toLowerCase().includes(q);
            const childrenMatches = node.children.length > 0
                && flattenFiltered(node.children).length > 0;
            if (matches || childrenMatches) {
                out.push(node);
            }
            visit(node.children);
        }
    };
    visit(nodes);
    return out;
}

const flat = computed<DeptNode[]>(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) {
        const out: DeptNode[] = [];
        const visit = (list: DeptNode[]) => {
            for (const node of list) {
                out.push(node);
                visit(node.children);
            }
        };
        visit(tree.value);
        return out;
    }

    return flattenFiltered(tree.value);
});

function clearSearch() {
    search.value = '';
}
</script>

<template>
    <div class="w-[400px] h-full relative shrink-0 overflow-hidden">
        <div class="w-[400px] h-full flex flex-col bg-[var(--wa-panel)]">
            <!-- Header -->
            <div class="h-[60px] px-4 flex items-center justify-between shrink-0">
                <h1 class="min-w-0 text-[var(--wa-text)] text-xl font-normal m-0 truncate">
                    Организация
                </h1>
            </div>

            <!-- Search -->
            <div class="px-3 py-2 shrink-0">
                <div class="relative rounded-full" :style="{ background: 'var(--wa-panel-header)' }">
                    <div class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 flex items-center justify-center">
                        <svg
                            class="w-4 h-4 text-[var(--wa-icon)]"
                            fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Поиск отдела"
                        class="w-full pl-12 pr-10 py-[9px] bg-transparent rounded-full text-sm text-[var(--wa-text)] border-0 focus:ring-0 focus:outline-none"
                    />
                    <button
                        v-if="search"
                        @click="clearSearch"
                        class="absolute right-3 top-1/2 -translate-y-1/2 w-6 h-6 rounded-full flex items-center justify-center text-[var(--wa-icon)] hover:bg-[var(--wa-selected)]"
                        type="button"
                        title="Очистить"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            <SidebarSectionTabs active="organization" />

            <!-- Departments list -->
            <div class="flex-1 overflow-y-auto wa-scrollbar">
                <div
                    v-if="flat.length === 0"
                    class="py-6 text-sm text-[var(--wa-text-secondary)] px-6 text-center"
                >
                    Нет доступных отделов
                </div>
                <Link
                    v-for="dept in flat"
                    :key="dept.id"
                    :href="route('organization.departments.show', dept.id)"
                    class="dept-item"
                    :class="{ 'dept-item-selected': dept.id === selectedDepartmentId }"
                    :style="{ paddingLeft: `${0.75 + dept.depth * 1.1}rem` }"
                >
                    <div class="dept-icon">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="dept-name truncate">{{ dept.name }}</div>
                        <div v-if="dept.description" class="dept-meta truncate">{{ dept.description }}</div>
                    </div>
                    <div v-if="dept.posts_count > 0" class="dept-badges">
                        <span
                            v-if="dept.in_progress_count > 0"
                            class="dept-badge dept-badge-progress"
                            :title="`В работе: ${dept.in_progress_count}`"
                        >{{ dept.in_progress_count > 99 ? '99+' : dept.in_progress_count }}</span>
                        <span
                            v-if="dept.open_count > 0"
                            class="dept-badge dept-badge-open"
                            :title="`Открыто: ${dept.open_count}`"
                        >{{ dept.open_count > 99 ? '99+' : dept.open_count }}</span>
                    </div>
                </Link>

                <!-- Archive link -->
                <Link
                    :href="route('organization.archive')"
                    class="dept-item archive-item"
                    :class="{ 'dept-item-selected': archiveActive }"
                >
                    <div class="dept-icon archive-icon">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8l1 12a2 2 0 002 2h8a2 2 0 002-2L19 8M10 12v4m4-4v4" />
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="dept-name truncate">Архив задач</div>
                        <div class="dept-meta">Завершённые задачи</div>
                    </div>
                    <span v-if="totalArchived > 0" class="dept-badge archive-badge" :title="`Архивных задач: ${totalArchived}`">
                        {{ totalArchived > 99 ? '99+' : totalArchived }}
                    </span>
                </Link>
            </div>
        </div>
    </div>
</template>

<style scoped>
.dept-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.6rem 0.75rem;
    border-bottom: 1px solid var(--wa-border);
    color: var(--wa-text);
    text-decoration: none;
    transition: background-color 0.12s ease;
    cursor: pointer;
}
.dept-item:hover {
    background-color: var(--wa-panel-hover);
}
.dept-item-selected {
    background-color: var(--wa-selected);
}
.dept-icon {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: var(--wa-panel-header);
    color: var(--wa-icon);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.dept-name {
    font-size: 0.95rem;
    line-height: 1.2;
    color: var(--wa-text);
}
.dept-meta {
    font-size: 0.8rem;
    color: var(--wa-text-secondary);
    margin-top: 2px;
}
.dept-badges {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-shrink: 0;
}
.dept-badge {
    min-width: 22px;
    height: 22px;
    padding: 0 0.4rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.dept-badge-progress {
    background: color-mix(in srgb, #3b82f6 90%, transparent);
    color: #fff;
}
.dept-badge-open {
    background: color-mix(in srgb, #f59e0b 85%, transparent);
    color: #0b0d0e;
}
.archive-item {
    border-top: 1px solid var(--wa-border-strong);
    margin-top: 2px;
}
.archive-icon {
    background: color-mix(in srgb, #22c55e 15%, var(--wa-panel-header));
    color: #22c55e;
}
.archive-badge {
    background: color-mix(in srgb, #22c55e 80%, transparent);
    color: #fff;
}
</style>
