<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import axios from 'axios';
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import OrganizationLayout from '@/Layouts/OrganizationLayout.vue';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import type { OrgDepartment } from './Partials/OrganizationSidebar.vue';
import type { OrgPost, OrgAttachment, OrgAssignee } from './Department.vue';

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
    if (postDangerKind.value === 'post') return 'Удалить пост?';
    if (postDangerKind.value === 'comment') return 'Удалить комментарий?';
    return 'Удалить файл?';
});

const postDangerDescription = computed(() => {
    if (postDangerKind.value === 'post') return 'Пост и всё обсуждение под ним будут удалены.';
    if (postDangerKind.value === 'comment') return 'Комментарий будет удалён без возможности восстановления.';
    if (postDangerKind.value === 'attachment' && postDangerAttachment.value) {
        return `Удалить файл «${postDangerAttachment.value.original_name}»?`;
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
            alert(e.response?.data?.message || 'Не удалось выполнить действие.');
        } else {
            alert('Не удалось выполнить действие.');
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
    if (status === 'in_progress') return 'В работе';
    if (status === 'done') return 'Готово';
    return 'Открыта';
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
    if (bytes < 1024) return `${bytes} Б`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} КБ`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} МБ`;
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

async function saveEdit() {
    if (editSubmitting.value) return;
    if (editTitle.value.trim() === '') {
        editError.value = 'Введите заголовок задачи.';
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
            editError.value = e.response?.data?.message || 'Не удалось сохранить.';
        } else {
            editError.value = 'Не удалось сохранить.';
        }
    } finally {
        editSubmitting.value = false;
    }
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
            commentError.value = e.response?.data?.message || 'Не удалось отправить комментарий.';
        } else {
            commentError.value = 'Не удалось отправить комментарий.';
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
                uploadError.value = e.response?.data?.message || `Не удалось загрузить файл ${file.name}.`;
            } else {
                uploadError.value = `Не удалось загрузить файл ${file.name}.`;
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
        <div class="flex flex-col h-full min-h-0">
            <!-- Header -->
            <div class="px-5 py-3 shrink-0 flex items-center gap-3 border-b" :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border)' }">
                <Link
                    :href="route('organization.departments.show', department.id)"
                    class="back-btn"
                    aria-label="Назад к отделу"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </Link>
                <div class="min-w-0 flex-1">
                    <div class="text-xs text-[var(--wa-text-secondary)]">{{ department.name }}</div>
                    <div class="text-base font-medium truncate text-[var(--wa-text)]">{{ localPost.title }}</div>
                </div>
                <div v-if="canEditPost && !editing" class="flex items-center gap-2">
                    <button type="button" class="secondary-btn" @click="startEdit">Редактировать</button>
                    <button type="button" class="danger-btn" @click="requestDeletePost">Удалить</button>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto wa-scrollbar px-5 py-5">
                <!-- Post detail -->
                <div class="post-block">
                    <div v-if="!editing" class="post-view">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="status-pill" :class="`status-${localPost.status}`">{{ statusLabel(localPost.status) }}</span>
                            <span v-if="localPost.due_at" class="text-xs text-[var(--wa-text-secondary)]">
                                Срок: {{ formatDate(localPost.due_at) }}
                            </span>
                        </div>
                        <div class="post-title">{{ localPost.title }}</div>
                        <!-- Rendered HTML body -->
                        <div
                            v-if="localPost.body"
                            class="post-body post-body-html"
                            v-html="localPost.body"
                        ></div>

                        <!-- ── Информационная панель: ответственные + автор ── -->
                        <div class="post-info-panel">
                            <!-- Ответственные -->
                            <div class="post-info-row">
                                <div class="post-info-label">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                    Ответственные
                                </div>
                                <div class="post-info-value">
                                    <template v-if="localPost.assignees?.length">
                                        <div class="assignees-list">
                                            <div
                                                v-for="a in localPost.assignees"
                                                :key="a.id"
                                                class="assignee-card"
                                            >
                                                <span class="assignee-card-avatar">{{ initial(a.name) }}</span>
                                                <span class="assignee-card-name">{{ a.name }}</span>
                                            </div>
                                        </div>
                                    </template>
                                    <span v-else class="post-info-empty">Не назначены</span>
                                </div>
                            </div>

                            <!-- Автор + дата -->
                            <div class="post-info-row">
                                <div class="post-info-label">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>
                                    Автор
                                </div>
                                <div class="post-info-value">
                                    <div class="author-chip">
                                        <span class="author-avatar">{{ authorInitial(localPost.author?.name) }}</span>
                                        <span>{{ localPost.author?.name || 'Без автора' }}</span>
                                    </div>
                                    <span class="post-info-date">{{ formatDate(localPost.created_at) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Edit form -->
                    <form v-else @submit.prevent="saveEdit" class="post-edit">
                        <label class="form-row">
                            <span>Заголовок</span>
                            <input v-model="editTitle" type="text" maxlength="255" autofocus />
                        </label>
                        <div class="form-row">
                            <span>Описание</span>
                            <RichTextEditor
                                v-model="editBody"
                                placeholder="Подробности задачи"
                                min-height="160px"
                            />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="form-row">
                                <span>Статус</span>
                                <select v-model="editStatus">
                                    <option value="open">Открыта</option>
                                    <option value="in_progress">В работе</option>
                                    <option value="done">Готово</option>
                                </select>
                            </label>
                            <label class="form-row">
                                <span>Срок</span>
                                <input v-model="editDue" type="datetime-local" />
                            </label>
                        </div>

                        <!-- Assignees picker in edit form -->
                        <div v-if="members.length > 0" class="form-row">
                            <span>Ответственные</span>
                            <div class="assignee-picker">
                                <button
                                    v-for="m in members"
                                    :key="m.id"
                                    type="button"
                                    class="assignee-chip"
                                    :class="{ 'assignee-chip-active': editAssigneeIds.includes(m.id) }"
                                    @click="toggleEditAssignee(m.id)"
                                >
                                    <span class="assignee-chip-avatar">{{ initial(m.name) }}</span>
                                    {{ m.name }}
                                    <svg v-if="editAssigneeIds.includes(m.id)" class="w-3.5 h-3.5 ml-auto shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div v-if="editError" class="text-sm" :style="{ color: 'var(--wa-danger)' }">{{ editError }}</div>
                        <div class="flex items-center justify-end gap-2">
                            <button type="button" class="secondary-btn" @click="cancelEdit">Отмена</button>
                            <button type="submit" class="primary-btn" :disabled="editSubmitting">Сохранить</button>
                        </div>
                    </form>
                </div>

                <!-- Attachments block -->
                <div class="attachments-block">
                    <div class="attachments-header">
                        <span>Вложения</span>
                        <span class="comments-count">{{ attachments.length }}</span>
                        <button
                            v-if="canEditPost"
                            type="button"
                            class="attach-upload-btn"
                            :disabled="uploading"
                            @click="triggerFileInput"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                            </svg>
                            {{ uploading ? 'Загрузка…' : 'Прикрепить файл' }}
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
                        Нет вложений.
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
                                title="Удалить"
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
                                title="Удалить"
                                @click="requestDeleteAttachment(a)"
                            >×</button>
                        </div>
                    </div>
                </div>

                <!-- Comments thread -->
                <div class="comments-block">
                    <div class="comments-header">
                        Обсуждение
                        <span class="comments-count">{{ localComments.length }}</span>
                    </div>
                    <div v-if="localComments.length === 0" class="text-sm text-[var(--wa-text-secondary)]">
                        Пока нет комментариев. Начните обсуждение задачи ниже.
                    </div>
                    <div v-for="c in localComments" :key="c.id" class="comment">
                        <div class="comment-avatar">{{ authorInitial(c.author?.name) }}</div>
                        <div class="comment-bubble">
                            <div class="comment-head">
                                <span class="comment-author">{{ c.author?.name || 'Без автора' }}</span>
                                <span class="comment-time">{{ formatDate(c.created_at) }}</span>
                                <button
                                    v-if="canDeleteComment(c)"
                                    type="button"
                                    class="comment-delete"
                                    @click="requestDeleteComment(c)"
                                    aria-label="Удалить"
                                >×</button>
                            </div>
                            <div class="comment-body">{{ c.body }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Add comment -->
            <div class="comment-composer">
                <div v-if="commentError" class="text-sm" :style="{ color: 'var(--wa-danger)' }">{{ commentError }}</div>
                <div class="flex items-end gap-2">
                    <textarea
                        v-model="newComment"
                        rows="2"
                        placeholder="Написать комментарий…"
                        maxlength="5000"
                        @keydown.enter.prevent.exact="submitComment"
                    ></textarea>
                    <button
                        type="button"
                        class="primary-btn"
                        :disabled="sendingComment || newComment.trim() === ''"
                        @click="submitComment"
                    >
                        Отправить
                    </button>
                </div>
            </div>
        </div>
    </OrganizationLayout>

    <DangerConfirmModal
        :open="postDangerOpen"
        :title="postDangerTitle"
        :description="postDangerDescription"
        confirm-label="Удалить"
        :busy="postDangerBusy"
        confirm-variant="danger"
        @close="closePostDanger"
        @confirm="confirmPostDanger"
    />
</template>

<style scoped>
.back-btn {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: var(--wa-icon);
    cursor: pointer;
    transition: background-color 0.12s ease;
}
.back-btn:hover { background-color: var(--wa-panel-hover); }

.primary-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.45rem 0.9rem;
    border-radius: 999px;
    background: var(--wa-accent);
    color: var(--wa-unread-text, #0b0d0e);
    font-size: 0.85rem;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: filter 0.12s ease;
}
.primary-btn:hover:not(:disabled) { filter: brightness(1.05); }
.primary-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.secondary-btn {
    padding: 0.4rem 0.85rem;
    border-radius: 999px;
    background: transparent;
    color: var(--wa-text);
    border: 1px solid var(--wa-border-strong);
    font-size: 0.82rem;
    cursor: pointer;
}
.secondary-btn:hover { background-color: var(--wa-panel-hover); }
.danger-btn {
    padding: 0.4rem 0.85rem;
    border-radius: 999px;
    background: transparent;
    color: var(--wa-danger);
    border: 1px solid color-mix(in srgb, var(--wa-danger) 60%, transparent);
    font-size: 0.82rem;
    cursor: pointer;
}
.danger-btn:hover { background-color: color-mix(in srgb, var(--wa-danger) 12%, transparent); }

.post-block {
    background: var(--wa-panel);
    border: 1px solid var(--wa-border);
    border-radius: 14px;
    padding: 1.1rem 1.2rem;
    margin-bottom: 1rem;
}
.post-title {
    font-size: 1.15rem;
    font-weight: 600;
    color: var(--wa-text);
    margin-bottom: 0.6rem;
    line-height: 1.3;
}
.post-body {
    font-size: 0.92rem;
    color: var(--wa-text);
    line-height: 1.55;
    margin-bottom: 0.8rem;
}
.post-meta {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    font-size: 0.78rem;
    color: var(--wa-text-secondary);
    margin-top: 0.6rem;
}
.author-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    color: var(--wa-text);
}
.author-avatar {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: color-mix(in srgb, var(--wa-accent) 28%, var(--wa-panel));
    color: var(--wa-text);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    font-weight: 600;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    padding: 0.1rem 0.55rem;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 600;
    line-height: 1.4;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}
.status-open { background: color-mix(in srgb, #f59e0b 22%, transparent); color: #f59e0b; }
.status-in_progress { background: color-mix(in srgb, #3b82f6 22%, transparent); color: #3b82f6; }
.status-done { background: color-mix(in srgb, var(--wa-accent) 22%, transparent); color: var(--wa-accent); }

.post-edit {
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}
.form-row {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
    font-size: 0.82rem;
    color: var(--wa-text-secondary);
}
.form-row input,
.form-row select {
    background: var(--wa-panel-header);
    color: var(--wa-text);
    border: 1px solid var(--wa-border-strong);
    border-radius: 10px;
    padding: 0.5rem 0.75rem;
    font-size: 0.9rem;
    width: 100%;
}

/* Attachments */
.attachments-block {
    background: var(--wa-panel);
    border: 1px solid var(--wa-border);
    border-radius: 14px;
    padding: 0.85rem 1.1rem;
    margin-bottom: 1rem;
}
.attachments-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--wa-text);
    margin-bottom: 0.6rem;
}
.attach-upload-btn {
    margin-left: auto;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.78rem;
    font-weight: 500;
    padding: 0.25rem 0.7rem;
    border-radius: 999px;
    border: 1px solid var(--wa-border-strong);
    background: transparent;
    color: var(--wa-text-secondary);
    cursor: pointer;
    transition: background-color 0.1s;
}
.attach-upload-btn:hover:not(:disabled) {
    background: var(--wa-panel-hover);
    color: var(--wa-text);
}
.attach-upload-btn:disabled { opacity: 0.5; cursor: default; }

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

/* Comments */
.comments-block {
    display: flex;
    flex-direction: column;
    gap: 0.7rem;
    padding-bottom: 1rem;
}
.comments-header {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--wa-text);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.2rem;
}
.comments-count {
    background: var(--wa-panel-header);
    color: var(--wa-text-secondary);
    font-size: 0.7rem;
    font-weight: 600;
    border-radius: 999px;
    padding: 0.05rem 0.45rem;
}
.comment {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
}
.comment-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--wa-panel-header);
    color: var(--wa-text);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.78rem;
    font-weight: 600;
    flex-shrink: 0;
    margin-top: 2px;
}
.comment-bubble {
    background: var(--wa-panel);
    border: 1px solid var(--wa-border);
    border-radius: 12px;
    padding: 0.5rem 0.8rem;
    flex: 1;
    min-width: 0;
}
.comment-head {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    margin-bottom: 0.2rem;
    font-size: 0.78rem;
}
.comment-author { font-weight: 600; color: var(--wa-text); }
.comment-time { color: var(--wa-text-secondary); }
.comment-delete {
    margin-left: auto;
    background: transparent;
    border: none;
    color: var(--wa-text-secondary);
    cursor: pointer;
    font-size: 1.1rem;
    line-height: 1;
}
.comment-delete:hover { color: var(--wa-danger); }
.comment-body {
    font-size: 0.88rem;
    color: var(--wa-text);
    white-space: pre-wrap;
    line-height: 1.4;
}

/* ── Информационная панель ─────────────────────────────────────────────────── */
.post-info-panel {
    margin-top: 1.25rem;
    border: 1px solid var(--wa-border);
    border-radius: 12px;
    overflow: hidden;
    background: var(--wa-panel-header);
}
.post-info-row {
    display: grid;
    grid-template-columns: 130px 1fr;
    gap: 0.5rem;
    padding: 0.7rem 1rem;
    align-items: start;
    border-bottom: 1px solid var(--wa-border);
}
.post-info-row:last-child { border-bottom: none; }

.post-info-label {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--wa-text-secondary);
    white-space: nowrap;
    padding-top: 2px;
}
.post-info-value {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
}
.post-info-empty {
    font-size: 0.82rem;
    color: var(--wa-text-secondary);
    font-style: italic;
}
.post-info-date {
    font-size: 0.75rem;
    color: var(--wa-text-secondary);
}

/* Assignees list in info panel */
.assignees-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}
.assignee-card {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.3rem 0.75rem 0.3rem 0.35rem;
    border-radius: 999px;
    background: color-mix(in srgb, var(--wa-accent) 12%, var(--wa-panel));
    border: 1px solid color-mix(in srgb, var(--wa-accent) 28%, var(--wa-border));
    font-size: 0.82rem;
    font-weight: 500;
    color: var(--wa-text);
}
.assignee-card-avatar {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: var(--wa-accent);
    color: var(--wa-unread-text, #0b0d0e);
    font-size: 0.65rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.assignee-card-name {
    line-height: 1;
}

/* Legacy classes kept for backward compat (edit form picker) */
.assignees-row { display: none; } /* replaced by post-info-panel */
.assignee-badge { display: none; }
.assignee-badge-avatar { display: none; }

/* Assignee picker in edit form */
.assignee-picker {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
    padding: 0.4rem 0;
}
.assignee-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.3rem 0.65rem 0.3rem 0.35rem;
    border-radius: 999px;
    border: 1px solid var(--wa-border-strong);
    background: var(--wa-panel-header);
    color: var(--wa-text);
    font-size: 0.82rem;
    cursor: pointer;
    transition: background-color 0.1s, border-color 0.1s;
}
.assignee-chip:hover {
    background: var(--wa-panel-hover);
    border-color: color-mix(in srgb, var(--wa-accent) 40%, var(--wa-border));
}
.assignee-chip-active {
    background: color-mix(in srgb, var(--wa-accent) 18%, var(--wa-panel-header));
    border-color: var(--wa-accent);
}
.assignee-chip-avatar {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: color-mix(in srgb, var(--wa-accent) 30%, var(--wa-panel));
    color: var(--wa-text);
    font-size: 0.65rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.comment-composer {
    border-top: 1px solid var(--wa-border);
    background: var(--wa-panel-header);
    padding: 0.7rem 1rem 0.85rem;
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}
.comment-composer textarea {
    flex: 1;
    background: var(--wa-panel);
    color: var(--wa-text);
    border: 1px solid var(--wa-border-strong);
    border-radius: 10px;
    padding: 0.5rem 0.75rem;
    font-size: 0.9rem;
    font-family: inherit;
    resize: vertical;
    min-height: 56px;
    max-height: 200px;
    width: 100%;
}
</style>
