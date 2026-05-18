<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import OrganizationLayout from '@/Layouts/OrganizationLayout.vue';
import type { OrgDepartment } from './Partials/OrganizationSidebar.vue';
import type { OrgPost } from './Department.vue';

interface ArchivePost extends OrgPost {
    department_name?: string;
}

const props = defineProps<{
    departments: OrgDepartment[];
    posts: ArchivePost[];
    total_archived: number;
}>();

const search = ref('');

const filtered = computed<ArchivePost[]>(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.posts;
    return props.posts.filter(
        (p) =>
            p.title.toLowerCase().includes(q) ||
            (p.department_name || '').toLowerCase().includes(q) ||
            (p.author?.name || '').toLowerCase().includes(q),
    );
});

function formatDate(value: string | null): string {
    if (!value) return '';
    try {
        return new Date(value).toLocaleString('ru-RU', {
            day: '2-digit', month: '2-digit', year: '2-digit',
            hour: '2-digit', minute: '2-digit',
        });
    } catch {
        return '';
    }
}

function bodyPreview(body: string | null): string {
    if (!body) return '';
    const div = document.createElement('div');
    div.innerHTML = body;
    const text = (div.textContent || div.innerText || '').trim();
    return text.length <= 180 ? text : text.slice(0, 180) + '…';
}
</script>

<template>
    <Head title="Архив задач · Организация" />
    <OrganizationLayout :departments="departments" :selected-department-id="null" archive-active>
        <div class="flex flex-col h-full min-h-0">
            <!-- Header -->
            <div
                class="px-5 py-3 shrink-0 flex items-center gap-3 border-b"
                :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border)' }"
            >
                <div class="w-10 h-10 rounded-full flex items-center justify-center shrink-0" :style="{ background: 'var(--wa-panel)', color: 'var(--wa-icon)' }">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8l1 12a2 2 0 002 2h8a2 2 0 002-2L19 8M10 12v4m4-4v4" />
                    </svg>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-base font-medium text-[var(--wa-text)]">Архив задач</div>
                    <div class="text-xs text-[var(--wa-text-secondary)]">{{ total_archived }} завершённых задач</div>
                </div>
            </div>

            <!-- Search -->
            <div class="px-5 pt-3 pb-1 shrink-0">
                <div class="relative rounded-full" :style="{ background: 'var(--wa-panel-header)' }">
                    <div class="absolute left-4 top-1/2 -translate-y-1/2">
                        <svg class="w-4 h-4 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Поиск по задачам, отделу, автору…"
                        class="w-full pl-11 pr-4 py-2 bg-transparent rounded-full text-sm text-[var(--wa-text)] border-0 focus:ring-0 focus:outline-none"
                    />
                </div>
            </div>

            <!-- Posts list -->
            <div class="flex-1 overflow-y-auto wa-scrollbar px-5 py-3">
                <div v-if="filtered.length === 0" class="text-sm text-[var(--wa-text-secondary)] text-center py-12">
                    {{ search ? 'Ничего не найдено.' : 'Архив пустой. Завершённые задачи появятся здесь.' }}
                </div>

                <Link
                    v-for="post in filtered"
                    :key="post.id"
                    :href="route('organization.posts.show', post.id)"
                    class="archive-card"
                >
                    <div class="archive-card-top">
                        <span class="done-pill">Готово</span>
                        <span v-if="post.department_name" class="dept-label">{{ post.department_name }}</span>
                        <span v-if="post.due_at" class="text-xs text-[var(--wa-text-secondary)] ml-auto">
                            Срок: {{ formatDate(post.due_at) }}
                        </span>
                    </div>
                    <div class="archive-card-title">{{ post.title }}</div>
                    <div v-if="post.body" class="archive-card-body">{{ bodyPreview(post.body) }}</div>
                    <div class="archive-card-meta">
                        <span>{{ post.author?.name || 'Без автора' }}</span>
                        <span>·</span>
                        <span>Завершено {{ formatDate(post.updated_at) }}</span>
                        <span v-if="post.attachments?.length" class="inline-flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                            </svg>
                            {{ post.attachments.length }}
                        </span>
                        <span class="ml-auto inline-flex items-center gap-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z" />
                            </svg>
                            {{ post.comments_count }}
                        </span>
                    </div>
                </Link>
            </div>
        </div>
    </OrganizationLayout>
</template>

<style scoped>
.archive-card {
    display: block;
    background: var(--wa-panel);
    border: 1px solid var(--wa-border);
    border-radius: 12px;
    padding: 0.85rem 1rem;
    margin-bottom: 0.55rem;
    text-decoration: none;
    color: var(--wa-text);
    transition: border-color 0.12s ease;
    opacity: 0.85;
}
.archive-card:hover {
    border-color: color-mix(in srgb, var(--wa-border-strong) 80%, var(--wa-accent));
    opacity: 1;
}
.archive-card-top {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.4rem;
    flex-wrap: wrap;
}
.done-pill {
    display: inline-flex;
    align-items: center;
    padding: 0.1rem 0.55rem;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 600;
    line-height: 1.4;
    text-transform: uppercase;
    letter-spacing: 0.02em;
    background: color-mix(in srgb, var(--wa-accent) 22%, transparent);
    color: var(--wa-accent);
}
.dept-label {
    font-size: 0.75rem;
    color: var(--wa-text-secondary);
    background: var(--wa-panel-header);
    border-radius: 999px;
    padding: 0.08rem 0.5rem;
    border: 1px solid var(--wa-border);
}
.archive-card-title {
    font-size: 0.95rem;
    font-weight: 600;
    line-height: 1.3;
    color: var(--wa-text);
    margin-bottom: 0.25rem;
}
.archive-card-body {
    font-size: 0.83rem;
    color: var(--wa-text-secondary);
    line-height: 1.4;
    margin-bottom: 0.45rem;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.archive-card-meta {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.73rem;
    color: var(--wa-text-secondary);
}
</style>
