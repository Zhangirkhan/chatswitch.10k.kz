<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\AI\OpenAiChatService;
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
    private const SUPPORTED_LANGS = [
        'ru' => 'русский',
        'kk' => 'казахский',
        'en' => 'английский',
        'zh' => 'китайский',
        'tr' => 'турецкий',
        'ar' => 'арабский',
    ];

    /** Максимальная длина тела сообщения для перевода (символов). */
    private const MAX_BODY_LEN = 4000;

    public function __construct(private readonly OpenAiChatService $openAi) {}

    public function translate(Request $request, Message $message): JsonResponse
    {
        $validated = $request->validate([
            'lang' => ['required', 'string', 'in:'.implode(',', array_keys(self::SUPPORTED_LANGS))],
        ]);

        $body = (string) ($message->body ?? '');
        if (trim($body) === '') {
            return response()->json(['translation' => '']);
        }

        $body = mb_substr($body, 0, self::MAX_BODY_LEN);
        $langName = self::SUPPORTED_LANGS[$validated['lang']];

        $messages = [
            [
                'role' => 'system',
                'content' => <<<PROMPT
Ты — профессиональный переводчик. Переведи текст на {$langName} язык.
Правила:
— Возвращай ТОЛЬКО перевод, без пояснений, кавычек и дополнительного текста.
— Сохраняй форматирование: переносы строк, знаки препинания, эмодзи.
— Если текст уже на нужном языке — верни его без изменений.
— Не добавляй ни одного лишнего слова.
PROMPT,
            ],
            [
                'role' => 'user',
                'content' => $body,
            ],
        ];

        try {
            $translation = $this->openAi->chat($messages, 0.2, 1000);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Сервис перевода недоступен.'], 503);
        }

        return response()->json(['translation' => trim($translation)]);
    }
}
