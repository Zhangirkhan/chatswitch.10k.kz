<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Support\MessageLanguageHeuristics;

final class MessageTranslationService
{
    private const MAX_BODY_LEN = 4000;

    public function __construct(private readonly OpenAiChatService $openAi) {}

    public function translate(string $body, string $lang, ?int $companyId): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        if (! in_array($lang, MessageLanguageHeuristics::SUPPORTED, true)) {
            throw new \InvalidArgumentException('Unsupported translation language.');
        }

        $body = mb_substr($body, 0, self::MAX_BODY_LEN);
        $langName = MessageLanguageHeuristics::LABELS[$lang];

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
— Казахский язык может быть написан кириллицей (в том числе без специальных букв), латиницей или русской транслитерацией (например: «салеметсизбе», «калайсын», «salam», «qalaysyn», «rahmet»). Распознавай такие формы как казахский и переводи их корректно, не путай с русским.
PROMPT,
            ],
            [
                'role' => 'user',
                'content' => $body,
            ],
        ];

        $translation = $this->openAi->chat(
            $messages,
            0.2,
            1000,
            new AiUsageOptions('translation', $companyId),
        );

        return trim($translation);
    }
}
