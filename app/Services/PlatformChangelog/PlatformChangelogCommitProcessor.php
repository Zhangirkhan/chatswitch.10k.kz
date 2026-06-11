<?php

declare(strict_types=1);

namespace App\Services\PlatformChangelog;

use App\Services\AI\AiUsageOptions;
use App\Services\AI\OpenAiChatService;
use App\Support\PlatformChangelog\GitCommitPathClassifier;
use App\Support\PlatformChangelog\GitCommitSnapshot;
use RuntimeException;

final class PlatformChangelogCommitProcessor
{
    public function __construct(
        private readonly OpenAiChatService $openAi,
        private readonly GitCommitPathClassifier $pathClassifier,
    ) {}

    /**
     * @return array{
     *     include: bool,
     *     audience: string,
     *     title: array{ru: string, kk: string, en: string},
     *     body: array{ru: string, kk: string, en: string}
     * }|null null — если OpenAI недоступен или ответ невалиден
     */
    public function process(GitCommitSnapshot $commit): ?array
    {
        if ($this->pathClassifier->isInternalOnly($commit->changedPaths)) {
            return $this->buildInternalEntry($commit);
        }

        if ((string) config('services.openai.api_key') === '') {
            return null;
        }

        $details = trim($commit->body) !== ''
            ? "Тема: {$commit->subject}\n\nОписание коммита:\n{$commit->body}"
            : "Тема: {$commit->subject}";

        if ($commit->changedPaths !== []) {
            $details .= "\n\nИзменённые файлы:\n".implode("\n", array_slice($commit->changedPaths, 0, 40));
        }

        $messages = [
            [
                'role' => 'system',
                'content' => <<<'PROMPT'
Ты редактор changelog для CRM-платформы Accel (операторы, менеджеры, админы компаний).

На вход — технический git-коммит разработчиков. Реши, показывать ли изменение конечным пользователям.

Не включай (include=false): рефакторинг, тесты, CI/CD, деплой, миграции без видимого эффекта, мелкие правки, обновление зависимостей, документацию для разработчиков, форматирование.

Если include=true, укажи audience:
- audience="user" — изменение видят операторы/менеджеры в tenant CRM или mobile app: чаты, клиенты, воронки, календарь, tenant settings, интеграции, баннеры/уведомления для пользователей.
- audience="internal" — только Super Admin / внутренняя админка платформы: UI /platform-changelog, /platform-banners, audit logs, git-sync, sandbox admin, стили таблиц в Super Admin, backend-only infra без эффекта для операторов CRM.

Включай (include=true, audience=user): новые функции, заметные улучшения интерфейса tenant-приложения, исправления багов, влияющие на работу пользователя, изменения в настройках/отчётах/интеграциях.

Если include=true — напиши короткий заголовок (до 120 символов) и описание (1–3 предложения) на русском, казахском и английском.
Стиль: понятный бизнес-пользователю, без жаргона разработчиков, без слов «коммит», «git», «PR», «refactor», «Super Admin» (для audience=user).

Ответ — строго JSON:
{"include": true|false, "audience": "user"|"internal", "title": {"ru": "", "kk": "", "en": ""}, "body": {"ru": "", "kk": "", "en": ""}}
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
                'audience' => 'internal',
                'title' => ['ru' => '', 'kk' => '', 'en' => ''],
                'body' => ['ru' => '', 'kk' => '', 'en' => ''],
            ];
        }

        $title = $this->normalizeTranslations($decoded['title'] ?? null, 200);
        $body = $this->normalizeTranslations($decoded['body'] ?? null, 10000);

        if ($title['ru'] === '' || $body['ru'] === '') {
            return null;
        }

        $audience = strtolower((string) ($decoded['audience'] ?? 'user'));

        return [
            'include' => true,
            'audience' => $audience === 'internal' ? 'internal' : 'user',
            'title' => $title,
            'body' => $body,
        ];
    }

    /**
     * @return array{
     *     include: bool,
     *     audience: string,
     *     title: array{ru: string, kk: string, en: string},
     *     body: array{ru: string, kk: string, en: string}
     * }
     */
    private function buildInternalEntry(GitCommitSnapshot $commit): array
    {
        $subject = mb_substr(trim($commit->subject), 0, 200);

        return [
            'include' => true,
            'audience' => 'internal',
            'title' => [
                'ru' => $subject,
                'kk' => $subject,
                'en' => $subject,
            ],
            'body' => [
                'ru' => 'Внутреннее обновление Super Admin.',
                'kk' => 'Super Admin ішкі жаңартуы.',
                'en' => 'Internal Super Admin update.',
            ],
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
