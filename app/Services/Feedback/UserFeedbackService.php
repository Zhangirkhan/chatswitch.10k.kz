<?php

declare(strict_types=1);

namespace App\Services\Feedback;

use App\Enums\UserFeedbackSource;
use App\Enums\UserFeedbackStatus;
use App\Enums\UserFeedbackType;
use App\Models\User;
use App\Models\UserFeedback;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

final class UserFeedbackService
{
    /**
     * @param  array{
     *     type: string,
     *     message: string,
     *     app_version?: string|null,
     *     device_platform?: string|null,
     *     device_model?: string|null
     * }  $data
     */
    public function create(User $user, UserFeedbackSource $source, array $data): UserFeedback
    {
        return UserFeedback::query()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'source' => $source,
            'type' => UserFeedbackType::from($data['type']),
            'message' => $data['message'],
            'app_version' => Arr::get($data, 'app_version'),
            'device_platform' => Arr::get($data, 'device_platform'),
            'device_model' => Arr::get($data, 'device_model'),
            'status' => UserFeedbackStatus::New,
        ]);
    }

    /**
     * @return Collection<int, UserFeedback>
     */
    public function recentForUser(User $user, int $limit = 10): Collection
    {
        return UserFeedback::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array{
     *     status?: string,
     *     type?: string,
     *     source?: string,
     *     company_id?: int|string|null,
     *     search?: string|null
     * }  $filters
     */
    public function paginateForAdmin(array $filters, int $perPage = 30): LengthAwarePaginator
    {
        $query = UserFeedback::query()
            ->with([
                'company:id,name,slug',
                'user:id,name,email',
                'resolvedBy:id,name',
            ])
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }

        if (! empty($filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->whereHas('company', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('user', fn ($q) => $q
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        return $query->paginate($perPage)->withQueryString();
    }

    public function markAsRead(UserFeedback $feedback, User $admin, ?string $adminNote = null): UserFeedback
    {
        if ($feedback->status === UserFeedbackStatus::Resolved) {
            return $feedback;
        }

        $feedback->fill([
            'status' => UserFeedbackStatus::Read,
        ]);

        if ($adminNote !== null && $adminNote !== '') {
            $feedback->admin_note = $adminNote;
        }

        $feedback->save();

        return $feedback->fresh(['company', 'user', 'resolvedBy']);
    }

    public function resolve(UserFeedback $feedback, User $admin, ?string $adminNote = null): UserFeedback
    {
        $feedback->fill([
            'status' => UserFeedbackStatus::Resolved,
            'resolved_by_user_id' => $admin->id,
            'resolved_at' => now(),
        ]);

        if ($adminNote !== null && $adminNote !== '') {
            $feedback->admin_note = $adminNote;
        }

        $feedback->save();

        return $feedback->fresh(['company', 'user', 'resolvedBy']);
    }

    public function countUnread(): int
    {
        return UserFeedback::query()
            ->where('status', UserFeedbackStatus::New)
            ->count();
    }
}
