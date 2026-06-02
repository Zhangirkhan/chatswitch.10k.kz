<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import axios from 'axios';
import { useI18n } from '@/composables/useI18n';
import UiModal from '@/Components/Ui/UiModal.vue';
import OrganizationLayout from '@/Layouts/OrganizationLayout.vue';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import type { OrgDepartment } from './Partials/OrganizationSidebar.vue';

const { t } = useI18n();

export interface OrgAssignee {
    id: number;
    name: string;
}

export interface OrgAttachment {
    id: number;
    original_name: string;
    url: string;
    mime_type: string | null;
    size: number;
    is_image: boolean;
    uploaded_by: number;
}

export interface OrgPost {
    id: number;
    department_id: number;
    title: string;
    body: string | null;
    status: 'open' | 'in_progress' | 'done';
    due_at: string | null;
    author: { id: number; name: string } | null;
    assignees: OrgAssignee[];
    comments_count: number;
    created_at: string | null;
    updated_at: string | null;
    attachments: OrgAttachment[];
}

const props = defineProps<{
    departments: OrgDepartment[];
    department: { id: number; name: string; description: string | null; parent_id: number | null };
    posts: OrgPost[];
    archived_count: number;
    members: OrgAssignee[];
}>();

const localPosts = ref<OrgPost[]>([...props.posts]);

const showCreate = ref(false);
const draftTitle = ref('');
const draftBody = ref('');
const draftStatus = ref<OrgPost['status']>('open');
const draftDue = ref('');
const draftAssigneeIds = ref<number[]>([]);
const submitting = ref(false);
const submitError = ref<string | null>(null);

function resetDraft() {
    draftTitle.value = '';
    draftBody.value = '';
    draftStatus.value = 'open';
    draftDue.value = '';
    draftAssigneeIds.value = [];
    submitError.value = null;
}

function openCreate() {
    resetDraft();
    showCreate.value = true;
}

function closeCreate() {
    showCreate.value = false;
    resetDraft();
}

function toggleAssignee(userId: number) {
    const idx = draftAssigneeIds.value.indexOf(userId);
    if (idx === -1) {
        draftAssigneeIds.value = [...draftAssigneeIds.value, userId];
    } else {
        draftAssigneeIds.value = draftAssigneeIds.value.filter((id) => id !== userId);
    }
}

async function submitCreate() {
    if (submitting.value) {
        return;
    }
    if (draftTitle.value.trim() === '') {
        submitError.value = t('organization.taskTitleRequired');
        return;
    }
    submitting.value = true;
    submitError.value = null;
    try {
        const { data } = await axios.post(route('organization.posts.store', props.department.id), {
            title: draftTitle.value.trim(),
            body: draftBody.value || null,
            status: draftStatus.value,
            due_at: draftDue.value ? new Date(draftDue.value).toISOString() : null,
            assignee_ids: draftAssigneeIds.value,
        });
        localPosts.value = [data.post, ...localPosts.value];
        showCreate.value = false;
        resetDraft();
        router.reload({ only: ['departments'] });
    } catch (e: unknown) {
        if (axios.isAxiosError(e)) {
            submitError.value = e.response?.data?.message || t('organization.taskCreateFailed');
        } else {
            submitError.value = t('organization.taskCreateFailed');
        }
    } finally {
        submitting.value = false;
    }
}

function statusLabel(status: OrgPost['status']): string {
    if (status === 'in_progress') {
        return t('organization.statusInProgress');
    }
    if (status === 'done') {
        return t('organization.statusDone');
    }

    return t('organization.statusOpen');
}

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
    const text = div.textContent || div.innerText || '';
    const trimmed = text.trim();
    if (trimmed.length <= 220) {
        return trimmed;
    }

    return trimmed.slice(0, 220) + '…';
}

function initial(name: string): string {
    return name.trim().charAt(0).toUpperCase();
}
</script>

<template>
    <Head :title="t('organization.departmentPageTitle', { name: department.name })" />
    <OrganizationLayout
        :departments="departments"
        :selected-department-id="department.id"
    >
        <div class="flex flex-col h-full min-h-0 bg-[var(--wa-page-bg)]">
            <header class="ui-page-header">
                <div class="ui-page-header__icon">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </div>
                <div class="ui-page-header__body min-w-0">
                    <h1 class="ui-page-header__title truncate">{{ department.name }}</h1>
                    <p v-if="department.description" class="ui-page-header__subtitle truncate">
                        {{ department.description }}
                    </p>
                </div>
                <button type="button" class="ui-btn ui-btn--primary ui-btn--pill shrink-0" @click="openCreate">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                    </svg>
                    {{ t('organization.createPost') }}
                </button>
            </header>

            <div class="ui-org-list-scroll wa-scrollbar">
                <div v-if="localPosts.length === 0" class="ui-empty-state ui-empty-state--org">
                    <p class="text-sm text-[var(--wa-text-secondary)] m-0">
                        <span v-if="archived_count === 0">{{ t('organization.deptEmptyNoTasks') }}</span>
                        <span v-else>{{ t('organization.deptEmptyAllDone') }}</span>
                    </p>
                </div>

                <Link
                    v-for="post in localPosts"
                    :key="post.id"
                    :href="route('organization.posts.show', post.id)"
                    class="ui-task-card ui-task-card--list"
                >
                    <div class="ui-task-card__top">
                        <span
                            class="ui-task-status ui-task-status--pill"
                            :class="`ui-task-status--${post.status}`"
                        >{{ statusLabel(post.status) }}</span>
                        <span v-if="post.due_at" class="ui-task-due">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            {{ t('organization.dueUntil', { date: formatDate(post.due_at) }) }}
                        </span>
                    </div>

                    <div class="ui-task-card__title">{{ post.title }}</div>

                    <div v-if="post.body" class="ui-task-card__body-preview">{{ bodyPreview(post.body) }}</div>

                    <div class="ui-task-card__assignees">
                        <template v-if="post.assignees?.length">
                            <svg class="w-3.5 h-3.5 shrink-0 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <div class="ui-task-card__assignees-list">
                                <span
                                    v-for="a in post.assignees.slice(0, 4)"
                                    :key="a.id"
                                    class="ui-task-card__assignee"
                                    :title="a.name"
                                >
                                    <span class="ui-task-card__assignee-avatar">{{ initial(a.name) }}</span>
                                    {{ a.name }}
                                </span>
                                <span v-if="post.assignees.length > 4" class="ui-task-card__assignee-more">
                                    +{{ post.assignees.length - 4 }}
                                </span>
                            </div>
                        </template>
                        <span v-else class="ui-task-card__no-assignee">{{ t('organization.noAssignee') }}</span>
                    </div>

                    <div class="ui-task-card__meta">
                        <span class="ui-task-card__author">
                            <span class="ui-task-card__author-avatar">{{ initial(post.author?.name || '?') }}</span>
                            {{ post.author?.name || t('organization.noAuthor') }}
                        </span>
                        <span class="ui-task-card__meta-sep">·</span>
                        <span>{{ formatDate(post.created_at) }}</span>
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

                <Link
                    v-if="archived_count > 0"
                    :href="route('organization.archive')"
                    class="ui-org-archive-inline"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8l1 12a2 2 0 002 2h8a2 2 0 002-2L19 8M10 12v4m4-4v4" />
                    </svg>
                    {{ t('organization.deptArchiveLink') }}
                    <span class="ui-org-archive-inline__count">{{ archived_count }}</span>
                    <svg class="w-3.5 h-3.5 ml-auto opacity-50" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                </Link>
            </div>
        </div>

        <UiModal
            :open="showCreate"
            :title="t('organization.newTaskModalTitle')"
            max-width="2xl"
            :aria-label="t('organization.newTaskModalTitle')"
            @close="closeCreate"
        >
            <form class="ui-form-stack" @submit.prevent="submitCreate">
                <label class="ui-form-row">
                    <span>{{ t('organization.fieldTitle') }}</span>
                    <input v-model="draftTitle" type="text" maxlength="255" class="ui-field" :placeholder="t('organization.taskTitlePlaceholder')" autofocus />
                </label>
                <div class="ui-form-row">
                    <span>{{ t('organization.fieldDescription') }}</span>
                    <RichTextEditor
                        v-model="draftBody"
                        :placeholder="t('organization.taskDetailsPlaceholder')"
                        min-height="160px"
                    />
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="ui-form-row">
                        <span>{{ t('organization.fieldStatus') }}</span>
                        <select v-model="draftStatus" class="ui-field">
                            <option value="open">{{ t('organization.statusOpen') }}</option>
                            <option value="in_progress">{{ t('organization.statusInProgress') }}</option>
                            <option value="done">{{ t('organization.statusDone') }}</option>
                        </select>
                    </label>
                    <label class="ui-form-row">
                        <span>{{ t('organization.fieldDue') }}</span>
                        <input v-model="draftDue" type="datetime-local" class="ui-field" />
                    </label>
                </div>

                <div v-if="members.length > 0" class="ui-form-row">
                    <span>{{ t('organization.fieldAssignees') }}</span>
                    <div class="ui-assignee-picker">
                        <button
                            v-for="m in members"
                            :key="m.id"
                            type="button"
                            class="ui-assignee-chip"
                            :class="{ 'is-active': draftAssigneeIds.includes(m.id) }"
                            @click="toggleAssignee(m.id)"
                        >
                            <span class="ui-assignee-chip__avatar">{{ initial(m.name) }}</span>
                            {{ m.name }}
                            <svg
                                v-if="draftAssigneeIds.includes(m.id)"
                                class="w-3.5 h-3.5 ml-auto shrink-0"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.5"
                                viewBox="0 0 24 24"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </button>
                    </div>
                </div>

                <p v-if="submitError" class="text-sm m-0" :style="{ color: 'var(--wa-danger)' }">
                    {{ submitError }}
                </p>
                <div class="ui-modal-actions">
                    <button type="button" class="ui-btn ui-btn--secondary ui-btn--pill" @click="closeCreate">
                        {{ t('organization.cancel') }}
                    </button>
                    <button type="submit" class="ui-btn ui-btn--primary ui-btn--pill" :disabled="submitting">
                        {{ t('organization.create') }}
                    </button>
                </div>
            </form>
        </UiModal>
    </OrganizationLayout>
</template>
