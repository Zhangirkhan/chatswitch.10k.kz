<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\FollowUpOutcome;
use App\Models\Message;
use App\Models\SalesMilestone;
use App\Models\ScheduledMessage;
use Illuminate\Support\Facades\Schema;

final class FollowUpOutcomeService
{
    public function recordSent(ScheduledMessage $scheduled): void
    {
        if (! Schema::hasTable('follow_up_outcomes')) {
            return;
        }

        if (! in_array($scheduled->purpose, [
            ScheduledMessage::PURPOSE_FUNNEL_FOLLOW_UP,
            ScheduledMessage::PURPOSE_NURTURE_FOLLOW_UP,
        ], true)) {
            return;
        }

        FollowUpOutcome::query()->firstOrCreate(
            ['scheduled_message_id' => (int) $scheduled->id],
            ['chat_id' => (int) $scheduled->chat_id],
        );
    }

    public function recordInboundResponse(Chat $chat, Message $message): void
    {
        if (! Schema::hasTable('follow_up_outcomes')) {
            return;
        }

        $messageAt = $message->message_timestamp ?? $message->created_at;
        if ($messageAt === null) {
            return;
        }

        $pending = FollowUpOutcome::query()
            ->where('chat_id', $chat->id)
            ->whereNull('responded_at')
            ->whereHas('scheduledMessage', static fn ($q) => $q
                ->where('status', ScheduledMessage::STATUS_SENT))
            ->orderByDesc('id')
            ->get();

        foreach ($pending as $outcome) {
            $scheduled = $outcome->scheduledMessage;
            if ($scheduled === null) {
                continue;
            }

            $sentAt = $scheduled->updated_at ?? $scheduled->scheduled_at;
            if ($sentAt === null || $messageAt->lte($sentAt)) {
                continue;
            }

            $state = $chat->sales_state;
            $qualified = is_array($state) && ($state['qualified'] ?? false) === true;

            $outcome->forceFill([
                'responded_at' => $messageAt,
                'recovered_to_qualified' => $qualified,
            ])->save();

            if ($qualified && Schema::hasTable('sales_milestones')) {
                app(SalesMilestoneRecorder::class)->record(
                    $chat,
                    SalesMilestone::MILESTONE_RE_ENGAGED,
                    SalesMilestone::SOURCE_SYSTEM,
                    (int) $message->id,
                );
            }

            break;
        }
    }
}
