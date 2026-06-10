<?php

declare(strict_types=1);

namespace App\Services\Organization;

use App\Models\Department;
use App\Models\DepartmentPost;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\OrganizationDepartmentTasks;
use App\Support\OrganizationRichTextSanitizer;
use Illuminate\Database\Eloquent\Collection;

final class DepartmentPostService
{
    public function ensureEnabled(): void
    {
        abort_unless(
            SystemSetting::getValue('module_tasks', 'on') === 'on',
            403,
            'Модуль «Задачи» отключён администратором.',
        );

        abort_unless(
            OrganizationDepartmentTasks::enabled(),
            403,
            'Задачи по отделам временно отключены.',
        );
    }

    public function authorizeDepartmentAccess(User $user, Department $department): void
    {
        if ($user->hasRole('administrator')) {
            return;
        }

        if (! $user->inDepartment((int) $department->id)) {
            abort(403, 'Нет доступа к отделу.');
        }
    }

    public function authorizeAuthorOrAdmin(User $user, ?int $authorId): void
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
     * @return Collection<int, DepartmentPost>
     */
    public function listForDepartment(Department $department, ?string $statusFilter = null): Collection
    {
        $query = $department->posts()
            ->with(['author:id,name', 'assignees:id,name', 'attachments'])
            ->withCount('comments');

        $statusFilter = $statusFilter !== null ? mb_strtolower(trim($statusFilter)) : 'active';

        if ($statusFilter === 'done') {
            $query->where('status', DepartmentPost::STATUS_DONE)
                ->orderByDesc('updated_at');
        } elseif ($statusFilter === 'all') {
            $query->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'open' THEN 1 WHEN 'done' THEN 2 ELSE 3 END")
                ->orderByDesc('created_at');
        } else {
            $query->whereIn('status', [DepartmentPost::STATUS_OPEN, DepartmentPost::STATUS_IN_PROGRESS])
                ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'open' THEN 1 ELSE 2 END")
                ->orderByDesc('created_at');
        }

        return $query->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Department $department, User $author, array $data): DepartmentPost
    {
        $post = DepartmentPost::query()->create([
            'department_id' => $department->id,
            'author_id' => $author->id,
            'title' => $data['title'],
            'body' => $this->sanitizeBody($data['body'] ?? null),
            'status' => $data['status'] ?? DepartmentPost::STATUS_OPEN,
            'due_at' => $data['due_at'] ?? null,
        ]);

        if (! empty($data['assignee_ids'])) {
            $post->assignees()->sync($this->filterDepartmentUserIds($department, $data['assignee_ids']));
        }

        return $this->loadPostRelations($post);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(DepartmentPost $post, Department $department, array $data): DepartmentPost
    {
        $post->update([
            'title' => $data['title'],
            'body' => $this->sanitizeBody($data['body'] ?? null),
            'status' => $data['status'] ?? $post->status,
            'due_at' => $data['due_at'] ?? null,
        ]);

        if (array_key_exists('assignee_ids', $data)) {
            $post->assignees()->sync(
                $this->filterDepartmentUserIds($department, $data['assignee_ids'] ?? []),
            );
        }

        return $this->loadPostRelations($post->fresh() ?? $post);
    }

    public function complete(DepartmentPost $post): DepartmentPost
    {
        if ($post->status !== DepartmentPost::STATUS_DONE) {
            $post->update(['status' => DepartmentPost::STATUS_DONE]);
        }

        return $this->loadPostRelations($post->fresh() ?? $post);
    }

    public function delete(DepartmentPost $post): void
    {
        $post->delete();
    }

    public function loadPostRelations(DepartmentPost $post): DepartmentPost
    {
        $post->load(['author:id,name', 'assignees:id,name', 'attachments']);
        $post->loadCount('comments');

        return $post;
    }

    /**
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

    private function sanitizeBody(mixed $body): ?string
    {
        if (! is_string($body)) {
            return null;
        }

        return OrganizationRichTextSanitizer::sanitize($body);
    }
}
