<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DepartmentController extends Controller
{
    public function index(): Response
    {
        $departments = Department::withCount('users')->orderBy('name')->get();

        return Inertia::render('Settings/Departments', [
            'departments' => $departments,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $department = Department::create($validated);

        return response()->json(['success' => true, 'department' => $department]);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        $department->update($validated);

        return response()->json(['success' => true, 'department' => $department->fresh()]);
    }

    public function destroy(Department $department): JsonResponse
    {
        $department->delete();

        return response()->json(['success' => true]);
    }
}
