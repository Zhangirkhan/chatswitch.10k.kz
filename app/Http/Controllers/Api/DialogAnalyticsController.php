<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DialogAnalyticsRequest;
use App\Models\SystemSetting;
use App\Services\DialogAnalytics\DialogAnalyticsFilters;
use App\Services\DialogAnalytics\DialogAnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

final class DialogAnalyticsController extends Controller
{
    public function __invoke(DialogAnalyticsRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless(SystemSetting::getValue('module_analytics', 'on') === 'on', 403, 'Модуль «Аналитика диалогов» отключён администратором.');

        $v = $request->validated();
        $filters = new DialogAnalyticsFilters(
            from: Carbon::parse($v['from'])->startOfDay(),
            to: Carbon::parse($v['to'])->endOfDay(),
            employeeId: isset($v['employee_id']) && $v['employee_id'] !== null ? (int) $v['employee_id'] : null,
            departmentId: isset($v['department_id']) && $v['department_id'] !== null ? (int) $v['department_id'] : null,
            status: $v['status'],
            channel: $v['channel'],
            page: (int) $v['page'],
            perPage: (int) $v['per_page'],
        );

        $service = DialogAnalyticsService::fromSettings();

        $cacheKey = 'dialog_analytics:'.hash('xxh128', serialize([
            $user->id,
            $user->getRoleNames()->sort()->values()->all(),
            $filters->cacheKeyPayload(),
        ]));

        $payload = Cache::remember($cacheKey, 120, static function () use ($service, $user, $filters): array {
            return $service->build($user, $filters);
        });

        return response()->json($payload);
    }
}
