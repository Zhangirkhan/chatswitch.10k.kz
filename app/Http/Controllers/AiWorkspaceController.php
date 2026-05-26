<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AI\AiWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

final class AiWorkspaceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('AiChat/Index', [
            'suggestions' => [
                'Какие записи у Михаила на этой неделе — когда занят, когда свободен?',
                'Сделки в воронке на этапе «Переговоры»',
                'Найди в чатах упоминания договора за последний месяц',
                'Клиенты с непрочитанными — диаграмма',
                'Мои задачи отдела на этой неделе',
            ],
        ]);
    }

    public function query(Request $request, AiWorkspaceService $workspace): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'history' => ['nullable', 'array', 'max:30'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:6000'],
        ]);

        $history = is_array($data['history'] ?? null) ? $data['history'] : [];

        try {
            $result = $workspace->handle(
                $request->user(),
                (string) $data['message'],
                $history,
            );
        } catch (RuntimeException $e) {
            Log::warning('[ai-workspace] query failed', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $this->safeErrorMessage($e->getMessage()),
            ], 422);
        } catch (Throwable $e) {
            Log::error('[ai-workspace] unexpected failure', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Не удалось обработать запрос.',
            ], 500);
        }

        return response()->json($result);
    }

    private function safeErrorMessage(string $error): string
    {
        $lower = mb_strtolower($error);
        if (str_contains($lower, 'openai') || str_contains($lower, 'api_key')) {
            return 'AI-сервис недоступен. Проверьте OPENAI_API_KEY в настройках сервера.';
        }

        return $error;
    }
}
