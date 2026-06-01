<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Chat;
use App\Models\Department;
use App\Models\DepartmentPost;
use App\Support\ChatUrl;
use App\Support\SlaReminderSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

final class SlaReminderService
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly SlaReminderSettings $slaSettings,
    ) {}

    public function countEligible(?int $minutes = null, ?int $companyId = null): int
    {
        if ($companyId === null || ! $this->slaSettings->enabled($companyId)) {
            return 0;
        }

        return $this->eligibleQuery($this->resolveMinutes($minutes, $companyId), $companyId)->count();
    }

    public function sendReminders(?int $minutes = null, ?int $companyId = null): int
    {
        if ($companyId === null || ! $this->slaSettings->enabled($companyId)) {
            return 0;
        }

        $minutes = $this->resolveMinutes($minutes, $companyId);
        $created = 0;

        $this->eligibleQuery($minutes, $companyId)
            ->with(['contact:id,name,push_name,phone_number', 'assignments'])
            ->orderBy('last_message_at')
            ->chunkById(100, function ($chats) use (&$created, $minutes): void {
                foreach ($chats as $chat) {
                    if (! $chat instanceof Chat || $this->hasReminderForCurrentWait($chat)) {
                        continue;
                    }

                    $this->createReminder($chat, $minutes);
                    $created++;
                }
            });

        if ($created > 0) {
            Log::info('SLA reminders created', [
                'count' => $created,
                'minutes' => $minutes,
                'company_id' => $companyId,
            ]);
        }

        return $created;
    }

    private function resolveMinutes(?int $minutes, int $companyId): int
    {
        return $minutes ?? $this->slaSettings->waitMinutes($companyId);
    }

    /**
     * @return Builder<Chat>
     */
    private function eligibleQuery(int $minutes, int $companyId): Builder
    {
        $threshold = now()->subMinutes(max(1, $minutes));

        return Chat::query()
            ->where('company_id', $companyId)
            ->where('is_archived', false)
            ->where('is_group', false)
            ->where('last_message_direction', 'inbound')
            ->whereNotNull('last_message_at')
            ->where('last_message_at', '<=', $threshold)
            ->whereDoesntHave('messages', function (Builder $query): void {
                $query
                    ->where('direction', 'system')
                    ->where('body', 'like', 'SLA: клиент ждёт ответа%')
                    ->whereColumn('message_timestamp', '>=', 'chats.last_message_at');
            });
    }

    private function hasReminderForCurrentWait(Chat $chat): bool
    {
        if ($chat->last_message_at === null) {
            return true;
        }

        return $chat->messages()
            ->where('direction', 'system')
            ->where('body', 'like', 'SLA: клиент ждёт ответа%')
            ->where('message_timestamp', '>=', $chat->last_message_at)
            ->exists();
    }

    private function createReminder(Chat $chat, int $minutes): void
    {
        $client = $chat->chat_name
            ?: $chat->contact?->name
            ?: $chat->contact?->push_name
            ?: $chat->contact?->phone_number
            ?: 'клиент';
        $waitMinutes = $chat->last_message_at !== null
            ? max($minutes, (int) $chat->last_message_at->diffInMinutes(now()))
            : $minutes;

        $body = "SLA: клиент ждёт ответа {$waitMinutes} мин. Чат: {$client}. Нужно ответить или передать ответственному.";
        $this->chatService->logSystemMessage($chat, $body);

        $department = $this->departmentFor($chat);
        if (! $department instanceof Department) {
            return;
        }

        $post = DepartmentPost::query()->create([
            'department_id' => $department->id,
            'author_id' => $chat->assignments->first()?->user_id,
            'title' => 'SLA: клиент ждёт ответа',
            'body' => "{$body}\n\n".ChatUrl::show($chat),
            'status' => DepartmentPost::STATUS_OPEN,
            'due_at' => now()->addMinutes(10),
        ]);

        $assigneeIds = $chat->assignments->pluck('user_id')->filter()->values()->all();
        if ($assigneeIds !== []) {
            $post->assignees()->sync($assigneeIds);
        }
    }

    private function departmentFor(Chat $chat): ?Department
    {
        $department = $chat->departments()->where('is_active', true)->orderBy('departments.id')->first();
        if ($department instanceof Department) {
            return $department;
        }

        return Department::query()->where('is_active', true)->orderBy('id')->first();
    }
}
