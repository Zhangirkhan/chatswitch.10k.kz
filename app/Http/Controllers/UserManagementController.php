<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\Department;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Support\PhoneFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

final class UserManagementController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));
        $role = trim((string) $request->input('role', ''));
        $status = trim((string) $request->input('status', ''));
        $departmentId = $request->integer('department_id') ?: null;

        $query = User::query()
            ->with(['roles', 'department', 'departments', 'whatsappSessions'])
            ->withCount('chatAssignments')
            ->orderBy('name');

        if ($search !== '') {
            $digits = preg_replace('/\D/', '', $search);
            $query->where(function ($q) use ($search, $digits): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhereHas('departments', fn ($dq) => $dq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('roles', fn ($rq) => $rq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('whatsappSessions', fn ($sq) => $sq
                        ->where('session_name', 'like', "%{$search}%")
                        ->orWhere('display_name', 'like', "%{$search}%"));

                if (is_string($digits) && $digits !== '') {
                    $q->orWhere('phone', 'like', "%{$digits}%");
                }
            });
        }

        if (in_array($role, ['administrator', 'manager', 'employee'], true)) {
            $query->role($role);
        }

        if ($departmentId !== null) {
            $query->whereHas('departments', fn ($dq) => $dq->where('departments.id', $departmentId));
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $users = $query
            ->paginate(20, ['*'], 'page', max(1, (int) $request->input('page', 1)))
            ->through(fn (User $user) => $this->transformUser($user));

        $departments = Department::query()->orderBy('name')->get(['id', 'name', 'parent_id', 'is_active']);

        $whatsappSessions = WhatsappSession::orderBy('display_name')
            ->get(['id', 'session_name', 'display_name', 'status']);

        return Inertia::render('Settings/Users', [
            'users' => $this->paginationPayload($users),
            'filters' => [
                'search' => $search,
                'role' => in_array($role, ['administrator', 'manager', 'employee'], true) ? $role : '',
                'department_id' => $departmentId,
                'status' => in_array($status, ['active', 'inactive'], true) ? $status : '',
            ],
            'departments' => $departments,
            'whatsappSessions' => $whatsappSessions,
            'availableRoles' => ['administrator', 'manager', 'employee'],
        ]);
    }

    /**
     * @param  LengthAwarePaginator<mixed>  $paginator
     * @return array<string, mixed>
     */
    private function paginationPayload(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $phonesList = $this->normalizePhonesList($validated['phones'] ?? null, $validated['phone'] ?? null);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $phonesList[0] ?? null,
            'phones' => $phonesList !== [] ? $phonesList : null,
            'password' => $validated['password'],
            'is_active' => true,
        ]);

        $user->assignRole($validated['role']);
        $user->syncDepartments($this->extractDepartmentIds($validated));
        $user->whatsappSessions()->sync($validated['whatsapp_session_ids'] ?? []);
        $user->load(['roles', 'department', 'departments', 'whatsappSessions']);

        return response()->json(['success' => true, 'user' => $this->transformUser($user)]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();
        $phonesList = $this->normalizePhonesList($validated['phones'] ?? null, $validated['phone'] ?? null);

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $phonesList[0] ?? null,
            'phones' => $phonesList !== [] ? $phonesList : null,
            'is_active' => $validated['is_active'] ?? $user->is_active,
        ]);

        if (! empty($validated['password'])) {
            $user->update(['password' => $validated['password']]);
        }

        $user->syncRoles([$validated['role']]);
        $user->syncDepartments($this->extractDepartmentIds($validated));
        $user->whatsappSessions()->sync($validated['whatsapp_session_ids'] ?? []);

        $user->load(['roles', 'department', 'departments', 'whatsappSessions']);

        return response()->json(['success' => true, 'user' => $this->transformUser($user)]);
    }

    /**
     * Унифицированно извлекает список отделов из запроса:
     *  • приоритетно — массив `department_ids[]` (новая форма с мультивыбором);
     *  • fallback — старое единственное `department_id` (legacy).
     *
     * @param  array<string, mixed>  $validated
     * @return array<int, int>
     */
    private function extractDepartmentIds(array $validated): array
    {
        if (isset($validated['department_ids']) && is_array($validated['department_ids'])) {
            return array_values(array_unique(array_map('intval', $validated['department_ids'])));
        }

        if (! empty($validated['department_id'])) {
            return [(int) $validated['department_id']];
        }

        return [];
    }

    public function destroy(User $user): JsonResponse
    {
        $user->delete();

        return response()->json(['success' => true]);
    }

    /** @return array<string, mixed> */
    private function transformUser(User $user): array
    {
        $phones = $user->phones;
        if (! is_array($phones) || $phones === []) {
            $phones = $user->phone ? [$user->phone] : [];
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'phones' => array_values($phones),
            'is_active' => (bool) $user->is_active,
            'department_id' => $user->department_id,
            'department' => $user->department,
            'department_ids' => $user->relationLoaded('departments')
                ? $user->departments->pluck('id')->map(fn ($v) => (int) $v)->all()
                : $user->departmentIds(),
            'departments' => $user->relationLoaded('departments')
                ? $user->departments->map(fn (Department $d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                ])->values()->all()
                : [],
            'roles' => $user->getRoleNames()->values()->all(),
            'whatsapp_sessions' => $user->whatsappSessions->map(fn (WhatsappSession $s) => [
                'id' => $s->id,
                'session_name' => $s->session_name,
                'display_name' => $s->display_name,
                'status' => $s->status,
            ])->values()->all(),
            'whatsapp_session_ids' => $user->whatsappSessions->pluck('id')->values()->all(),
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }

    /**
     * @param  array<int, mixed>|null  $phones
     * @return list<string>
     */
    private function normalizePhonesList(?array $phones, ?string $legacyPhone): array
    {
        $items = collect($phones ?? []);
        if ($legacyPhone !== null && trim($legacyPhone) !== '') {
            $items->push($legacyPhone);
        }

        /** @var list<string> $out */
        $out = $items
            ->map(fn ($p) => PhoneFormatter::normalize(is_string($p) ? $p : null))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $out;
    }
}
