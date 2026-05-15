<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\SystemSetting;
use App\Services\AI\FunnelAiSuggestionService;
use App\Support\TenantCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

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

        $companyId = TenantCompany::id();

        $funnels = Funnel::query()
            ->where('company_id', $companyId)
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
        $stagesPayload = $this->validateOptionalStages($request);
        $companyId = TenantCompany::id();
        $validated['company_id'] = $companyId;

        $funnel = DB::transaction(static function () use ($validated, $stagesPayload, $companyId): Funnel {
            $nextPosition = (int) (Funnel::query()
                ->where('company_id', $companyId)
                ->max('position') ?? -1) + 1;

            $funnel = Funnel::create([
                ...$validated,
                'position' => $nextPosition,
            ]);

            foreach ($stagesPayload as $index => $stage) {
                $funnel->stages()->create([
                    'name' => $stage['name'],
                    'color' => $stage['color'] ?? '#9ca3af',
                    'is_active' => $stage['is_active'] ?? true,
                    'position' => $index,
                ]);
            }

            return $funnel;
        });

        $funnel->load('stages')->loadCount('stages');

        return response()->json(['success' => true, 'funnel' => $funnel]);
    }

    public function update(Request $request, Funnel $funnel): JsonResponse
    {
        $this->ensureModuleEnabled();
        $validated = $this->validateFunnel($request, $funnel);
        unset($validated['company_id']);

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

    public function reorderStages(Request $request, Funnel $funnel): JsonResponse
    {
        $this->ensureModuleEnabled();
        $validated = $request->validate([
            'stage_ids' => ['required', 'array', 'min:1'],
            'stage_ids.*' => ['integer', 'exists:funnel_stages,id'],
        ]);

        $orderedIds = array_values(array_unique(array_map('intval', $validated['stage_ids'])));

        DB::transaction(function () use ($funnel, $orderedIds): void {
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

    public function aiSuggest(Request $request, FunnelAiSuggestionService $service): JsonResponse
    {
        $this->ensureModuleEnabled();

        $validated = $request->validate([
            'business_description' => ['required', 'string', 'min:10', 'max:4000'],
        ]);

        try {
            $suggestion = $service->suggest($validated['business_description']);
        } catch (RuntimeException $e) {
            return $this->aiErrorResponse($request, $e, 'suggest');
        } catch (Throwable $e) {
            return $this->aiUnexpectedResponse($request, $e, 'suggest');
        }

        return response()->json([
            'success' => true,
            'suggestion' => $suggestion,
        ]);
    }

    public function aiOnboardingSuggest(Request $request, FunnelAiSuggestionService $service): JsonResponse
    {
        $this->ensureModuleEnabled();

        $validated = $request->validate([
            'target_audience' => ['required', 'string', 'min:10', 'max:2000'],
            'industry' => ['required', 'string', 'min:3', 'max:2000'],
            'business_description' => ['required', 'string', 'min:10', 'max:2000'],
            'clients_description' => ['required', 'string', 'min:10', 'max:2000'],
            'products_description' => ['required', 'string', 'min:10', 'max:2000'],
            'sales_process' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $validated['company_id'] = TenantCompany::id();

        try {
            $result = $service->suggestVariants($validated);
        } catch (RuntimeException $e) {
            return $this->aiErrorResponse($request, $e, 'onboarding');
        } catch (Throwable $e) {
            return $this->aiUnexpectedResponse($request, $e, 'onboarding');
        }

        return response()->json([
            'success' => true,
            ...$result,
        ]);
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
    private function validateFunnel(Request $request, ?Funnel $existing = null): array
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

    /**
     * @return list<array{name: string, color?: string|null, is_active?: bool}>
     */
    private function validateOptionalStages(Request $request): array
    {
        if (! $request->has('stages')) {
            return [];
        }

        $validated = $request->validate([
            'stages' => ['array', 'max:20'],
            'stages.*.name' => ['required', 'string', 'max:255'],
            'stages.*.color' => ['nullable', 'string', 'max:16'],
            'stages.*.is_active' => ['sometimes', 'boolean'],
        ]);

        return array_values($validated['stages'] ?? []);
    }

    private function aiErrorResponse(Request $request, RuntimeException $e, string $context): JsonResponse
    {
        Log::warning("[funnel-ai] {$context} failed", [
            'user_id' => $request->user()?->id,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => $this->safeAiErrorMessage($e->getMessage()),
        ], 422);
    }

    private function aiUnexpectedResponse(Request $request, Throwable $e, string $context): JsonResponse
    {
        Log::error("[funnel-ai] {$context} unexpected failure", [
            'user_id' => $request->user()?->id,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Не удалось получить ответ AI. Попробуйте ещё раз.',
        ], 500);
    }

    private function safeAiErrorMessage(string $error): string
    {
        $lower = mb_strtolower($error);

        if (str_contains($lower, 'openai') || str_contains($lower, 'api') || str_contains($lower, 'timeout')) {
            return 'AI-сервис временно недоступен. Попробуйте ещё раз позже.';
        }

        return $error !== '' ? $error : 'AI временно недоступен. Попробуйте ещё раз.';
    }
}
