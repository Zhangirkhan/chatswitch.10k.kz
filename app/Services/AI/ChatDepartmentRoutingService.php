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
     * По текущему сообщению определяет подходящий отдел, закрепляет его за чатом
     * и возвращает модель для дальнейших проверок (график, автоответ).
     */
    public function resolveAndAssignDepartment(Chat $chat, Message $triggerMessage): ?Department
    {
        if ($chat->is_group) {
            return $this->primaryDepartment($chat);
        }

        if (! (bool) config('funnel.department_routing.enabled', true)) {
            return $this->primaryDepartment($chat);
        }

        $chat->loadMissing('departments');
        $classification = $this->classifier->classify($chat, $triggerMessage);

        if ($classification === null) {
            return $this->primaryDepartment($chat);
        }

        $department = Department::query()
            ->whereKey($classification->departmentId)
            ->where('is_active', true)
            ->first();

        if (! $department instanceof Department) {
            return $this->primaryDepartment($chat);
        }

        $targetId = (int) $department->id;
        $currentIds = $chat->departments
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        if ($currentIds !== [$targetId]) {
            DB::transaction(function () use ($chat, $department): void {
                $chat->departments()->sync([(int) $department->id]);
                $this->assignInitialFunnel($chat, $department);
            });

            $chat->load('departments');

            $body = $currentIds === []
                ? 'AI назначил отдел «'.$department->name.'». '.$classification->reason
                : 'AI определил для обращения отдел «'.$department->name.'». '.$classification->reason;

            $this->chatService->logSystemMessage($chat, $body);

            Log::info('[department-routing] assigned', [
                'chat_id' => $chat->id,
                'department_id' => $department->id,
                'confidence' => $classification->confidence,
                'replaced' => $currentIds !== [],
            ]);
        } elseif ($chat->funnel_id === null) {
            $this->assignInitialFunnel($chat, $department);
        }

        return $department;
    }

    /**
     * @deprecated Используйте {@see resolveAndAssignDepartment()}.
     */
    public function routeIfNeeded(Chat $chat, Message $triggerMessage): bool
    {
        $before = $chat->departments()->pluck('departments.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $department = $this->resolveAndAssignDepartment($chat, $triggerMessage);
        if ($department === null) {
            return false;
        }

        $after = $chat->departments()->pluck('departments.id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        return $before !== $after;
    }

    private function primaryDepartment(Chat $chat): ?Department
    {
        $chat->loadMissing('departments');

        return $chat->departments
            ->where('is_active', true)
            ->sortBy('id')
            ->first();
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
