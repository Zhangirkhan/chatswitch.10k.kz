<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from '@/composables/useI18n';
import { computed, ref } from 'vue';
import OrganizationLayout from '@/Layouts/OrganizationLayout.vue';
import type { OrgDepartment } from './Partials/OrganizationSidebar.vue';
import type { OrgPost } from './Department.vue';

const { t } = useI18n();

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
    if (!q) {
        return props.posts;
    }

    return props.posts.filter(
        (p) =>
            p.title.toLowerCase().includes(q)
            || (p.department_name || '').toLowerCase().includes(q)
            || (p.author?.name || '').toLowerCase().includes(q),
    );
});

function formatDate(value: string | null): string {
    if (!value) {
        return '';
    }
    try {
        return new Date(value).toLocaleString('ru-RU', {
            day: '2-digit',
            month: '2-digit',
            year: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return '';
    }
}

function bodyPreview(body: string | null): string {
    if (!body) {
        return '';
    }
    const div = document.createElement('div');
    div.innerHTML = body;
    const text = (div.textContent || div.innerText || '').trim();

    return text.length <= 180 ? text : text.slice(0, 180) + '…';
}
</script>

<template>
    <Head :title="t('organization.archiveTitle')" />
    <OrganizationLayout :departments="departments" :selected-department-id="null" archive-active>
        <div class="flex flex-col h-full min-h-0 bg-[var(--wa-page-bg)]">
            <header class="ui-page-header">
                <div class="ui-page-header__icon">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8l1 12a2 2 0 002 2h8a2 2 0 002-2L19 8M10 12v4m4-4v4" />
                    </svg>
                </div>
                <div class="ui-page-header__body">
                    <h1 class="ui-page-header__title">{{ t('organization.archiveHeading') }}</h1>
                    <p class="ui-page-header__subtitle">
                        {{ t('organization.archiveCompletedCount', { count: total_archived }) }}
                    </p>
                </div>
            </header>

            <div class="px-5 pt-3 pb-1 shrink-0">
                <div class="ui-search-shell">
                    <div class="absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none">
                        <svg class="w-4 h-4 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <input
                        v-model="search"
                        type="search"
                        :placeholder="t('organization.archiveSearch')"
                        class="ui-search-input"
                    />
                </div>
            </div>

            <div class="ui-org-list-scroll wa-scrollbar">
                <div v-if="filtered.length === 0" class="ui-empty-state ui-empty-state--org">
                    <p class="text-sm text-[var(--wa-text-secondary)] m-0">
                        {{ search ? t('organization.archiveNotFound') : t('organization.archiveEmpty') }}
                    </p>
                </div>

                <Link
                    v-for="post in filtered"
                    :key="post.id"
                    :href="route('organization.posts.show', post.id)"
                    class="ui-task-card ui-task-card--list ui-task-card--muted"
                >
                    <div class="ui-task-card__top">
                        <span class="ui-task-status ui-task-status--done ui-task-status--pill">
                            {{ t('organization.donePill') }}
                        </span>
                        <span v-if="post.department_name" class="ui-dept-label">{{ post.department_name }}</span>
                        <span v-if="post.due_at" class="ui-task-due ml-auto">
                            {{ t('organization.dueDateLabel', { date: formatDate(post.due_at) }) }}
                        </span>
                    </div>
                    <div class="ui-task-card__title">{{ post.title }}</div>
                    <div v-if="post.body" class="ui-task-card__body-preview">{{ bodyPreview(post.body) }}</div>
                    <div class="ui-task-card__meta">
                        <span>{{ post.author?.name || t('organization.noAuthor') }}</span>
                        <span class="ui-task-card__meta-sep">·</span>
                        <span>{{ t('organization.completedAt', { date: formatDate(post.updated_at) }) }}</span>
                        <span v-if="post.attachments?.length" class="ui-task-card__meta-icon">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                            </svg>
                            {{ post.attachments.length }}
                        </span>
                        <span class="ui-task-card__meta-icon ui-task-card__meta-icon--end">
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
