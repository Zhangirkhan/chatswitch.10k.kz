<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Settings\SyncDepartmentMembersRequest;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class DepartmentController extends Controller
{
    public function index(): Response
    {
        $departments = Department::query()
            ->with([
                'users' => static fn ($q) => $q
                    ->select('users.id', 'users.name', 'users.email', 'users.department_id')
                    ->orderBy('name'),
            ])
            ->withCount('users')
            ->orderBy('name')
            ->get();

        $users = User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'department_id', 'is_active']);

        return Inertia::render('Settings/Departments', [
            'departments' => $departments,
            'users' => $users,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'sometimes|boolean',
        ]);

        $department = Department::create($validated);

        return response()->json(['success' => true, 'department' => $department]);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'sometimes|boolean',
        ]);

        $department->update($validated);

        return response()->json(['success' => true, 'department' => $department->fresh()]);
    }

    /**
     * У каждого пользователя один отдел (users.department_id): список user_ids — полный состав отдела.
     */
    public function syncMembers(SyncDepartmentMembersRequest $request, Department $department): JsonResponse
    {
        $ids = $request->userIds();

        DB::transaction(static function () use ($department, $ids): void {
            User::query()
                ->where('department_id', $department->id)
                ->when(count($ids) > 0, static fn ($q) => $q->whereNotIn('id', $ids))
                ->update(['department_id' => null]);

            if (count($ids) > 0) {
                User::query()->whereIn('id', $ids)->update(['department_id' => $department->id]);
            }
        });

        $department->load([
            'users' => static fn ($q) => $q
                ->select('users.id', 'users.name', 'users.email', 'users.department_id')
                ->orderBy('name'),
        ]);
        $department->loadCount('users');

        $users = User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'department_id', 'is_active']);

        return response()->json([
            'success' => true,
            'department' => $department,
            'users' => $users,
        ]);
    }

    public function destroy(Department $department): JsonResponse
    {
        $department->delete();

        return response()->json(['success' => true]);
    }
}
