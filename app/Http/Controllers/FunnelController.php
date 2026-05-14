<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD воронок продаж и их этапов. Все эндпоинты только для администратора —
 * см. middleware на роутах в `routes/web.php`. Этапы — отдельные ресурсы,
 * вложенные в воронку: единичные действия (create/update/destroy) и групповой
 * `reorderStages` для перестановки порядка drag-and-drop'ом.
 */
final class FunnelController extends Controller
{
    public function index(): Response
    {
        $this->ensureModuleEnabled();

        $funnels = Funnel::query()
            ->with(['stages'])
            ->withCount('stages')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return Inertia::render('Settings/Funnels', [
            'funnels' => $funnels,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureModuleEnabled();
        $validated = $this->validateFunnel($request);

        $funnel = DB::transaction(static function () use ($validated): Funnel {
            // Новая воронка попадает в конец списка — берём максимальный
            // position и +1; стартуем с 0, если воронок ещё нет.
            $nextPosition = (int) (Funnel::max('position') ?? -1) + 1;

            return Funnel::create([
                ...$validated,
                'position' => $nextPosition,
            ]);
        });

        $funnel->load('stages')->loadCount('stages');

        return response()->json(['success' => true, 'funnel' => $funnel]);
    }

    public function update(Request $request, Funnel $funnel): JsonResponse
    {
        $this->ensureModuleEnabled();
        $validated = $this->validateFunnel($request);

        $funnel->update($validated);
        $funnel->load('stages')->loadCount('stages');

        return response()->json(['success' => true, 'funnel' => $funnel]);
    }

    public function destroy(Funnel $funnel): JsonResponse
    {
        $this->ensureModuleEnabled();
        $funnel->delete();

        return response()->json(['success' => true]);
    }

    public function storeStage(Request $request, Funnel $funnel): JsonResponse
    {
        $this->ensureModuleEnabled();
        $validated = $this->validateStage($request);

        $stage = DB::transaction(static function () use ($funnel, $validated): FunnelStage {
            $nextPosition = (int) ($funnel->stages()->max('position') ?? -1) + 1;

            return $funnel->stages()->create([
                ...$validated,
                'position' => $nextPosition,
            ]);
        });

        $funnel->load('stages')->loadCount('stages');

        return response()->json([
            'success' => true,
            'funnel' => $funnel,
            'stage' => $stage->fresh(),
        ]);
    }

    public function updateStage(Request $request, Funnel $funnel, FunnelStage $stage): JsonResponse
    {
        $this->ensureModuleEnabled();
        // Защита от подмены id в URL: этап должен принадлежать переданной воронке.
        abort_if((int) $stage->funnel_id !== (int) $funnel->id, 404);

        $validated = $this->validateStage($request);

        $stage->update($validated);
        $funnel->load('stages')->loadCount('stages');

        return response()->json([
            'success' => true,
            'funnel' => $funnel,
            'stage' => $stage->fresh(),
        ]);
    }

    public function destroyStage(Funnel $funnel, FunnelStage $stage): JsonResponse
    {
        $this->ensureModuleEnabled();
        abort_if((int) $stage->funnel_id !== (int) $funnel->id, 404);

        $stage->delete();
        $funnel->load('stages')->loadCount('stages');

        return response()->json(['success' => true, 'funnel' => $funnel]);
    }

    /**
     * Перестановка этапов в новом порядке. На вход — массив id этапов в
     * нужной последовательности; обновляем `position` каждому одной транзакцией.
     */
    public function reorderStages(Request $request, Funnel $funnel): JsonResponse
    {
        $this->ensureModuleEnabled();
        $validated = $request->validate([
            'stage_ids' => ['required', 'array', 'min:1'],
            'stage_ids.*' => ['integer', 'exists:funnel_stages,id'],
        ]);

        $orderedIds = array_values(array_unique(array_map('intval', $validated['stage_ids'])));

        DB::transaction(function () use ($funnel, $orderedIds): void {
            // Берём только те этапы, что реально принадлежат этой воронке —
            // защита от посторонних id, которые «прошли» exists-валидацию.
            $ownIds = $funnel->stages()->pluck('id')->map(fn ($v) => (int) $v)->all();

            $position = 0;
            foreach ($orderedIds as $stageId) {
                if (! in_array($stageId, $ownIds, true)) {
                    continue;
                }
                FunnelStage::query()
                    ->where('id', $stageId)
                    ->update(['position' => $position]);
                $position++;
            }
        });

        $funnel->load('stages')->loadCount('stages');

        return response()->json(['success' => true, 'funnel' => $funnel]);
    }

    private function ensureModuleEnabled(): void
    {
        abort_unless(
            SystemSetting::getValue('module_funnels', 'on') === 'on',
            403,
            'Модуль «Воронки продаж» отключён администратором.',
        );
    }

    /** @return array<string, mixed> */
    private function validateFunnel(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'color' => ['nullable', 'string', 'max:16'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function validateStage(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:16'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
