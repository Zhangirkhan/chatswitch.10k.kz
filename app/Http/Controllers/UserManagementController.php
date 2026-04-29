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
use Inertia\Inertia;
use Inertia\Response;

final class UserManagementController extends Controller
{
    public function index(): Response
    {
        $users = User::with(['roles', 'department', 'whatsappSessions'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->transformUser($user));

        $departments = Department::query()->orderBy('name')->get(['id', 'name', 'is_active']);

        $whatsappSessions = WhatsappSession::orderBy('display_name')
            ->get(['id', 'session_name', 'display_name', 'status']);

        return Inertia::render('Settings/Users', [
            'users' => $users,
            'departments' => $departments,
            'whatsappSessions' => $whatsappSessions,
            'availableRoles' => ['administrator', 'manager', 'employee'],
        ]);
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
            'department_id' => $validated['department_id'] ?? null,
            'is_active' => true,
        ]);

        $user->assignRole($validated['role']);
        $user->whatsappSessions()->sync($validated['whatsapp_session_ids'] ?? []);
        $user->load(['roles', 'department', 'whatsappSessions']);

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
            'department_id' => $validated['department_id'] ?? null,
            'is_active' => $validated['is_active'] ?? $user->is_active,
        ]);

        if (! empty($validated['password'])) {
            $user->update(['password' => $validated['password']]);
        }

        $user->syncRoles([$validated['role']]);

        $user->whatsappSessions()->sync($validated['whatsapp_session_ids'] ?? []);

        $user->load(['roles', 'department', 'whatsappSessions']);

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
