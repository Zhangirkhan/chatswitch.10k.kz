<?php

declare(strict_types=1);

namespace App\Services\Funnel;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class FunnelBoardBulkMoveService
{
    public function __construct(
        private readonly ChatFunnelStateService $stateService,
    ) {}

    /**
     * @param  list<int>  $chatIds
     * @return array{moved: int, skipped: int, errors: list<string>}
     */
    public function move(User $actor, int $funnelId, int $stageId, array $chatIds, bool $forceLocked = false): array
    {
        $moved = 0;
        $skipped = 0;
        $errors = [];

        foreach ($chatIds as $chatId) {
            $chat = Chat::query()->find($chatId);
            if ($chat === null) {
                $skipped++;

                continue;
            }

            if (! Gate::forUser($actor)->allows('manageFunnel', $chat)) {
                $skipped++;

                continue;
            }

            if ($chat->funnel_stage_locked && ! $forceLocked) {
                $skipped++;

                continue;
            }

            if ($stageId === FunnelBoardService::INBOX_STAGE_ID) {
                $payload = [
                    'funnel_id' => null,
                    'funnel_stage_id' => null,
                ];
            } else {
                $payload = [
                    'funnel_id' => $funnelId,
                    'funnel_stage_id' => $stageId,
                ];
            }

            try {
                $this->stateService->applyManual($chat, $payload, $actor);
                $moved++;
            } catch (\Throwable $e) {
                $errors[] = 'Чат #'.$chatId.': '.$e->getMessage();
                $skipped++;
            }
        }

        return [
            'moved' => $moved,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
}
