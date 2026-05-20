<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\Department;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\Message;
use App\Services\ChatService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ChatDepartmentRoutingService
{
    public function __construct(
        private readonly ChatDepartmentClassifierService $classifier,
        private readonly ChatService $chatService,
    ) {}

    /**
     * Назначает отдел чату, если он ещё не привязан. Возвращает true, если отдел добавлен.
     */
    public function routeIfNeeded(Chat $chat, Message $triggerMessage): bool
    {
        if ($chat->is_group || ! (bool) config('funnel.department_routing.enabled', true)) {
            return false;
        }

        $chat->loadMissing('departments');
        if ($chat->departments->isNotEmpty()) {
            return false;
        }

        $classification = $this->classifier->classify($chat, $triggerMessage);
        if ($classification === null) {
            return false;
        }

        $department = Department::query()
            ->whereKey($classification->departmentId)
            ->where('is_active', true)
            ->first();

        if (! $department instanceof Department) {
            return false;
        }

        DB::transaction(function () use ($chat, $department, $classification): void {
            $chat->departments()->sync([(int) $department->id]);
            $this->assignInitialFunnel($chat, $department);
        });

        $chat->load('departments');
        $this->chatService->logSystemMessage(
            $chat,
            'AI назначил отдел «'.$department->name.'». '.$classification->reason,
        );

        Log::info('[department-routing] assigned', [
            'chat_id' => $chat->id,
            'department_id' => $department->id,
            'confidence' => $classification->confidence,
        ]);

        return true;
    }

    private function assignInitialFunnel(Chat $chat, Department $department): void
    {
        if ($chat->funnel_id !== null) {
            return;
        }

        $funnel = $department->funnels()
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->first();

        if (! $funnel instanceof Funnel) {
            return;
        }

        $stage = $funnel->stages()
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->first();

        $chat->forceFill([
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stage instanceof FunnelStage ? $stage->id : null,
        ])->save();
    }
}
