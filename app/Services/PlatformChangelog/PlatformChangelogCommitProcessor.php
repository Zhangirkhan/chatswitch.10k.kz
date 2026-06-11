<?php

declare(strict_types=1);

namespace App\Services\PlatformChangelog;

use App\Services\AI\AiUsageOptions;
use App\Services\AI\OpenAiChatService;
use App\Support\PlatformChangelog\GitCommitSnapshot;
use RuntimeException;

final class PlatformChangelogCommitProcessor
{
    public function __construct(private readonly OpenAiChatService $openAi) {}

    /**
     * @return array{
     *     include: bool,
     *     title: array{ru: string, kk: string, en: string},
     *     body: array{ru: string, kk: string, en: string}
     * }|null null — если OpenAI недоступен или ответ невалиден
     */
    public function process(GitCommitSnapshot $commit): ?array
    {
        if ((string) config('services.openai.api_key') === '') {
            return null;
        }

        $details = trim($commit->body) !== ''
            ? "Тема: {$commit->subject}\n\nОписание коммита:\n{$commit->body}"
            : "Тема: {$commit->subject}";

        $messages = [
            [
                'role' => 'system',
                'content' => <<<'PROMPT'
Ты редактор changelog для CRM-платформы Accel (операторы, менеджеры, админы компаний).

На вход — технический git-коммит разработчиков. Реши, показывать ли изменение конечным пользователям.

Не включай (include=false): рефакторинг, тесты, CI/CD, деплой, миграции без видимого эффекта, мелкие правки, обновление зависимостей, документацию для разработчиков, форматирование.

Включай (include=true): новые функции, заметные улучшения интерфейса, исправления багов, влияющие на работу пользователя, изменения в настройках/отчётах/интеграциях.

Если include=true — напиши короткий заголовок (до 120 символов) и описание (1–3 предложения) на русском, казахском и английском.
Стиль: понятный бизнес-пользователю, без жаргона разработчиков, без слов «коммит», «git», «PR», «refactor».

Ответ — строго JSON:
{"include": true|false, "title": {"ru": "", "kk": "", "en": ""}, "body": {"ru": "", "kk": "", "en": ""}}
PROMPT,
            ],
            [
                'role' => 'user',
                'content' => $details,
            ],
        ];

        try {
            $decoded = $this->openAi->chatJson(
                $messages,
                0.2,
                1200,
                new AiUsageOptions('platform_changelog'),
            );
        } catch (RuntimeException) {
            return null;
        }

        if (! is_bool($decoded['include'] ?? null)) {
            return null;
        }

        if ($decoded['include'] !== true) {
            return [
                'include' => false,
                'title' => ['ru' => '', 'kk' => '', 'en' => ''],
                'body' => ['ru' => '', 'kk' => '', 'en' => ''],
            ];
        }

        $title = $this->normalizeTranslations($decoded['title'] ?? null, 200);
        $body = $this->normalizeTranslations($decoded['body'] ?? null, 10000);

        if ($title['ru'] === '' || $body['ru'] === '') {
            return null;
        }

        return [
            'include' => true,
            'title' => $title,
            'body' => $body,
        ];
    }

    /**
     * @return array{ru: string, kk: string, en: string}
     */
    private function normalizeTranslations(mixed $value, int $maxLen): array
    {
        $source = is_array($value) ? $value : [];

        $ru = $this->clip(is_string($source['ru'] ?? null) ? trim($source['ru']) : '', $maxLen);
        $kk = $this->clip(is_string($source['kk'] ?? null) ? trim($source['kk']) : '', $maxLen);
        $en = $this->clip(is_string($source['en'] ?? null) ? trim($source['en']) : '', $maxLen);

        if ($kk === '') {
            $kk = $ru;
        }
        if ($en === '') {
            $en = $ru;
        }

        return [
            'ru' => $ru,
            'kk' => $kk,
            'en' => $en,
        ];
    }

    private function clip(string $value, int $maxLen): string
    {
        if ($value === '') {
            return '';
        }

        return mb_strlen($value) > $maxLen ? mb_substr($value, 0, $maxLen) : $value;
    }
}
