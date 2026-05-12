<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Settings\SyncDepartmentMembersRequest;
use App\Models\Department;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
                'funnels:id',
                'funnelStages:id,funnel_id',
            ])
            ->withCount('users')
            ->orderBy('parent_id')
            ->orderBy('name')
            ->get()
            ->map(static function (Department $d): array {
                // Аккуратный денормализованный плоский список без pivot-полей —
                // фронт работает только с массивами id, без лишних служебных колонок.
                return [
                    'id' => $d->id,
                    'name' => $d->name,
                    'description' => $d->description,
                    'parent_id' => $d->parent_id,
                    'is_active' => $d->is_active,
                    'users_count' => $d->users_count,
                    'users' => $d->users->map(fn (User $u) => [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                        'department_id' => $u->department_id,
                    ])->values()->all(),
                    'funnel_ids' => $d->funnels->pluck('id')->map(fn ($v) => (int) $v)->values()->all(),
                    'funnel_stage_ids' => $d->funnelStages->pluck('id')->map(fn ($v) => (int) $v)->values()->all(),
                ];
            });

        $users = User::query()
            ->with('departments:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'department_id', 'is_active'])
            ->map(static function (User $u): array {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'department_id' => $u->department_id,
                    'department_ids' => $u->departments->pluck('id')->map(fn ($v) => (int) $v)->all(),
                    'is_active' => $u->is_active,
                ];
            });

        // Воронки со списком этапов — нужны для пикера в модалке отдела.
        $funnels = Funnel::query()
            ->with(['stages'])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return Inertia::render('Settings/Departments', [
            'departments' => $departments,
            'users' => $users,
            'funnels' => $funnels,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, null);
        [$attrs, $funnelIds, $stageIds] = $this->extractFunnelSelection($validated);

        $department = DB::transaction(function () use ($attrs, $funnelIds, $stageIds): Department {
            $dept = Department::create($attrs);
            $this->syncFunnelSelection($dept, $funnelIds, $stageIds);

            return $dept;
        });

        $department->load(['funnels:id', 'funnelStages:id,funnel_id']);

        return response()->json(['success' => true, 'department' => $department]);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $validated = $this->validatePayload($request, $department);
        [$attrs, $funnelIds, $stageIds] = $this->extractFunnelSelection($validated);

        DB::transaction(function () use ($department, $attrs, $funnelIds, $stageIds): void {
            $department->update($attrs);
            $this->syncFunnelSelection($department, $funnelIds, $stageIds);
        });

        $department->refresh()->load(['funnels:id', 'funnelStages:id,funnel_id']);

        return response()->json(['success' => true, 'department' => $department]);
    }

    /**
     * Общая валидация create/update. Параллельно защищает иерархию от:
     *  - parent_id == self;
     *  - parent_id ∈ descendants(self) — иначе образуется цикл.
     *
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?Department $current): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('departments', 'id'),
            ],
            'is_active' => 'sometimes|boolean',
            'funnel_ids' => 'nullable|array',
            'funnel_ids.*' => ['integer', Rule::exists('funnels', 'id')],
            'funnel_stage_ids' => 'nullable|array',
            'funnel_stage_ids.*' => ['integer', Rule::exists('funnel_stages', 'id')],
        ]);

        $parentId = $validated['parent_id'] ?? null;
        if ($parentId !== null) {
            $parentId = (int) $parentId;
            $validated['parent_id'] = $parentId;
        }

        if ($current !== null && $parentId !== null) {
            if ($parentId === (int) $current->id) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Отдел не может быть родителем самому себе.',
                ]);
            }

            $allDepartments = Department::query()->get(['id', 'parent_id']);
            $descendants = $current->descendantIds($allDepartments);
            if (in_array($parentId, $descendants, true)) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Нельзя назначить родителем дочерний отдел — это создаст цикл.',
                ]);
            }
        }

        // Согласованность: каждый выбранный этап обязан принадлежать одной из
        // выбранных воронок. Иначе на UI получим «висячие» этапы без воронки.
        $funnelIds = array_values(array_unique(array_map('intval', $validated['funnel_ids'] ?? [])));
        $stageIds = array_values(array_unique(array_map('intval', $validated['funnel_stage_ids'] ?? [])));

        if ($stageIds !== []) {
            $stageMeta = FunnelStage::query()
                ->whereIn('id', $stageIds)
                ->pluck('funnel_id', 'id')
                ->all();

            foreach ($stageIds as $sid) {
                $owningFunnelId = isset($stageMeta[$sid]) ? (int) $stageMeta[$sid] : null;
                if ($owningFunnelId === null || ! in_array($owningFunnelId, $funnelIds, true)) {
                    throw ValidationException::withMessages([
                        'funnel_stage_ids' => 'Этап #'.$sid.' принадлежит воронке, которая не подключена к отделу.',
                    ]);
                }
            }
        }

        return $validated;
    }

    /**
     * Делит валидированные данные на «атрибуты модели» и «выбор воронок/этапов».
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: array<string, mixed>, 1: array<int, int>, 2: array<int, int>}
     */
    private function extractFunnelSelection(array $validated): array
    {
        $funnelIds = array_values(array_unique(array_map('intval', $validated['funnel_ids'] ?? [])));
        $stageIds = array_values(array_unique(array_map('intval', $validated['funnel_stage_ids'] ?? [])));

        unset($validated['funnel_ids'], $validated['funnel_stage_ids']);

        return [$validated, $funnelIds, $stageIds];
    }

    /**
     * Синхронизирует pivot'ы `department_funnel` и `department_funnel_stage`.
     * Дополнительно подчищает: этапы, чьи воронки убраны из выбора, удаляются.
     */
    private function syncFunnelSelection(Department $department, array $funnelIds, array $stageIds): void
    {
        $department->funnels()->sync($funnelIds);

        $cleanStageIds = $stageIds;
        if ($funnelIds === []) {
            $cleanStageIds = [];
        } elseif ($cleanStageIds !== []) {
            $allowedStageIds = FunnelStage::query()
                ->whereIn('funnel_id', $funnelIds)
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->all();
            $cleanStageIds = array_values(array_intersect($cleanStageIds, $allowedStageIds));
        }

        $department->funnelStages()->sync($cleanStageIds);
    }

    /**
     * Полный список user_ids — это снимок состава ОДНОГО отдела. Сотрудник может
     * параллельно состоять в нескольких отделах, поэтому работаем строго через pivot:
     *  • detach всех текущих участников отдела, которых нет в $ids;
     *  • attach новых;
     *  • для каждого затронутого пользователя пересчитываем «основной» отдел
     *    (`users.department_id` = наименьший id из его отделов, либо null).
     */
    public function syncMembers(SyncDepartmentMembersRequest $request, Department $department): JsonResponse
    {
        $ids = array_values(array_unique(array_map('intval', $request->userIds())));

        DB::transaction(static function () use ($department, $ids): void {
            $current = $department->users()->pluck('users.id')->map(fn ($v) => (int) $v)->all();
            $toDetach = array_values(array_diff($current, $ids));
            $toAttach = array_values(array_diff($ids, $current));
            $touched = array_unique([...$toDetach, ...$toAttach]);

            if ($toDetach !== []) {
                $department->users()->detach($toDetach);
            }
            if ($toAttach !== []) {
                $department->users()->syncWithoutDetaching($toAttach);
            }

            if ($touched === []) {
                return;
            }

            $users = User::query()->whereIn('id', $touched)->with('departments:id')->get();
            foreach ($users as $user) {
                $deptIds = $user->departments->pluck('id')->map(fn ($v) => (int) $v)->all();
                sort($deptIds);
                $primary = $deptIds[0] ?? null;
                if ((int) $user->department_id !== (int) $primary && ! ($primary === null && $user->department_id === null)) {
                    $user->forceFill(['department_id' => $primary])->save();
                }
            }
        });

        $department->load([
            'users' => static fn ($q) => $q
                ->select('users.id', 'users.name', 'users.email', 'users.department_id')
                ->orderBy('name'),
        ]);
        $department->loadCount('users');

        $users = User::query()
            ->with('departments:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'department_id', 'is_active'])
            ->map(static function (User $u): array {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'department_id' => $u->department_id,
                    'department_ids' => $u->departments->pluck('id')->map(fn ($v) => (int) $v)->all(),
                    'is_active' => $u->is_active,
                ];
            });

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
