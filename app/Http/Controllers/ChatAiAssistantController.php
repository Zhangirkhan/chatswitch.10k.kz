<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Services\AI\ChatAssistantService;
use App\Support\AiSafeErrorMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Endpoint правой панели «AI-ассистент оператора» в окне диалога.
 * Каждый запрос самодостаточен: фронт присылает локальную историю переписки
 * с AI, бэк дополняет её свежим контекстом всех сообщений чата (БД — источник правды)
 * и проксирует в OpenAI.
 */
final class ChatAiAssistantController extends Controller
{
    public function chat(Request $request, Chat $chat, ChatAssistantService $assistant): JsonResponse
    {
        $this->authorize('view', $chat);

        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:4000'],
            'history' => ['nullable', 'array', 'max:40'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:6000'],
        ]);

        $history = is_array($data['history'] ?? null) ? $data['history'] : [];
        $message = (string) ($data['message'] ?? '');

        try {
            $result = $assistant->reply(
                $chat,
                $request->user(),
                $history,
                $message,
            );
        } catch (RuntimeException $e) {
            Log::warning('[ai-assistant] reply failed', [
                'chat_id' => $chat->id,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => AiSafeErrorMessage::forUser(
                    $e->getMessage(),
                    $request->user()?->hasRole('administrator') === true,
                ),
                'technical_error' => $request->user()?->hasRole('administrator') === true ? $e->getMessage() : null,
            ], 502);
        } catch (Throwable $e) {
            Log::error('[ai-assistant] unexpected failure', [
                'chat_id' => $chat->id,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Не удалось получить ответ AI.',
            ], 500);
        }

        return response()->json([
            'reply' => $result['reply'],
            'reply_draft' => $result['reply_draft'],
            'product' => $result['product'],
            'reply_intro' => $result['reply_intro'],
            'reply_variants' => $result['reply_variants'],
        ]);
    }
}
