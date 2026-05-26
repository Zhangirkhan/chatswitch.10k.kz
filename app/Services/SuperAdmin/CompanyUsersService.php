<?php

declare(strict_types=1);

namespace App\Services\SuperAdmin;

use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use App\Models\WhatsappSession;
final class CompanyUsersService
{
    /**
     * @return array{
     *     users: list<array<string, mixed>>,
     *     departments: list<array<string, mixed>>,
     *     whatsapp_sessions: list<array<string, mixed>>
     * }
     */
    public function payloadForCompany(Company $company): array
    {
        $users = User::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $company->id)
            ->where('is_super_admin', false)
            ->with([
                'roles',
                'department:id,name',
                'departments:id,name',
                'whatsappSessions:id,session_name,display_name,status,company_id',
            ])
            ->orderBy('name')
            ->get();

        $departments = Department::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $company->id)
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id', 'is_active']);

        $whatsappSessions = WhatsappSession::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $company->id)
            ->orderBy('display_name')
            ->get(['id', 'session_name', 'display_name', 'status']);

        return [
            'users' => $users->map(fn (User $user): array => $this->transformUser($user))->all(),
            'departments' => $departments->map(fn (Department $d): array => [
                'id' => $d->id,
                'name' => $d->name,
                'parent_id' => $d->parent_id,
                'is_active' => (bool) $d->is_active,
            ])->all(),
            'whatsapp_sessions' => $whatsappSessions->map(fn (WhatsappSession $s): array => [
                'id' => $s->id,
                'session_name' => $s->session_name,
                'display_name' => $s->display_name,
                'status' => $s->status,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function transformUser(User $user): array
    {
        $phones = $user->phones;
        if (! is_array($phones) || $phones === []) {
            $phones = $user->phone ? [$user->phone] : [];
        }

        $roles = $user->relationLoaded('roles')
            ? $user->roles->pluck('name')->values()->all()
            : $user->getRoleNames()->values()->all();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'phones' => array_values($phones),
            'is_active' => (bool) $user->is_active,
            'is_owner' => false,
            'department_id' => $user->department_id,
            'department' => $user->department !== null ? [
                'id' => $user->department->id,
                'name' => $user->department->name,
            ] : null,
            'departments' => $user->relationLoaded('departments')
                ? $user->departments->map(fn (Department $d): array => [
                    'id' => $d->id,
                    'name' => $d->name,
                ])->values()->all()
                : [],
            'roles' => array_map(fn (string $name): array => ['name' => $name], $roles),
            'whatsapp_sessions' => $user->whatsappSessions->map(fn (WhatsappSession $s): array => [
                'id' => $s->id,
                'session_name' => $s->session_name,
                'display_name' => $s->display_name,
                'status' => $s->status,
            ])->values()->all(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $users
     * @return list<array<string, mixed>>
     */
    public function markOwner(array $users, ?int $ownerUserId): array
    {
        return array_map(function (array $user) use ($ownerUserId): array {
            $user['is_owner'] = $ownerUserId !== null && (int) $user['id'] === $ownerUserId;

            return $user;
        }, $users);
    }
}
