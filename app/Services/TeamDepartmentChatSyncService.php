<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Department;
use App\Models\TeamConversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TeamDepartmentChatSyncService
{
    public function ensureDepartmentConversation(Department $department): TeamConversation
    {
        $companyId = $this->inferCompanyIdForDepartment($department);

        return TeamConversation::query()->firstOrCreate(
            [
                'department_id' => $department->id,
                'type' => TeamConversation::TYPE_DEPARTMENT,
            ],
            [
                'company_id' => $companyId,
            ],
        );
    }

    public function syncAllMembers(Department $department): void
    {
        $conversation = $this->ensureDepartmentConversation($department);
        $userIds = $department->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        DB::transaction(function () use ($conversation, $userIds, $department): void {
            $this->refreshDepartmentConversationCompanyId($conversation, $department);

            $conversation->participants()->sync(
                collect($userIds)->mapWithKeys(fn (int $id) => [
                    $id => [
                        'can_leave' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                ])->all(),
            );
        });
    }

    public function onDepartmentMemberAttached(Department $department, int $userId): void
    {
        $conversation = $this->ensureDepartmentConversation($department);
        $this->refreshDepartmentConversationCompanyId($conversation, $department);

        $conversation->participants()->syncWithoutDetaching([
            $userId => [
                'can_leave' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function onDepartmentMemberDetached(Department $department, int $userId): void
    {
        $conversation = TeamConversation::query()
            ->where('department_id', $department->id)
            ->where('type', TeamConversation::TYPE_DEPARTMENT)
            ->first();

        if ($conversation === null) {
            return;
        }

        $conversation->participants()->detach($userId);
    }

    private function refreshDepartmentConversationCompanyId(TeamConversation $conversation, Department $department): void
    {
        $companyId = $this->inferCompanyIdForDepartment($department);
        if ($companyId !== $conversation->company_id) {
            $conversation->forceFill(['company_id' => $companyId])->save();
        }
    }

    private function inferCompanyIdForDepartment(Department $department): ?int
    {
        $row = User::query()
            ->whereHas('departments', fn ($q) => $q->where('departments.id', $department->id))
            ->whereNotNull('company_id')
            ->selectRaw('company_id, COUNT(*) as c')
            ->groupBy('company_id')
            ->orderByDesc('c')
            ->first();

        if ($row === null) {
            return null;
        }

        return (int) $row->company_id;
    }
}
