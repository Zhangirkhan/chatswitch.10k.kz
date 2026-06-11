<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import axios from 'axios';
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import SpeechDictationButton from '@/Components/AiChat/SpeechDictationButton.vue';
import OrganizationLayout from '@/Layouts/OrganizationLayout.vue';
import { appendSpeechText, highlightSpeechInput } from '@/utils/appendSpeechText';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import { useI18n } from '@/composables/useI18n';
import { useToastStore } from '@/stores/toast';
import type { OrgDepartment } from './Partials/OrganizationSidebar.vue';
import type { OrgPost, OrgAttachment, OrgAssignee } from './Department.vue';

const { t } = useI18n();

const { show: showToast } = useToastStore();

interface OrgComment {
    id: number;
    post_id: number;
    body: string;
    author: { id: number; name: string } | null;
    created_at: string | null;
}

const props = defineProps<{
    departments: OrgDepartment[];
    department: { id: number; name: string };
    post: OrgPost;
    comments: OrgComment[];
    members: OrgAssignee[];
}>();

const page = usePage<any>();
const currentUserId = computed<number | null>(() => page.props.auth?.user?.id ?? null);
const isAdmin = computed<boolean>(() => Array.isArray(page.props.auth?.user?.roles) && page.props.auth.user.roles.includes('administrator'));

const localPost = ref<OrgPost>({ ...props.post });
const localComments = ref<OrgComment[]>([...props.comments]);

const newComment = ref('');
const commentTextareaRef = ref<HTMLTextAreaElement | null>(null);
const sendingComment = ref(false);
const commentError = ref<string | null>(null);

const canEditPost = computed<boolean>(() => isAdmin.value || (localPost.value.author?.id === currentUserId.value));

const editing = ref(false);
const editTitle = ref(localPost.value.title);
const editBody = ref(localPost.value.body || '');
const editStatus = ref<OrgPost['status']>(localPost.value.status);
const editDue = ref<string>(localPost.value.due_at ? toLocalDateTime(localPost.value.due_at) : '');
const editAssigneeIds = ref<number[]>(localPost.value.assignees?.map((a) => a.id) ?? []);
const editError = ref<string | null>(null);
const editSubmitting = ref(false);
const completingPost = ref(false);

function toggleEditAssignee(userId: number) {
    const idx = editAssigneeIds.value.indexOf(userId);
    if (idx === -1) {
        editAssigneeIds.value = [...editAssigneeIds.value, userId];
    } else {
        editAssigneeIds.value = editAssigneeIds.value.filter((id) => id !== userId);
    }
}

// --- Attachments ---
const attachments = ref<OrgAttachment[]>([...(props.post.attachments ?? [])]);
const uploading = ref(false);
const uploadError = ref<string | null>(null);
const fileInputRef = ref<HTMLInputElement | null>(null);

type PostDangerKind = 'post' | 'comment' | 'attachment';

const postDangerOpen = ref(false);
const postDangerKind = ref<PostDangerKind | null>(null);
const postDangerComment = ref<OrgComment | null>(null);
const postDangerAttachment = ref<OrgAttachment | null>(null);
const postDangerBusy = ref(false);

const postDangerTitle = computed(() => {
    if (postDangerKind.value === 'post') return t('organization.deletePostTitle');
    if (postDangerKind.value === 'comment') return t('organization.deleteCommentTitle');
    return t('organization.deleteFileTitle');
});

const postDangerDescription = computed(() => {
    if (postDangerKind.value === 'post') return t('organization.deletePostDesc');
    if (postDangerKind.value === 'comment') return t('organization.deleteCommentDesc');
    if (postDangerKind.value === 'attachment' && postDangerAttachment.value) {
        return t('organization.deleteFileDesc', { name: postDangerAttachment.value.original_name });
    }
    return '';
});

function closePostDanger(): void {
    if (postDangerBusy.value) return;
    postDangerOpen.value = false;
    postDangerKind.value = null;
    postDangerComment.value = null;
    postDangerAttachment.value = null;
}

function requestDeletePost(): void {
    postDangerKind.value = 'post';
    postDangerComment.value = null;
    postDangerAttachment.value = null;
    postDangerOpen.value = true;
}

function requestDeleteComment(c: OrgComment): void {
    postDangerKind.value = 'comment';
    postDangerComment.value = c;
    postDangerAttachment.value = null;
    postDangerOpen.value = true;
}

function requestDeleteAttachment(a: OrgAttachment): void {
    postDangerKind.value = 'attachment';
    postDangerComment.value = null;
    postDangerAttachment.value = a;
    postDangerOpen.value = true;
}

async function confirmPostDanger(): Promise<void> {
    const kind = postDangerKind.value;
    if (!kind) return;
    postDangerBusy.value = true;
    try {
        if (kind === 'post') {
            await axios.delete(route('organization.posts.destroy', localPost.value.id));
            await router.visit(route('organization.departments.show', localPost.value.department_id));
            return;
        }
        if (kind === 'comment' && postDangerComment.value) {
            const c = postDangerComment.value;
            await axios.delete(route('organization.posts.comments.destroy', [localPost.value.id, c.id]));
            localComments.value = localComments.value.filter((x) => x.id !== c.id);
            localPost.value = { ...localPost.value, comments_count: Math.max(0, localPost.value.comments_count - 1) };
        } else if (kind === 'attachment' && postDangerAttachment.value) {
            const a = postDangerAttachment.value;
            await axios.delete(route('organization.posts.attachments.destroy', [localPost.value.id, a.id]));
            attachments.value = attachments.value.filter((x) => x.id !== a.id);
        }
        closePostDanger();
    } catch (e: unknown) {
        if (axios.isAxiosError(e)) {
            showToast({ message: e.response?.data?.message || t('organization.actionFailed'), type: 'warning' });
        } else {
            showToast({ message: t('organization.actionFailed'), type: 'warning' });
        }
    } finally {
        postDangerBusy.value = false;
    }
}

function toLocalDateTime(iso: string): string {
    try {
        const d = new Date(iso);
        const tz = d.getTimezoneOffset() * 60000;
        return new Date(d.getTime() - tz).toISOString().slice(0, 16);
    } catch {
        return '';
    }
}

function statusLabel(status: OrgPost['status']): string {
    if (status === 'in_progress') return t('organization.statusInProgress');
    if (status === 'done') return t('organization.statusDone');
    return t('organization.statusOpen');
}

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

function formatFileSize(bytes: number): string {
    if (bytes < 1024) return t('organization.fileSizeB', { size: bytes });
    if (bytes < 1024 * 1024) return t('organization.fileSizeKb', { size: (bytes / 1024).toFixed(1) });
    return t('organization.fileSizeMb', { size: (bytes / (1024 * 1024)).toFixed(1) });
}

function startEdit() {
    editTitle.value = localPost.value.title;
    editBody.value = localPost.value.body || '';
    editStatus.value = localPost.value.status;
    editDue.value = localPost.value.due_at ? toLocalDateTime(localPost.value.due_at) : '';
    editAssigneeIds.value = localPost.value.assignees?.map((a) => a.id) ?? [];
    editError.value = null;
    editing.value = true;
}

function cancelEdit() {
    editing.value = false;
    editError.value = null;
}

async function completePost(): Promise<void> {
    if (completingPost.value) {
        return;
    }
    completingPost.value = true;
    try {
        const { data } = await axios.post(route('organization.posts.complete', localPost.value.id));
        localPost.value = { ...localPost.value, ...data.post };
        showToast({ message: t('organization.taskCompleted'), type: 'info' });
    } catch (e: unknown) {
        if (axios.isAxiosError(e)) {
            showToast({ message: e.response?.data?.message || t('organization.actionFailed'), type: 'warning' });
        } else {
            showToast({ message: t('organization.actionFailed'), type: 'warning' });
        }
    } finally {
        completingPost.value = false;
    }
}

async function saveEdit() {
    if (editSubmitting.value) return;
    if (editTitle.value.trim() === '') {
        editError.value = t('organization.taskTitleRequired');
        return;
    }
    editSubmitting.value = true;
    editError.value = null;
    try {
        const { data } = await axios.patch(route('organization.posts.update', localPost.value.id), {
            title: editTitle.value.trim(),
            body: editBody.value || null,
            status: editStatus.value,
            due_at: editDue.value ? new Date(editDue.value).toISOString() : null,
            assignee_ids: editAssigneeIds.value,
        });
        localPost.value = { ...localPost.value, ...data.post };
        editing.value = false;
    } catch (e: unknown) {
        if (axios.isAxiosError(e)) {
            editError.value = e.response?.data?.message || t('organization.saveFailed');
        } else {
            editError.value = t('organization.saveFailed');
        }
    } finally {
        editSubmitting.value = false;
    }
}

function appendCommentSpeech(text: string): void {
    newComment.value = appendSpeechText(newComment.value, text);
    highlightSpeechInput(commentTextareaRef.value);
    commentTextareaRef.value?.focus();
}

function onSpeechError(message: string): void {
    showToast({ message, type: 'warning' });
}

async function submitComment() {
    if (sendingComment.value) return;
    if (newComment.value.trim() === '') return;
    sendingComment.value = true;
    commentError.value = null;
    try {
        const { data } = await axios.post(
            route('organization.posts.comments.store', localPost.value.id),
            { body: newComment.value.trim() },
        );
        localComments.value = [...localComments.value, data.comment];
        localPost.value = { ...localPost.value, comments_count: localPost.value.comments_count + 1 };
        newComment.value = '';
    } catch (e: unknown) {
        if (axios.isAxiosError(e)) {
            commentError.value = e.response?.data?.message || t('organization.commentFailed');
        } else {
            commentError.value = t('organization.commentFailed');
        }
    } finally {
        sendingComment.value = false;
    }
}

function canDeleteComment(c: OrgComment): boolean {
    return isAdmin.value || (c.author?.id === currentUserId.value);
}

function authorInitial(name: string | undefined | null): string {
    if (!name) return '?';
    return name.trim().charAt(0).toUpperCase();
}

function initial(name: string): string {
    return name.trim().charAt(0).toUpperCase();
}

function triggerFileInput() {
    fileInputRef.value?.click();
}

async function onFilesSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const files = input.files;
    if (!files || files.length === 0) return;

    uploading.value = true;
    uploadError.value = null;

    for (const file of Array.from(files)) {
        try {
            const formData = new FormData();
            formData.append('file', file);
            const { data } = await axios.post(
                route('organization.posts.attachments.store', localPost.value.id),
                formData,
                { headers: { 'Content-Type': 'multipart/form-data' } },
            );
            attachments.value = [...attachments.value, data.attachment];
        } catch (e: unknown) {
            if (axios.isAxiosError(e)) {
                uploadError.value = e.response?.data?.message || t('organization.uploadFailed', { name: file.name });
            } else {
                uploadError.value = t('organization.uploadFailed', { name: file.name });
            }
        }
    }

    uploading.value = false;
    input.value = '';
}

function canDeleteAttachment(a: OrgAttachment): boolean {
    return isAdmin.value || a.uploaded_by === currentUserId.value;
}
</script>

<template>
    <Head :title="`${post.title} · ${department.name}`" />
    <OrganizationLayout
        :departments="departments"
        :selected-department-id="department.id"
    >
        <div class="flex flex-col h-full min-h-0 bg-[var(--wa-page-bg)]">
            <header class="ui-page-header">
                <Link
                    :href="route('organization.departments.show', department.id)"
                    class="ui-page-header__back"
                    :aria-label="t('organization.backAria')"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </Link>
                <div class="ui-page-header__body min-w-0">
                    <p class="ui-page-header__subtitle m-0">{{ department.name }}</p>
                    <h1 class="ui-page-header__title truncate">{{ localPost.title }}</h1>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <button
                        v-if="localPost.status !== 'done'"
                        type="button"
                        class="ui-btn ui-btn--primary ui-btn--pill ui-btn--sm"
                        :disabled="completingPost"
                        @click="completePost"
                    >
                        {{ completingPost ? t('organization.completingTask') : t('organization.completeTask') }}
                    </button>
                    <template v-if="canEditPost && !editing">
                        <button type="button" class="ui-btn ui-btn--secondary ui-btn--pill ui-btn--sm" @click="startEdit">{{ t('organization.edit') }}</button>
                        <button type="button" class="ui-btn ui-btn--danger-ghost ui-btn--pill ui-btn--sm" @click="requestDeletePost">{{ t('organization.delete') }}</button>
                    </template>
                </div>
            </header>

            <div class="ui-org-post-scroll wa-scrollbar">
                <div class="ui-post-panel">
                    <div v-if="!editing">
                        <div class="flex items-center gap-2 mb-3">
                            <span
                                class="ui-task-status ui-task-status--pill"
                                :class="`ui-task-status--${localPost.status}`"
                            >{{ statusLabel(localPost.status) }}</span>
                            <span v-if="localPost.due_at" class="text-xs text-[var(--wa-text-secondary)]">
                                {{ t('organization.dueLabel') }} {{ formatDate(localPost.due_at) }}
                            </span>
                        </div>
                        <h2 class="ui-post-panel__title">{{ localPost.title }}</h2>
                        <div
                            v-if="localPost.body"
                            class="ui-post-panel__body post-body-html"
                            v-html="localPost.body"
                        ></div>

                        <div class="ui-post-info">
                            <div class="ui-post-info__row">
                                <div class="ui-post-info__label">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                    {{ t('organization.fieldAssignees') }}
                                </div>
                                <div class="ui-post-info__value">
                                    <template v-if="localPost.assignees?.length">
                                        <span
                                            v-for="a in localPost.assignees"
                                            :key="a.id"
                                            class="ui-post-assignee-card"
                                        >
                                            <span class="ui-post-assignee-card__avatar">{{ initial(a.name) }}</span>
                                            {{ a.name }}
                                        </span>
                                    </template>
                                    <span v-else class="text-[var(--wa-text-secondary)] italic text-sm">{{ t('organization.noAssignee') }}</span>
                                </div>
                            </div>

                            <div class="ui-post-info__row">
                                <div class="ui-post-info__label">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>
                                    {{ t('organization.author') }}
                                </div>
                                <div class="ui-post-info__value flex flex-wrap items-center gap-2">
                                    <span class="ui-post-author-chip">
                                        <span class="ui-post-author-chip__avatar">{{ authorInitial(localPost.author?.name) }}</span>
                                        <span>{{ localPost.author?.name || t('organization.noAuthor') }}</span>
                                    </span>
                                    <span class="text-xs text-[var(--wa-text-secondary)]">{{ formatDate(localPost.created_at) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <form v-else class="ui-form-stack" @submit.prevent="saveEdit">
                        <label class="ui-form-row">
                            <span>{{ t('organization.fieldTitle') }}</span>
                            <input v-model="editTitle" type="text" maxlength="255" class="ui-field" autofocus />
                        </label>
                        <div class="ui-form-row">
                            <span>{{ t('organization.fieldDescription') }}</span>
                            <RichTextEditor
                                v-model="editBody"
                                :placeholder="t('organization.taskDetailsShort')"
                                min-height="160px"
                            />
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <label class="ui-form-row">
                                <span>{{ t('organization.fieldStatus') }}</span>
                                <select v-model="editStatus" class="ui-field">
                                    <option value="open">{{ t('organization.statusOpen') }}</option>
                                    <option value="in_progress">{{ t('organization.statusInProgress') }}</option>
                                    <option value="done">{{ t('organization.statusDone') }}</option>
                                </select>
                            </label>
                            <label class="ui-form-row">
                                <span>{{ t('organization.fieldDue') }}</span>
                                <input v-model="editDue" type="datetime-local" class="ui-field" />
                            </label>
                        </div>

                        <div v-if="members.length > 0" class="ui-form-row">
                            <span>{{ t('organization.fieldAssignees') }}</span>
                            <div class="ui-assignee-picker flex-wrap">
                                <button
                                    v-for="m in members"
                                    :key="m.id"
                                    type="button"
                                    class="ui-assignee-chip"
                                    :class="{ 'is-active': editAssigneeIds.includes(m.id) }"
                                    @click="toggleEditAssignee(m.id)"
                                >
                                    <span class="ui-assignee-chip__avatar">{{ initial(m.name) }}</span>
                                    {{ m.name }}
                                    <svg v-if="editAssigneeIds.includes(m.id)" class="w-3.5 h-3.5 ml-auto shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <p v-if="editError" class="text-sm m-0" :style="{ color: 'var(--wa-danger)' }">{{ editError }}</p>
                        <div class="ui-modal-actions">
                            <button type="button" class="ui-btn ui-btn--secondary ui-btn--pill" @click="cancelEdit">{{ t('organization.cancel') }}</button>
                            <button type="submit" class="ui-btn ui-btn--primary ui-btn--pill" :disabled="editSubmitting">{{ t('organization.save') }}</button>
                        </div>
                    </form>
                </div>

                <div class="ui-post-panel">
                    <div class="ui-post-section-head">
                        <span>{{ t('organization.attachments') }}</span>
                        <span class="ui-post-count-badge">{{ attachments.length }}</span>
                        <button
                            v-if="canEditPost"
                            type="button"
                            class="ui-btn ui-btn--ghost ui-btn--pill ui-btn--sm ml-auto"
                            :disabled="uploading"
                            @click="triggerFileInput"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                            </svg>
                            {{ uploading ? t('organization.uploading') : t('organization.attachFile') }}
                        </button>
                        <input
                            ref="fileInputRef"
                            type="file"
                            multiple
                            class="hidden"
                            @change="onFilesSelected"
                        />
                    </div>

                    <div v-if="uploadError" class="text-sm mb-2" :style="{ color: 'var(--wa-danger)' }">{{ uploadError }}</div>

                    <div v-if="attachments.length === 0 && !uploading" class="text-sm text-[var(--wa-text-secondary)]">
                        {{ t('organization.noAttachments') }}
                    </div>

                    <!-- Image previews -->
                    <div v-if="attachments.some((a) => a.is_image)" class="attach-images">
                        <div
                            v-for="a in attachments.filter((x) => x.is_image)"
                            :key="a.id"
                            class="attach-img-wrap"
                        >
                            <a :href="a.url" target="_blank" rel="noopener">
                                <img :src="a.url" :alt="a.original_name" class="attach-img" />
                            </a>
                            <button
                                v-if="canDeleteAttachment(a)"
                                type="button"
                                class="attach-delete"
                                :title="t('organization.deleteAria')"
                                @click="requestDeleteAttachment(a)"
                            >×</button>
                        </div>
                    </div>

                    <!-- File list (non-images) -->
                    <div v-if="attachments.some((a) => !a.is_image)" class="attach-files">
                        <div
                            v-for="a in attachments.filter((x) => !x.is_image)"
                            :key="a.id"
                            class="attach-file"
                        >
                            <svg class="w-4 h-4 shrink-0 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                            </svg>
                            <a :href="a.url" target="_blank" rel="noopener" class="attach-file-name truncate">{{ a.original_name }}</a>
                            <span class="text-xs text-[var(--wa-text-secondary)] shrink-0">{{ formatFileSize(a.size) }}</span>
                            <button
                                v-if="canDeleteAttachment(a)"
                                type="button"
                                class="attach-delete-sm"
                                :title="t('organization.deleteAria')"
                                @click="requestDeleteAttachment(a)"
                            >×</button>
                        </div>
                    </div>
                </div>

                <div class="ui-post-panel">
                    <div class="ui-post-section-head">
                        {{ t('organization.discussion') }}
                        <span class="ui-post-count-badge">{{ localComments.length }}</span>
                    </div>
                    <div v-if="localComments.length === 0" class="text-sm text-[var(--wa-text-secondary)]">
                        {{ t('organization.noComments') }}
                    </div>
                    <div v-for="c in localComments" :key="c.id" class="ui-comment">
                        <div class="ui-comment__avatar">{{ authorInitial(c.author?.name) }}</div>
                        <div class="ui-comment__bubble">
                            <div class="ui-comment__head">
                                <span class="ui-comment__author">{{ c.author?.name || t('organization.noAuthor') }}</span>
                                <span class="ui-comment__time">{{ formatDate(c.created_at) }}</span>
                                <button
                                    v-if="canDeleteComment(c)"
                                    type="button"
                                    class="ui-comment__delete"
                                    @click="requestDeleteComment(c)"
                                    :aria-label="t('organization.deleteAria')"
                                >×</button>
                            </div>
                            <div class="ui-comment__body">{{ c.body }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="ui-comment-composer">
                <p v-if="commentError" class="text-sm m-0 mb-2" :style="{ color: 'var(--wa-danger)' }">{{ commentError }}</p>
                <div class="flex items-end gap-2">
                    <SpeechDictationButton
                        :disabled="sendingComment"
                        size="sm"
                        @transcript="appendCommentSpeech"
                        @error="onSpeechError"
                    />
                    <textarea
                        ref="commentTextareaRef"
                        v-model="newComment"
                        rows="2"
                        :placeholder="t('organization.commentPlaceholder')"
                        maxlength="5000"
                        @keydown.enter.prevent.exact="submitComment"
                    ></textarea>
                    <button
                        type="button"
                        class="ui-btn ui-btn--primary ui-btn--pill shrink-0"
                        :disabled="sendingComment || newComment.trim() === ''"
                        @click="submitComment"
                    >
                        {{ t('organization.sendComment') }}
                    </button>
                </div>
            </div>
        </div>
    </OrganizationLayout>

    <DangerConfirmModal
        :open="postDangerOpen"
        :title="postDangerTitle"
        :description="postDangerDescription"
        :confirm-label="t('organization.delete')"
        :busy="postDangerBusy"
        confirm-variant="danger"
        @close="closePostDanger"
        @confirm="confirmPostDanger"
    />
</template>

<style scoped>
.attach-images {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
    margin-bottom: 0.6rem;
}
.attach-img-wrap {
    position: relative;
    width: 100px;
    height: 100px;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid var(--wa-border);
}
.attach-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.attach-delete {
    position: absolute;
    top: 2px;
    right: 4px;
    background: rgba(0,0,0,0.55);
    color: #fff;
    border: none;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    font-size: 14px;
    line-height: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}
.attach-delete:hover { background: rgba(220,38,38,0.85); }

.attach-files {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}
.attach-file {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.35rem 0.6rem;
    border-radius: 8px;
    background: var(--wa-panel-header);
    font-size: 0.82rem;
}
.attach-file-name {
    flex: 1;
    color: var(--wa-accent);
    text-decoration: none;
    min-width: 0;
}
.attach-file-name:hover { text-decoration: underline; }
.attach-delete-sm {
    background: transparent;
    border: none;
    color: var(--wa-text-secondary);
    font-size: 1rem;
    cursor: pointer;
    line-height: 1;
    padding: 0 2px;
    flex-shrink: 0;
}
.attach-delete-sm:hover { color: var(--wa-danger); }
</style>
