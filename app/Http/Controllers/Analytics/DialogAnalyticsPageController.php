<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DialogAnalyticsPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user && $user->hasAnyRole(['administrator', 'manager', 'employee']), 403);

        $departments = $this->departmentsForUser($user);
        $employees = $this->employeesForUser($user);

        $sla = SystemSetting::getValue('analytics.sla_first_response_seconds');
        $slaSeconds = is_numeric($sla) ? (int) $sla : 300;

        $defaultTo = now()->endOfDay();
        $defaultFrom = now()->subDays(7)->startOfDay();

        return Inertia::render('Analytics/Dialogs', [
            'filterOptions' => [
                'departments' => $departments,
                'employees' => $employees,
                'sla_seconds' => $slaSeconds,
                'default_from' => $defaultFrom->toIso8601String(),
                'default_to' => $defaultTo->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function departmentsForUser(User $user): array
    {
        $q = Department::query()->orderBy('name')->where('is_active', true);

        if ($user->hasRole('manager')) {
            $managerDeptIds = $user->departmentIds();
            if ($managerDeptIds === []) {
                return [];
            }
            $q->whereIn('id', $managerDeptIds);
        }

        return $q->get(['id', 'name'])->map(static fn (Department $d) => [
            'id' => $d->id,
            'name' => $d->name,
        ])->all();
    }

    /**
     * @return list<array{id: int, name: string, department_id: int|null}>
     */
    private function employeesForUser(User $user): array
    {
        $q = User::query()
            ->where('is_active', true)
            ->orderBy('name');

        if ($user->hasRole('manager')) {
            $managerDeptIds = $user->departmentIds();
            if ($managerDeptIds === []) {
                return [];
            }
            $q->whereHas('departments', static fn ($qq) => $qq->whereIn('departments.id', $managerDeptIds));
        }

        if ($user->hasRole('employee')) {
            $q->where('id', $user->id);
        }

        return $q->get(['id', 'name', 'department_id'])->map(static fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'department_id' => $u->department_id,
        ])->all();
    }
}
