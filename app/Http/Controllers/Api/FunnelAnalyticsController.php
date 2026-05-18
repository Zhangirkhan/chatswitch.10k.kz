<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Funnel;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Funnel\FunnelConversionAnalyticsService;
use App\Support\TenantCompany;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FunnelAnalyticsController extends Controller
{
    public function __construct(
        private readonly FunnelConversionAnalyticsService $conversionAnalytics,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(
            SystemSetting::getValue('module_funnels', 'on') === 'on',
            403,
            'Модуль «Воронки продаж» отключён администратором.',
        );

        $user = $request->user();
        abort_unless($user && $user->hasAnyRole(['administrator', 'manager', 'employee']), 403);

        $validated = $request->validate([
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'funnel_id' => ['nullable', 'integer', 'exists:funnels,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $departmentId = isset($validated['department_id']) ? (int) $validated['department_id'] : null;
        $employeeId = isset($validated['employee_id']) ? (int) $validated['employee_id'] : null;
        $funnelId = isset($validated['funnel_id']) ? (int) $validated['funnel_id'] : null;
        $allowedDepartmentIds = $this->allowedDepartmentIds($user);

        if ($departmentId !== null && ! in_array($departmentId, $allowedDepartmentIds, true)) {
            abort(403);
        }

        $scopeDepartmentIds = $departmentId !== null ? [$departmentId] : $allowedDepartmentIds;

        $funnels = Funnel::query()
            ->where('company_id', TenantCompany::id())
            ->with(['stages', 'departments:id,name'])
            ->withCount('stages')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $stageSelections = DB::table('department_funnel_stage')
            ->join('funnel_stages', 'funnel_stages.id', '=', 'department_funnel_stage.funnel_stage_id')
            ->whereIn('department_funnel_stage.department_id', $scopeDepartmentIds)
            ->select([
                'department_funnel_stage.department_id',
                'department_funnel_stage.funnel_stage_id',
                'funnel_stages.funnel_id',
            ])
            ->get();

        $connectedRows = DB::table('department_funnel')
            ->whereIn('department_id', $scopeDepartmentIds)
            ->get(['department_id', 'funnel_id']);

        $selectedStagesByFunnel = [];
        foreach ($stageSelections as $row) {
            $fid = (int) $row->funnel_id;
            $selectedStagesByFunnel[$fid] ??= [];
            $selectedStagesByFunnel[$fid][(int) $row->funnel_stage_id] = true;
        }

        $connectedDeptIdsByFunnel = [];
        foreach ($connectedRows as $row) {
            $fid = (int) $row->funnel_id;
            $connectedDeptIdsByFunnel[$fid] ??= [];
            $connectedDeptIdsByFunnel[$fid][(int) $row->department_id] = true;
        }

        $rows = $funnels->map(function (Funnel $funnel) use ($connectedDeptIdsByFunnel, $selectedStagesByFunnel): array {
            $connectedDeptIds = array_keys($connectedDeptIdsByFunnel[$funnel->id] ?? []);
            $selectedStageIds = array_keys($selectedStagesByFunnel[$funnel->id] ?? []);
            $stagesCount = (int) $funnel->stages_count;
            $selectedCount = count($selectedStageIds);

            return [
                'id' => $funnel->id,
                'name' => $funnel->name,
                'description' => $funnel->description,
                'color' => $funnel->color,
                'is_active' => (bool) $funnel->is_active,
                'stages_count' => $stagesCount,
                'selected_stages_count' => $selectedCount,
                'coverage_percent' => $stagesCount > 0 ? round($selectedCount * 100 / $stagesCount, 1) : null,
                'departments_count' => count($connectedDeptIds),
                'departments' => $funnel->departments
                    ->whereIn('id', $connectedDeptIds)
                    ->values()
                    ->map(fn (Department $department) => [
                        'id' => $department->id,
                        'name' => $department->name,
                    ])
                    ->all(),
                'stages' => $funnel->stages->map(fn ($stage) => [
                    'id' => $stage->id,
                    'name' => $stage->name,
                    'color' => $stage->color,
                    'is_active' => (bool) $stage->is_active,
                    'selected' => in_array((int) $stage->id, $selectedStageIds, true),
                ])->values()->all(),
            ];
        })->values();

        $activeFunnels = $rows->where('is_active', true)->count();
        $connectedFunnels = $rows->filter(fn (array $row) => (int) $row['departments_count'] > 0)->count();
        $totalStages = $rows->sum('stages_count');
        $selectedStages = $rows->sum('selected_stages_count');

        $from = isset($validated['from'])
            ? Carbon::parse((string) $validated['from'])->startOfDay()
            : now()->subDays(7)->startOfDay();
        $to = isset($validated['to'])
            ? Carbon::parse((string) $validated['to'])->endOfDay()
            : now()->endOfDay();

        $conversion = $this->conversionAnalytics->build(
            $user,
            $from,
            $to,
            $funnelId,
            $departmentId,
            $employeeId,
        );

        return response()->json([
            'summary' => [
                'total_funnels' => $rows->count(),
                'active_funnels' => $activeFunnels,
                'connected_funnels' => $connectedFunnels,
                'total_stages' => $totalStages,
                'selected_stages' => $selectedStages,
                'departments_in_scope' => count($scopeDepartmentIds),
                'stage_coverage_percent' => $totalStages > 0 ? round($selectedStages * 100 / $totalStages, 1) : null,
                'tracked_chats' => $conversion['summary']['tracked_chats'],
                'total_transitions' => $conversion['summary']['total_transitions'],
                'funnels_with_conversion_data' => $conversion['summary']['funnels_with_data'],
            ],
            'funnels' => $rows,
            'conversion' => $conversion,
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function allowedDepartmentIds(User $user): array
    {
        if ($user->hasRole('administrator')) {
            return Department::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return $user->departmentIds();
    }
}
