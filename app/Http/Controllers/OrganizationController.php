<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DepartmentPost;
use App\Models\DepartmentPostAttachment;
use App\Models\DepartmentPostComment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\OrganizationRichTextSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class OrganizationController extends Controller
{
    public function index(Request $request): Response
    {
        $this->ensureModuleEnabled();

        return Inertia::render('Organization/Index', [
            'departments' => $this->departmentsPayload($request->user()),
        ]);
    }

    public function showDepartment(Request $request, Department $department): Response
    {
        $this->ensureModuleEnabled();
        $this->authorizeDepartmentAccess($request->user(), $department);

        $activePosts = $department->posts()
            ->whereIn('status', [DepartmentPost::STATUS_OPEN, DepartmentPost::STATUS_IN_PROGRESS])
            ->with(['author:id,name', 'assignees:id,name'])
            ->withCount('comments')
            ->orderByRaw("FIELD(status, 'in_progress', 'open')")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (DepartmentPost $post) => $this->transformPost($post));

        $archivedCount = $department->posts()
            ->where('status', DepartmentPost::STATUS_DONE)
            ->count();

        return Inertia::render('Organization/Department', [
            'departments' => $this->departmentsPayload($request->user()),
            'department' => $this->transformDepartment($department),
            'posts' => $activePosts,
            'archived_count' => $archivedCount,
            'members' => $this->departmentMembersPayload($department),
        ]);
    }

    public function archive(Request $request): Response
    {
        $this->ensureModuleEnabled();

        $user = $request->user();

        $query = DepartmentPost::query()
            ->where('status', DepartmentPost::STATUS_DONE)
            ->with(['author:id,name', 'department:id,name', 'assignees:id,name'])
            ->withCount('comments')
            ->orderByDesc('updated_at');

        if (! $user->hasRole('administrator')) {
            $userDeptIds = $user->departmentIds();
            if ($userDeptIds === []) {
                $query->whereRaw('1=0');
            } else {
                $query->whereIn('department_id', $userDeptIds);
            }
        }

        $posts = $query->get()->map(fn (DepartmentPost $post) => $this->transformPost($post, true));

        return Inertia::render('Organization/Archive', [
            'departments' => $this->departmentsPayload($user),
            'posts' => $posts,
            'total_archived' => $posts->count(),
        ]);
    }

    public function showPost(Request $request, DepartmentPost $post): Response
    {
        $this->ensureModuleEnabled();
        $department = $post->department()->firstOrFail();
        $this->authorizeDepartmentAccess($request->user(), $department);

        $post->load(['author:id,name', 'attachments', 'assignees:id,name']);
        $post->loadCount('comments');

        $comments = $post->comments()
            ->with('author:id,name')
            ->orderBy('created_at')
            ->get()
            ->map(fn (DepartmentPostComment $c) => $this->transformComment($c));

        return Inertia::render('Organization/Post', [
            'departments' => $this->departmentsPayload($request->user()),
            'department' => $this->transformDepartment($department),
            'post' => $this->transformPost($post),
            'comments' => $comments,
            'members' => $this->departmentMembersPayload($department),
        ]);
    }

    public function storePost(Request $request, Department $department): JsonResponse
    {
        $this->ensureModuleEnabled();
        $this->authorizeDepartmentAccess($request->user(), $department);

        $data = $this->validatePostPayload($request);

        $post = DepartmentPost::create([
            'department_id' => $department->id,
            'author_id' => $request->user()->id,
            'title' => $data['title'],
            'body' => $data['body'],
            'status' => $data['status'] ?? DepartmentPost::STATUS_OPEN,
            'due_at' => $data['due_at'],
        ]);

        if (! empty($data['assignee_ids'])) {
            $post->assignees()->sync($this->filterDepartmentUserIds($department, $data['assignee_ids']));
        }

        $post->load(['author:id,name', 'assignees:id,name']);
        $post->loadCount('comments');

        return response()->json([
            'success' => true,
            'post' => $this->transformPost($post),
        ]);
    }

    public function updatePost(Request $request, DepartmentPost $post): JsonResponse
    {
        $this->ensureModuleEnabled();
        $department = $post->department()->firstOrFail();
        $this->authorizeDepartmentAccess($request->user(), $department);
        $this->authorizeAuthorOrAdmin($request->user(), $post->author_id);

        $data = $this->validatePostPayload($request);

        $post->update([
            'title' => $data['title'],
            'body' => $data['body'],
            'status' => $data['status'] ?? $post->status,
            'due_at' => $data['due_at'],
        ]);

        // sync принимает пустой массив — это снимает всех ответственных
        $safeIds = isset($data['assignee_ids'])
            ? $this->filterDepartmentUserIds($department, $data['assignee_ids'])
            : null;

        if ($safeIds !== null) {
            $post->assignees()->sync($safeIds);
        }

        $post->load(['author:id,name', 'assignees:id,name']);
        $post->loadCount('comments');

        return response()->json([
            'success' => true,
            'post' => $this->transformPost($post),
        ]);
    }

    public function destroyPost(Request $request, DepartmentPost $post): JsonResponse
    {
        $this->ensureModuleEnabled();
        $department = $post->department()->firstOrFail();
        $this->authorizeDepartmentAccess($request->user(), $department);
        $this->authorizeAuthorOrAdmin($request->user(), $post->author_id);

        $post->delete();

        return response()->json(['success' => true]);
    }

    public function storeComment(Request $request, DepartmentPost $post): JsonResponse
    {
        $this->ensureModuleEnabled();
        $department = $post->department()->firstOrFail();
        $this->authorizeDepartmentAccess($request->user(), $department);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $comment = DepartmentPostComment::create([
            'department_post_id' => $post->id,
            'author_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        $comment->load('author:id,name');

        return response()->json([
            'success' => true,
            'comment' => $this->transformComment($comment),
        ]);
    }

    public function storeAttachment(Request $request, DepartmentPost $post): JsonResponse
    {
        $this->ensureModuleEnabled();
        $department = $post->department()->firstOrFail();
        $this->authorizeDepartmentAccess($request->user(), $department);

        $request->validate([
            'file' => ['required', 'file', 'max:20480'], // 20 MB max
        ]);

        $file = $request->file('file');
        $path = $file->store("post-attachments/{$post->id}", 'public');

        $attachment = DepartmentPostAttachment::create([
            'department_post_id' => $post->id,
            'uploaded_by' => $request->user()->id,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);

        return response()->json([
            'success' => true,
            'attachment' => $this->transformAttachment($attachment),
        ]);
    }

    public function destroyAttachment(Request $request, DepartmentPost $post, DepartmentPostAttachment $attachment): JsonResponse
    {
        $this->ensureModuleEnabled();

        if ($attachment->department_post_id !== $post->id) {
            abort(404);
        }

        $department = $post->department()->firstOrFail();
        $this->authorizeDepartmentAccess($request->user(), $department);
        $this->authorizeAuthorOrAdmin($request->user(), $attachment->uploaded_by);

        Storage::disk('public')->delete($attachment->path);
        $attachment->delete();

        return response()->json(['success' => true]);
    }

    public function destroyComment(Request $request, DepartmentPost $post, DepartmentPostComment $comment): JsonResponse
    {
        $this->ensureModuleEnabled();

        if ($comment->department_post_id !== $post->id) {
            abort(404);
        }

        $department = $post->department()->firstOrFail();
        $this->authorizeDepartmentAccess($request->user(), $department);
        $this->authorizeAuthorOrAdmin($request->user(), $comment->author_id);

        $comment->delete();

        return response()->json(['success' => true]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function departmentsPayload(User $user): Collection
    {
        $query = Department::query()
            ->where('is_active', true)
            ->withCount(['posts as open_count' => fn ($q) => $q->where('status', DepartmentPost::STATUS_OPEN)])
            ->withCount(['posts as in_progress_count' => fn ($q) => $q->where('status', DepartmentPost::STATUS_IN_PROGRESS)])
            ->withCount(['posts as done_count' => fn ($q) => $q->where('status', DepartmentPost::STATUS_DONE)])
            ->orderBy('parent_id')
            ->orderBy('name');

        if (! $user->hasRole('administrator')) {
            $userDeptIds = $user->departmentIds();
            if ($userDeptIds === []) {
                return collect();
            }
            $query->whereIn('id', $userDeptIds);
        }

        return $query->get(['id', 'name', 'description', 'parent_id'])
            ->map(fn (Department $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'description' => $d->description,
                'parent_id' => $d->parent_id,
                'open_count' => (int) ($d->open_count ?? 0),
                'in_progress_count' => (int) ($d->in_progress_count ?? 0),
                'done_count' => (int) ($d->done_count ?? 0),
                // Итого активных — для совместимости со старым полем сайдбара
                'posts_count' => (int) ($d->open_count ?? 0) + (int) ($d->in_progress_count ?? 0),
                'archived_posts_count' => (int) ($d->done_count ?? 0),
            ])
            ->values();
    }

    /**
     * Члены отдела для пикера ответственных.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function departmentMembersPayload(Department $department): array
    {
        return $department->users()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['users.id', 'users.name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values()
            ->all();
    }

    /**
     * Оставляет только id пользователей, которые являются членами отдела.
     * Защита от назначения посторонних людей ответственными.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function filterDepartmentUserIds(Department $department, array $ids): array
    {
        $memberIds = $department->users()->pluck('users.id')->map(fn ($v) => (int) $v)->all();

        return array_values(array_filter(
            array_map('intval', $ids),
            fn (int $id) => in_array($id, $memberIds, true),
        ));
    }

    private function ensureModuleEnabled(): void
    {
        abort_unless(
            SystemSetting::getValue('module_tasks', 'on') === 'on',
            403,
            'Модуль «Задачи» отключён администратором.',
        );
    }

    private function authorizeDepartmentAccess(User $user, Department $department): void
    {
        if ($user->hasRole('administrator')) {
            return;
        }

        if (! $user->inDepartment((int) $department->id)) {
            abort(403, 'Нет доступа к отделу.');
        }
    }

    private function authorizeAuthorOrAdmin(User $user, ?int $authorId): void
    {
        if ($user->hasRole('administrator')) {
            return;
        }

        if ($authorId !== null && $authorId === $user->id) {
            return;
        }

        abort(403, 'Можно изменять только свои записи.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePostPayload(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:65535'],
            'status' => ['nullable', Rule::in(DepartmentPost::STATUSES)],
            'due_at' => ['nullable', 'date'],
            'assignee_ids' => ['sometimes', 'nullable', 'array'],
            'assignee_ids.*' => ['integer'],
        ]);

        $data['body'] = $data['body'] ?? null;
        if (is_string($data['body'])) {
            $data['body'] = OrganizationRichTextSanitizer::sanitize($data['body']);
        }
        $data['due_at'] = isset($data['due_at']) ? Carbon::parse($data['due_at']) : null;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function transformDepartment(Department $department): array
    {
        return [
            'id' => $department->id,
            'name' => $department->name,
            'description' => $department->description,
            'parent_id' => $department->parent_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformPost(DepartmentPost $post, bool $withDepartment = false): array
    {
        $attachments = $post->relationLoaded('attachments')
            ? $post->attachments->map(fn (DepartmentPostAttachment $a) => $this->transformAttachment($a))->values()->all()
            : [];

        $assignees = $post->relationLoaded('assignees')
            ? $post->assignees->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()->all()
            : [];

        $data = [
            'id' => $post->id,
            'department_id' => $post->department_id,
            'title' => $post->title,
            'body' => OrganizationRichTextSanitizer::sanitize($post->body),
            'status' => $post->status,
            'due_at' => $post->due_at?->toIso8601String(),
            'author' => $post->author ? [
                'id' => $post->author->id,
                'name' => $post->author->name,
            ] : null,
            'assignees' => $assignees,
            'comments_count' => (int) ($post->comments_count ?? 0),
            'created_at' => $post->created_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
            'attachments' => $attachments,
        ];

        if ($withDepartment && $post->relationLoaded('department') && $post->department) {
            $data['department_name'] = $post->department->name;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function transformAttachment(DepartmentPostAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'original_name' => $attachment->original_name,
            'url' => $attachment->url(),
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->size,
            'is_image' => $attachment->isImage(),
            'uploaded_by' => $attachment->uploaded_by,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformComment(DepartmentPostComment $comment): array
    {
        return [
            'id' => $comment->id,
            'post_id' => $comment->department_post_id,
            'body' => $comment->body,
            'author' => $comment->author ? [
                'id' => $comment->author->id,
                'name' => $comment->author->name,
            ] : null,
            'created_at' => $comment->created_at?->toIso8601String(),
        ];
    }
}
