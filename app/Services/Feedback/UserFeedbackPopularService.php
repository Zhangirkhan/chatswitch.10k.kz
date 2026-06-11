<?php

declare(strict_types=1);

namespace App\Services\Feedback;

use App\Enums\UserFeedbackStatus;
use App\Enums\UserFeedbackType;
use App\Models\User;
use App\Models\UserFeedback;
use App\Models\UserFeedbackLike;
use App\Support\FeedbackDiagnosticDetector;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class UserFeedbackPopularService
{
    /**
     * @param  array{
     *     type?: string|null,
     *     limit?: int,
     *     sort?: string|null
     * }  $filters
     * @return Collection<int, UserFeedback>
     */
    public function popularForUser(User $user, array $filters = []): Collection
    {
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 50)));
        $type = trim((string) ($filters['type'] ?? ''));

        $query = UserFeedback::query()
            ->select('user_feedback.*')
            ->where('is_diagnostic', false)
            ->withExists([
                'likes as liked_by_me' => static fn ($q) => $q->where('user_id', $user->id),
            ]);

        if ($type !== '' && in_array($type, ['complaint', 'suggestion'], true)) {
            $query->where('type', $type);
        }

        $sort = trim((string) ($filters['sort'] ?? 'likes_desc'));
        if ($sort === 'likes_desc') {
            $query->orderByDesc('likes_count')->orderByDesc('created_at');
        } else {
            $query->orderByDesc('created_at');
        }

        return $query->limit($limit)->get();
    }

    /**
     * @return array{likes_count: int, liked_by_me: bool}
     */
    public function like(User $user, UserFeedback $feedback): array
    {
        $this->assertLikeable($feedback);

        return DB::transaction(function () use ($user, $feedback): array {
            $existing = UserFeedbackLike::query()
                ->where('user_feedback_id', $feedback->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing !== null) {
                $feedback->refresh();

                return [
                    'likes_count' => (int) $feedback->likes_count,
                    'liked_by_me' => true,
                ];
            }

            UserFeedbackLike::query()->create([
                'user_feedback_id' => $feedback->id,
                'user_id' => $user->id,
                'created_at' => now(),
            ]);

            $feedback->increment('likes_count');
            $feedback->refresh();

            return [
                'likes_count' => (int) $feedback->likes_count,
                'liked_by_me' => true,
            ];
        });
    }

    /**
     * @return array{likes_count: int, liked_by_me: bool}
     */
    public function unlike(User $user, UserFeedback $feedback): array
    {
        $this->assertLikeable($feedback);

        return DB::transaction(function () use ($user, $feedback): array {
            $deleted = UserFeedbackLike::query()
                ->where('user_feedback_id', $feedback->id)
                ->where('user_id', $user->id)
                ->delete();

            if ($deleted === 0) {
                throw new NotFoundHttpException('Лайк не найден.');
            }

            if ($feedback->likes_count > 0) {
                $feedback->decrement('likes_count');
            }

            $feedback->refresh();

            return [
                'likes_count' => (int) $feedback->likes_count,
                'liked_by_me' => false,
            ];
        });
    }

    /**
     * @param  array{
     *     status?: string|null,
     *     type?: string|null,
     *     period?: string|null
     * }  $filters
     */
    public function paginateRankingForAdmin(array $filters, int $perPage = 30): LengthAwarePaginator
    {
        $query = UserFeedback::query()
            ->with([
                'company:id,name,slug',
                'user:id,name,email',
            ])
            ->where('is_diagnostic', false)
            ->orderByDesc('likes_count')
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        $period = trim((string) ($filters['period'] ?? ''));
        if ($period === '7d') {
            $query->where('created_at', '>=', now()->subDays(7));
        } elseif ($period === '30d') {
            $query->where('created_at', '>=', now()->subDays(30));
        }

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * @return Collection<int, UserFeedback>
     */
    public function topForDashboard(int $limit = 10): Collection
    {
        return UserFeedback::query()
            ->where('is_diagnostic', false)
            ->where('created_at', '>=', now()->subDays(30))
            ->orderByDesc('likes_count')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'type', 'message', 'likes_count', 'created_at']);
    }

    private function assertLikeable(UserFeedback $feedback): void
    {
        if ($feedback->is_diagnostic || FeedbackDiagnosticDetector::isDiagnostic((string) $feedback->message)) {
            throw ValidationException::withMessages([
                'feedback' => ['Diagnostic-обращения нельзя лайкать.'],
            ]);
        }
    }
}
