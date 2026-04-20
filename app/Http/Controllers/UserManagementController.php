<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

final class UserManagementController extends Controller
{
    public function index(): Response
    {
        $users = User::with(['roles', 'department'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->transformUser($user));

        $departments = Department::where('is_active', true)->orderBy('name')->get();

        return Inertia::render('Settings/Users', [
            'users' => $users,
            'departments' => $departments,
            'availableRoles' => ['administrator', 'manager', 'employee'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'password' => 'required|string|min:6',
            'role' => 'required|string|in:administrator,manager,employee',
            'department_id' => 'nullable|exists:departments,id',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'department_id' => $validated['department_id'] ?? null,
            'is_active' => true,
        ]);

        $user->assignRole($validated['role']);
        $user->load(['roles', 'department']);

        return response()->json(['success' => true, 'user' => $this->transformUser($user)]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => "required|email|unique:users,email,{$user->id}",
            'phone' => 'nullable|string|max:20',
            'password' => 'nullable|string|min:6',
            'role' => 'required|string|in:administrator,manager,employee',
            'department_id' => 'nullable|exists:departments,id',
            'is_active' => 'boolean',
        ]);

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'department_id' => $validated['department_id'] ?? null,
            'is_active' => $validated['is_active'] ?? $user->is_active,
        ]);

        if (! empty($validated['password'])) {
            $user->update(['password' => Hash::make($validated['password'])]);
        }

        $user->syncRoles([$validated['role']]);
        $user->load(['roles', 'department']);

        return response()->json(['success' => true, 'user' => $this->transformUser($user)]);
    }

    public function destroy(User $user): JsonResponse
    {
        $user->delete();

        return response()->json(['success' => true]);
    }

    /** @return array<string, mixed> */
    private function transformUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'is_active' => (bool) $user->is_active,
            'department_id' => $user->department_id,
            'department' => $user->department,
            'roles' => $user->getRoleNames()->values()->all(),
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }
}
