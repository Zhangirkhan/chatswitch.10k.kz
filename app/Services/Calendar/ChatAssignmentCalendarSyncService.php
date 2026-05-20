<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\CalendarEvent;
use App\Models\Chat;
use App\Models\ChatAssignment;
use Illuminate\Support\Collection;

/**
 * Синхронизирует назначение мастера в чате с ответственным в календаре (замер, маникюр и т.д.).
 */
final class ChatAssignmentCalendarSyncService
{
    public function ensureChatAssignment(Chat $chat, int $assigneeUserId, ?int $assignedByUserId = null): void
    {
        ChatAssignment::query()->firstOrCreate(
            ['chat_id' => $chat->id, 'user_id' => $assigneeUserId],
            ['assigned_by' => $assignedByUserId],
        );

        $this->syncUpcomingEventsForChat($chat, $assigneeUserId);
    }

    /**
     * @param  list<int|string>  $oldIds
     * @param  list<int|string>  $newIds
     */
    public function syncFromAssignmentChange(Chat $chat, array $oldIds, array $newIds): void
    {
        $old = array_map(intval(...), $oldIds);
        $new = array_map(intval(...), $newIds);
        $added = array_values(array_diff($new, $old));

        $assigneeId = $this->resolveCalendarAssigneeId($new, $added);
        if ($assigneeId === null) {
            return;
        }

        $this->syncUpcomingEventsForChat($chat, $assigneeId);
    }

    public function syncUpcomingEventsForChat(Chat $chat, int $assigneeUserId): int
    {
        $events = CalendarEvent::query()
            ->where('chat_id', $chat->id)
            ->where('starts_at', '>=', now()->subDay())
            ->get();

        $updated = 0;
        foreach ($events as $event) {
            if ((int) $event->assignee_user_id === $assigneeUserId) {
                continue;
            }

            $metadata = is_array($event->metadata) ? $event->metadata : [];
            $aiMeta = is_array($metadata['ai'] ?? null) ? $metadata['ai'] : [];
            if ($aiMeta !== []) {
                $aiMeta['assignee_user_id'] = $assigneeUserId;
                $metadata['ai'] = $aiMeta;
            }

            $event->forceFill([
                'assignee_user_id' => $assigneeUserId,
                'metadata' => $metadata,
            ])->save();
            $updated++;
        }

        return $updated;
    }

    /**
     * @param  list<int>  $currentAssigneeIds
     * @param  list<int>  $addedAssigneeIds
     */
    private function resolveCalendarAssigneeId(array $currentAssigneeIds, array $addedAssigneeIds): ?int
    {
        if ($currentAssigneeIds === []) {
            return null;
        }

        if ($addedAssigneeIds !== []) {
            return (int) end($addedAssigneeIds);
        }

        if (count($currentAssigneeIds) === 1) {
            return (int) $currentAssigneeIds[0];
        }

        return null;
    }

    public function primaryAssigneeFromChat(Chat $chat): ?int
    {
        $chat->loadMissing('assignments');

        /** @var Collection<int, ChatAssignment> $assignments */
        $assignments = $chat->assignments;
        if ($assignments->isEmpty()) {
            return null;
        }

        if ($assignments->count() === 1) {
            return (int) $assignments->first()->user_id;
        }

        return (int) $assignments->sortByDesc('created_at')->first()->user_id;
    }
}
