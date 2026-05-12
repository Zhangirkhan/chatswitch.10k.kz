<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import axios from 'axios';
import OrganizationLayout from '@/Layouts/OrganizationLayout.vue';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import type { OrgDepartment } from './Partials/OrganizationSidebar.vue';

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
    if (submitting.value) return;
    if (draftTitle.value.trim() === '') {
        submitError.value = 'Введите заголовок задачи.';
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
            submitError.value = e.response?.data?.message || 'Не удалось создать задачу.';
        } else {
            submitError.value = 'Не удалось создать задачу.';
        }
    } finally {
        submitting.value = false;
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

function bodyPreview(body: string | null): string {
    if (!body) return '';
    const div = document.createElement('div');
    div.innerHTML = body;
    const text = div.textContent || div.innerText || '';
    const trimmed = text.trim();
    if (trimmed.length <= 220) return trimmed;
    return trimmed.slice(0, 220) + '…';
}

function initial(name: string): string {
    return name.trim().charAt(0).toUpperCase();
}
</script>

<template>
    <Head :title="`${department.name} · Организация`" />
    <OrganizationLayout
        :departments="departments"
        :selected-department-id="department.id"
    >
        <div class="flex flex-col h-full min-h-0">
            <!-- Header -->
            <div class="px-5 py-3 shrink-0 flex items-center gap-3 border-b" :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border)' }">
                <div class="w-10 h-10 rounded-full flex items-center justify-center" :style="{ background: 'var(--wa-panel)', color: 'var(--wa-icon)' }">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-base font-medium truncate text-[var(--wa-text)]">{{ department.name }}</div>
                    <div v-if="department.description" class="text-xs truncate text-[var(--wa-text-secondary)]">
                        {{ department.description }}
                    </div>
                </div>
                <button
                    type="button"
                    class="primary-btn"
                    @click="openCreate"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                    Создать пост
                </button>
            </div>

            <!-- Posts list -->
            <div class="flex-1 overflow-y-auto wa-scrollbar px-5 py-4">
                <div v-if="localPosts.length === 0" class="text-sm text-[var(--wa-text-secondary)] text-center py-12">
                    <span v-if="archived_count === 0">В этом отделе пока нет задач. Создайте первую задачу, чтобы начать обсуждение.</span>
                    <span v-else>Все задачи завершены.</span>
                </div>
                <Link
                    v-for="post in localPosts"
                    :key="post.id"
                    :href="route('organization.posts.show', post.id)"
                    class="post-card"
                >
                    <!-- Статус + срок -->
                    <div class="post-card-top">
                        <span class="status-pill" :class="`status-${post.status}`">{{ statusLabel(post.status) }}</span>
                        <span v-if="post.due_at" class="post-card-due">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            До {{ formatDate(post.due_at) }}
                        </span>
                    </div>

                    <!-- Заголовок -->
                    <div class="post-card-title">{{ post.title }}</div>

                    <!-- Превью тела -->
                    <div v-if="post.body" class="post-card-body">{{ bodyPreview(post.body) }}</div>

                    <!-- Ответственные -->
                    <div class="post-card-assignees">
                        <template v-if="post.assignees?.length">
                            <svg class="w-3.5 h-3.5 shrink-0 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <div class="post-card-assignees-list">
                                <span
                                    v-for="a in post.assignees.slice(0, 4)"
                                    :key="a.id"
                                    class="post-card-assignee"
                                    :title="a.name"
                                >
                                    <span class="post-card-assignee-avatar">{{ initial(a.name) }}</span>
                                    {{ a.name }}
                                </span>
                                <span v-if="post.assignees.length > 4" class="post-card-assignee-more">
                                    +{{ post.assignees.length - 4 }}
                                </span>
                            </div>
                        </template>
                        <span v-else class="post-card-no-assignee">Ответственный не назначен</span>
                    </div>

                    <!-- Мета: автор · дата · вложения · комментарии -->
                    <div class="post-card-meta">
                        <span class="post-card-author">
                            <span class="post-card-author-avatar">{{ initial(post.author?.name || '?') }}</span>
                            {{ post.author?.name || 'Без автора' }}
                        </span>
                        <span class="post-card-meta-sep">·</span>
                        <span>{{ formatDate(post.created_at) }}</span>
                        <span v-if="post.attachments?.length" class="post-card-meta-icon">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" /></svg>
                            {{ post.attachments.length }}
                        </span>
                        <span class="post-card-meta-icon ml-auto">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z" /></svg>
                            {{ post.comments_count }}
                        </span>
                    </div>
                </Link>
                <!-- Archive link -->
                <Link
                    v-if="archived_count > 0"
                    :href="route('organization.archive')"
                    class="archive-link"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8l1 12a2 2 0 002 2h8a2 2 0 002-2L19 8M10 12v4m4-4v4" />
                    </svg>
                    Архив задач отдела
                    <span class="archive-link-count">{{ archived_count }}</span>
                    <svg class="w-3.5 h-3.5 ml-auto opacity-50" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                </Link>
            </div>
        </div>

        <!-- Create modal -->
        <Teleport to="body">
            <div v-if="showCreate" class="org-modal-backdrop" @click.self="closeCreate">
                <div class="org-modal org-modal-wide">
                    <div class="org-modal-header">
                        <h3>Новый пост-задача</h3>
                        <button type="button" class="org-modal-close" @click="closeCreate" aria-label="Закрыть">×</button>
                    </div>
                    <form @submit.prevent="submitCreate" class="org-modal-body">
                        <label class="form-row">
                            <span>Заголовок</span>
                            <input v-model="draftTitle" type="text" maxlength="255" placeholder="Что нужно сделать" autofocus />
                        </label>
                        <div class="form-row">
                            <span>Описание</span>
                            <RichTextEditor
                                v-model="draftBody"
                                placeholder="Подробности задачи (опционально)"
                                min-height="160px"
                            />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="form-row">
                                <span>Статус</span>
                                <select v-model="draftStatus">
                                    <option value="open">Открыта</option>
                                    <option value="in_progress">В работе</option>
                                    <option value="done">Готово</option>
                                </select>
                            </label>
                            <label class="form-row">
                                <span>Срок</span>
                                <input v-model="draftDue" type="datetime-local" />
                            </label>
                        </div>

                        <!-- Assignees picker -->
                        <div v-if="members.length > 0" class="form-row">
                            <span>Ответственные</span>
                            <div class="assignee-picker">
                                <button
                                    v-for="m in members"
                                    :key="m.id"
                                    type="button"
                                    class="assignee-chip"
                                    :class="{ 'assignee-chip-active': draftAssigneeIds.includes(m.id) }"
                                    @click="toggleAssignee(m.id)"
                                >
                                    <span class="assignee-chip-avatar">{{ initial(m.name) }}</span>
                                    {{ m.name }}
                                    <svg v-if="draftAssigneeIds.includes(m.id)" class="w-3.5 h-3.5 ml-auto shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div v-if="submitError" class="text-sm" :style="{ color: 'var(--wa-danger)' }">
                            {{ submitError }}
                        </div>
                        <div class="org-modal-actions">
                            <button type="button" class="secondary-btn" @click="closeCreate">Отмена</button>
                            <button type="submit" class="primary-btn" :disabled="submitting">Создать</button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </OrganizationLayout>
</template>

<style scoped>
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
    padding: 0.45rem 0.9rem;
    border-radius: 999px;
    background: transparent;
    color: var(--wa-text);
    border: 1px solid var(--wa-border-strong);
    font-size: 0.85rem;
    cursor: pointer;
}
.secondary-btn:hover { background-color: var(--wa-panel-hover); }

.post-card {
    display: block;
    background: var(--wa-panel);
    border: 1px solid var(--wa-border);
    border-radius: 12px;
    padding: 0.85rem 1rem;
    margin-bottom: 0.65rem;
    text-decoration: none;
    color: var(--wa-text);
    transition: border-color 0.12s ease, transform 0.08s ease;
}
.post-card:hover {
    border-color: color-mix(in srgb, var(--wa-accent) 50%, var(--wa-border));
}
.post-card-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.45rem;
}
.post-card-title {
    font-size: 0.95rem;
    font-weight: 600;
    line-height: 1.3;
    color: var(--wa-text);
    margin-bottom: 0.3rem;
}
.post-card-body {
    font-size: 0.85rem;
    color: var(--wa-text-secondary);
    line-height: 1.4;
    margin-bottom: 0.5rem;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.post-card-due {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.72rem;
    color: var(--wa-text-secondary);
}

/* Assignees section */
.post-card-assignees {
    display: flex;
    align-items: flex-start;
    gap: 0.4rem;
    padding: 0.55rem 0.8rem;
    border-radius: 8px;
    background: color-mix(in srgb, var(--wa-accent) 6%, var(--wa-panel-header));
    border: 1px solid color-mix(in srgb, var(--wa-accent) 18%, var(--wa-border));
    min-height: 36px;
}
.post-card-assignees-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.3rem;
    flex: 1;
}
.post-card-assignee {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    font-size: 0.78rem;
    font-weight: 500;
    color: var(--wa-text);
    padding: 0.1rem 0.5rem 0.1rem 0.2rem;
    border-radius: 999px;
    background: color-mix(in srgb, var(--wa-accent) 14%, var(--wa-panel));
    border: 1px solid color-mix(in srgb, var(--wa-accent) 30%, var(--wa-border));
}
.post-card-assignee-avatar {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: var(--wa-accent);
    color: var(--wa-unread-text, #0b0d0e);
    font-size: 0.58rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.post-card-assignee-more {
    font-size: 0.72rem;
    color: var(--wa-text-secondary);
    padding: 0.1rem 0.3rem;
    align-self: center;
}
.post-card-no-assignee {
    font-size: 0.75rem;
    color: var(--wa-text-secondary);
    font-style: italic;
    align-self: center;
}

.post-card-meta {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.75rem;
    color: var(--wa-text-secondary);
}
.post-card-author {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
}
.post-card-author-avatar {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: var(--wa-panel-hover);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.58rem;
    font-weight: 700;
    flex-shrink: 0;
}
.post-card-meta-sep { opacity: 0.5; }
.post-card-meta-icon {
    display: inline-flex;
    align-items: center;
    gap: 0.2rem;
}

/* Assignee picker in modal */
.assignee-picker {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
    padding: 0.5rem 0;
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
    min-width: 0;
}
.assignee-chip:hover {
    background: var(--wa-panel-hover);
    border-color: color-mix(in srgb, var(--wa-accent) 40%, var(--wa-border));
}
.assignee-chip-active {
    background: color-mix(in srgb, var(--wa-accent) 18%, var(--wa-panel-header));
    border-color: var(--wa-accent);
    color: var(--wa-text);
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
.status-done { background: color-mix(in srgb, #22c55e 22%, transparent); color: #22c55e; }

.org-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.55);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 100;
    padding: 1rem;
}
.org-modal {
    background: var(--wa-panel);
    border-radius: 14px;
    width: 100%;
    max-width: 540px;
    max-height: 90vh;
    overflow-y: auto;
    border: 1px solid var(--wa-border-strong);
    color: var(--wa-text);
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.45);
}
.org-modal-wide { max-width: 720px; }
.org-modal-header {
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
.org-modal-header h3 { font-size: 1rem; font-weight: 600; margin: 0; }
.org-modal-close {
    background: transparent;
    border: none;
    color: var(--wa-text-secondary);
    font-size: 1.4rem;
    line-height: 1;
    cursor: pointer;
}
.org-modal-body {
    padding: 1rem 1.2rem 1.2rem;
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}
.form-row {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
    font-size: 0.85rem;
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
.org-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    padding-top: 0.4rem;
}

.archive-link {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.65rem 0.85rem;
    border-radius: 12px;
    border: 1px dashed var(--wa-border-strong);
    color: var(--wa-text-secondary);
    text-decoration: none;
    font-size: 0.83rem;
    margin-top: 0.25rem;
    transition: background-color 0.12s, border-color 0.12s, color 0.12s;
}
.archive-link:hover {
    background: color-mix(in srgb, #22c55e 8%, var(--wa-panel));
    border-color: color-mix(in srgb, #22c55e 45%, var(--wa-border));
    color: #22c55e;
}
.archive-link-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 0.4rem;
    border-radius: 999px;
    background: color-mix(in srgb, #22c55e 20%, transparent);
    color: #22c55e;
    font-size: 0.72rem;
    font-weight: 600;
}
</style>
