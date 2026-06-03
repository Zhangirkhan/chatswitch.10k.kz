<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\AI\AiUsageOptions;
use App\Services\AI\MessageTranslationService;
use App\Support\MessageInboundText;
use App\Support\MessageLanguageHeuristics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Переводит тело WhatsApp-сообщения на указанный язык через OpenAI.
 *
 * Сообщения принадлежат чату, доступному текущему пользователю.
 * Перевод не сохраняется в БД — кеш хранится на стороне клиента.
 */
final class MessageTranslationController extends Controller
{
    public function __construct(private readonly MessageTranslationService $translation) {}

    public function translate(Request $request, Message $message): JsonResponse
    {
        $message->loadMissing('chat');
        if ($message->chat === null) {
            abort(404);
        }

        $this->authorize('view', $message->chat);

        $validated = $request->validate([
            'lang' => ['required', 'string', 'in:'.implode(',', MessageLanguageHeuristics::SUPPORTED)],
        ]);

        $body = MessageInboundText::forMessage($message);
        if (trim($body) === '') {
            return response()->json(['translation' => '']);
        }

        try {
            $translation = $this->translation->translate($body, $validated['lang'], $message->chat->company_id);
        } catch (\Throwable) {
            return response()->json(['error' => 'Сервис перевода недоступен.'], 503);
        }

        return response()->json(['translation' => $translation]);
    }
}
