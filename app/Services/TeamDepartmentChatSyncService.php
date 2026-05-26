<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Department;
use App\Models\TeamConversation;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class TeamDepartmentChatSyncService
{
    public function ensureDepartmentConversation(Department $department): TeamConversation
    {
        $companyId = $this->inferCompanyIdForDepartment($department);

        $conversation = TeamConversation::query()
            ->withoutGlobalScope('tenant')
            ->where('department_id', $department->id)
            ->where('type', TeamConversation::TYPE_DEPARTMENT)
            ->first();

        if ($conversation !== null) {
            $this->refreshDepartmentConversationCompanyId($conversation, $department);

            return $conversation;
        }

        try {
            return TeamConversation::query()->withoutGlobalScope('tenant')->create([
                'department_id' => $department->id,
                'type' => TeamConversation::TYPE_DEPARTMENT,
                'company_id' => $companyId ?? app(TenantContext::class)->companyIdOrNull(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $conversation = TeamConversation::query()
                ->withoutGlobalScope('tenant')
                ->where('department_id', $department->id)
                ->where('type', TeamConversation::TYPE_DEPARTMENT)
                ->firstOrFail();

            $this->refreshDepartmentConversationCompanyId($conversation, $department);

            return $conversation;
        }
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

            $this->syncAdministratorsToDepartment($department, $conversation);
        });
    }

    /**
     * Администратор видит и участвует во всех чатах отделов (single-tenant: один инстанс — все отделы).
     */
    public function syncAdministratorToAllDepartmentChats(User $user): void
    {
        if (! $user->hasRole('administrator')) {
            return;
        }

        $conversations = TeamConversation::query()
            ->where('type', TeamConversation::TYPE_DEPARTMENT)
            ->whereHas('department', static fn ($q) => $q->where('is_active', true))
            ->get(['id']);

        foreach ($conversations as $conversation) {
            $this->attachAdministrator($conversation, $user);
        }
    }

    public function syncAdministratorsToDepartment(Department $department, ?TeamConversation $conversation = null): void
    {
        $conversation ??= $this->ensureDepartmentConversation($department);

        foreach ($this->activeAdministratorIds() as $adminId) {
            $this->attachAdministrator($conversation, $adminId);
        }
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

        $this->syncAdministratorsToDepartment($department, $conversation);
    }

    public function onDepartmentMemberDetached(Department $department, int $userId): void
    {
        $conversation = TeamConversation::query()
            ->withoutGlobalScope('tenant')
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
        $companyId = $this->inferCompanyIdForDepartment($department)
            ?? app(TenantContext::class)->companyIdOrNull();

        if ($companyId !== null && $companyId !== $conversation->company_id) {
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

    /**
     * @return list<int>
     */
    private function activeAdministratorIds(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', static fn ($q) => $q->where('name', 'administrator'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function attachAdministrator(TeamConversation $conversation, User|int $admin): void
    {
        $adminId = $admin instanceof User ? (int) $admin->id : $admin;

        $conversation->participants()->syncWithoutDetaching([
            $adminId => [
                'can_leave' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
