<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\User;
use App\Support\TenantCompany;

/**
 * Правила доступа к данным сотрудников и отделов в AI workspace.
 */
final class AiWorkspaceAccessService
{
    public function canViewEmployee(User $viewer, User $target): bool
    {
        if ((int) $viewer->id === (int) $target->id) {
            return true;
        }

        if ((int) $target->company_id !== TenantCompany::id()) {
            return false;
        }

        if ($viewer->hasRole('administrator')) {
            return true;
        }

        if ($viewer->hasRole('manager')) {
            return $this->sharesDepartment($viewer, $target);
        }

        return false;
    }

    public function sharesDepartment(User $a, User $b): bool
    {
        return array_intersect($a->departmentIds(), $b->departmentIds()) !== [];
    }

    /**
     * @return list<User>
     */
    public function resolveEmployeesByName(User $viewer, string $name, int $limit = 8): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }

        $query = User::query()
            ->where('company_id', TenantCompany::id())
            ->where('is_active', true)
            ->where('name', 'like', "%{$name}%")
            ->orderBy('name');

        if ($viewer->hasRole('administrator')) {
            return $query->limit($limit)->get()->all();
        }

        if ($viewer->hasRole('manager')) {
            $deptIds = $viewer->departmentIds();
            if ($deptIds === []) {
                return [];
            }

            return $query
                ->whereHas('departments', static fn ($q) => $q->whereIn('departments.id', $deptIds))
                ->limit($limit)
                ->get()
                ->all();
        }

        /** @var User|null $self */
        $self = $query->where('id', $viewer->id)->first();

        return $self !== null ? [$self] : [];
    }

    /**
     * @return list<array{id: int, name: string, email: string|null}>
     */
    public function listDepartmentColleagues(User $viewer, ?string $departmentName = null, int $limit = 30): array
    {
        $query = User::query()
            ->where('company_id', TenantCompany::id())
            ->where('is_active', true)
            ->orderBy('name');

        if ($viewer->hasRole('administrator')) {
            if ($departmentName !== null && $departmentName !== '') {
                $query->whereHas('departments', static fn ($q) => $q->where('name', 'like', "%{$departmentName}%"));
            }
        } elseif ($viewer->hasRole('manager')) {
            $deptIds = $viewer->departmentIds();
            if ($deptIds === []) {
                return [];
            }
            $query->whereHas('departments', static fn ($q) => $q->whereIn('departments.id', $deptIds));
            if ($departmentName !== null && $departmentName !== '') {
                $query->whereHas('departments', static fn ($q) => $q
                    ->whereIn('departments.id', $deptIds)
                    ->where('name', 'like', "%{$departmentName}%"));
            }
        } else {
            $deptIds = $viewer->departmentIds();
            if ($deptIds === []) {
                return $this->serializeEmployees([$viewer]);
            }
            $query->whereHas('departments', static fn ($q) => $q->whereIn('departments.id', $deptIds));
        }

        return $this->serializeEmployees($query->limit($limit)->get(['id', 'name', 'email'])->all());
    }

    /**
     * @param  list<User>  $users
     * @return list<array{id: int, name: string, email: string|null}>
     */
    private function serializeEmployees(array $users): array
    {
        return array_map(static fn (User $u): array => [
            'id' => (int) $u->id,
            'name' => (string) $u->name,
            'email' => $u->email,
        ], $users);
    }
}
