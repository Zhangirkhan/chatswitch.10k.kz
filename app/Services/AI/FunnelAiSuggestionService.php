<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Генерация черновика воронки продаж и её этапов по описанию бизнеса от клиента.
 *
 * На вход — свободный текст или структурированный онбординг. На выход — нормализованная
 * структура, пригодная для превью на фронте и атомарного сохранения. Ничего не пишет в БД.
 */
final class FunnelAiSuggestionService
{
    /**
     * @var list<string>
     */
    private const FUNNEL_PALETTE = [
        '#25d366', '#34d399', '#22d3ee', '#3b82f6', '#6366f1',
        '#8b5cf6', '#a855f7', '#ec4899', '#ef4444', '#f97316',
        '#f59e0b', '#facc15', '#84cc16', '#9ca3af', '#64748b',
    ];

    /**
     * @var list<string>
     */
    private const STAGE_PALETTE = [
        '#9ca3af', '#3b82f6', '#6366f1', '#8b5cf6', '#a855f7',
        '#ec4899', '#f97316', '#f59e0b', '#facc15', '#84cc16',
        '#34d399', '#25d366', '#22d3ee', '#ef4444', '#64748b',
    ];

    private const MIN_STAGES = 3;

    private const MAX_STAGES = 8;

    private const MIN_VARIANTS = 2;

    private const TARGET_VARIANTS = 3;

    private const MAX_DESCRIPTION_INPUT = 4000;

    private const MAX_ONBOARDING_FIELD = 2000;

    private const MAX_NAME_LEN = 120;

    private const MAX_DESCRIPTION_LEN = 500;

    private const MAX_STAGE_NAME_LEN = 80;

    private const MAX_RATIONALE_LEN = 300;

    private const MAX_KNOWLEDGE_CONTEXT = 3000;

    public function __construct(
        private readonly OpenAiChatService $openAi,
        private readonly KnowledgeContextTextFormatter $knowledgeTextFormatter,
    ) {}

    /**
     * @return array{
     *     name: string,
     *     description: string,
     *     color: string,
     *     stages: list<array{name: string, color: string}>,
     * }
     */
    public function suggest(string $businessDescription): array
    {
        $description = trim($businessDescription);
        if ($description === '') {
            throw new RuntimeException('Опишите бизнес, чтобы AI смог предложить воронку.');
        }

        $description = Str::limit($description, self::MAX_DESCRIPTION_INPUT, '...');

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->userPrompt($description)],
        ];

        $raw = $this->openAi->chatJson($messages, 0.4, 900);

        return $this->normalize($raw);
    }

    /**
     * @param  array{
     *     company_id: int,
     *     target_audience: string,
     *     industry: string,
     *     business_description: string,
     *     clients_description: string,
     *     products_description: string,
     *     sales_process: string,
     * }  $input
     * @return array{
     *     suggestions: list<array{
     *         name: string,
     *         description: string,
     *         color: string,
     *         rationale: string,
     *         stages: list<array{name: string, color: string}>,
     *     }>,
     * }
     */
    public function suggestVariants(array $input): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->variantsSystemPrompt()],
            ['role' => 'user', 'content' => $this->variantsUserPrompt($input)],
        ];

        $raw = $this->openAi->chatJson($messages, 0.45, 1400);

        return $this->normalizeVariants($raw);
    }

    private function systemPrompt(): string
    {
        $minStages = self::MIN_STAGES;
        $maxStages = self::MAX_STAGES;
        $palette = implode(', ', self::FUNNEL_PALETTE);

        return <<<PROMPT
Ты — методолог отдела продаж. По описанию бизнеса ты проектируешь воронку продаж и её этапы под реальный путь клиента в этой нише.

Правила:
1. Отвечай строго валидным JSON-объектом без какого-либо текста вокруг.
2. Поля верхнего уровня: name, description, color, stages.
3. name — короткое название воронки на языке описания (по умолчанию русский), до 80 символов, без кавычек и эмодзи.
4. description — 1–2 предложения о том, для какого процесса воронка, до 300 символов.
5. color — hex-цвет вида "#rrggbb" из палитры: {$palette}.
6. stages — массив из {$minStages}–{$maxStages} этапов, упорядоченных от первого касания клиента к закрытию сделки/повторной продаже. У каждого этапа поля name (до 60 символов, без эмодзи) и color (hex из той же палитры).
7. Этапы должны соответствовать реальному циклу сделки в описанной нише: учитывай тип продукта (товар/услуга/подписка), тип клиента (B2C/B2B), длину сделки. Не используй универсальные шаблонные этапы, если бизнес узкоспециализированный.
8. Не дублируй этапы и не пиши абстракции вроде «Прочее», «Архив» — это не этапы продаж.
9. Финальный этап — закрытие сделки или повторная продажа, типичная для ниши (например, «Сделка закрыта», «Оплачено», «Доставлено», «Подписан договор»).
PROMPT;
    }

    private function variantsSystemPrompt(): string
    {
        $minStages = self::MIN_STAGES;
        $maxStages = self::MAX_STAGES;
        $minVariants = self::MIN_VARIANTS;
        $targetVariants = self::TARGET_VARIANTS;
        $palette = implode(', ', self::FUNNEL_PALETTE);

        return <<<PROMPT
Ты — методолог отдела продаж. По ответам онбординга ты проектируешь несколько разных воронок продаж под один бизнес.

Правила:
1. Отвечай строго валидным JSON-объектом без текста вокруг.
2. Поле верхнего уровня: suggestions — массив из {$targetVariants} объектов (минимум {$minVariants}).
3. Каждый объект suggestions содержит: name, description, color, rationale, stages.
4. name — короткое название воронки (до 80 символов, русский, без эмодзи).
5. description — 1–2 предложения о процессе воронки (до 300 символов).
6. rationale — 1–2 предложения, почему этот вариант подходит именно этому бизнесу (до 250 символов).
7. color — hex "#rrggbb" из палитры: {$palette}.
8. stages — {$minStages}–{$maxStages} этапов с полями name и color; этапы упорядочены от первого касания к закрытию.
9. Варианты должны отличаться по сценарию: например короткий цикл / длинный B2B / повторные продажи / премиум-консультация — выбери подходящие для ниши.
10. Не дублируй одинаковые этапы между вариантами без необходимости; названия воронок тоже должны различаться.
PROMPT;
    }

    private function userPrompt(string $description): string
    {
        return "Описание бизнеса от клиента:\n{$description}\n\nВерни JSON по правилам выше.";
    }

    /**
     * @param  array{
     *     company_id: int,
     *     target_audience: string,
     *     industry: string,
     *     business_description: string,
     *     clients_description: string,
     *     products_description: string,
     *     sales_process: string,
     * }  $input
     */
    private function variantsUserPrompt(array $input): string
    {
        $knowledgeBlock = $this->optionalKnowledgeBlock((int) $input['company_id']);

        $targetAudience = Str::limit(trim($input['target_audience']), self::MAX_ONBOARDING_FIELD, '...');
        $industry = Str::limit(trim($input['industry']), self::MAX_ONBOARDING_FIELD, '...');
        $business = Str::limit(trim($input['business_description']), self::MAX_ONBOARDING_FIELD, '...');
        $clients = Str::limit(trim($input['clients_description']), self::MAX_ONBOARDING_FIELD, '...');
        $products = Str::limit(trim($input['products_description']), self::MAX_ONBOARDING_FIELD, '...');
        $sales = Str::limit(trim($input['sales_process']), self::MAX_ONBOARDING_FIELD, '...');

        $prompt = <<<PROMPT
Ответы онбординга:

1. Целевая аудитория: {$targetAudience}
2. Сфера деятельности: {$industry}
3. О бизнесе: {$business}
4. Клиенты: {$clients}
5. Товары и услуги: {$products}
6. Процесс продаж: {$sales}
PROMPT;

        if ($knowledgeBlock !== '') {
            $prompt .= "\n\nДополнительный контекст из базы знаний компании:\n{$knowledgeBlock}";
        }

        $prompt .= "\n\nВерни JSON с полем suggestions по правилам выше.";

        return $prompt;
    }

    private function optionalKnowledgeBlock(int $companyId): string
    {
        $lines = $this->knowledgeTextFormatter->knowledgeLines($companyId);
        if ($lines === []) {
            return '';
        }

        $text = implode("\n", $lines);

        return Str::limit($text, self::MAX_KNOWLEDGE_CONTEXT, '...');
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *     name: string,
     *     description: string,
     *     color: string,
     *     stages: list<array{name: string, color: string}>,
     * }
     */
    private function normalize(array $raw): array
    {
        $name = $this->sanitizeName($raw['name'] ?? null, 'Новая воронка', self::MAX_NAME_LEN);
        $description = $this->sanitizeName($raw['description'] ?? null, '', self::MAX_DESCRIPTION_LEN);
        $color = $this->normalizeHex($raw['color'] ?? null, self::FUNNEL_PALETTE[0]);
        $stages = $this->normalizeStages($raw['stages'] ?? []);

        if (count($stages) < self::MIN_STAGES) {
            throw new RuntimeException('AI не смог сформировать корректные этапы. Уточните описание бизнеса.');
        }

        return [
            'name' => $name,
            'description' => $description,
            'color' => $color,
            'stages' => $stages,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *     suggestions: list<array{
     *         name: string,
     *         description: string,
     *         color: string,
     *         rationale: string,
     *         stages: list<array{name: string, color: string}>,
     *     }>,
     * }
     */
    private function normalizeVariants(array $raw): array
    {
        $rawSuggestions = $raw['suggestions'] ?? [];
        if (! is_array($rawSuggestions)) {
            $rawSuggestions = [];
        }

        $suggestions = [];
        $seenNames = [];

        foreach (array_values($rawSuggestions) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = $this->sanitizeName($item['name'] ?? null, '', self::MAX_NAME_LEN);
            if ($name === '') {
                continue;
            }

            $key = mb_strtolower($name);
            if (isset($seenNames[$key])) {
                continue;
            }
            $seenNames[$key] = true;

            $stages = $this->normalizeStages($item['stages'] ?? []);
            if (count($stages) < self::MIN_STAGES) {
                continue;
            }

            $suggestions[] = [
                'name' => $name,
                'description' => $this->sanitizeName($item['description'] ?? null, '', self::MAX_DESCRIPTION_LEN),
                'color' => $this->normalizeHex($item['color'] ?? null, self::FUNNEL_PALETTE[$index % count(self::FUNNEL_PALETTE)]),
                'rationale' => $this->sanitizeName($item['rationale'] ?? null, '', self::MAX_RATIONALE_LEN),
                'stages' => $stages,
            ];

            if (count($suggestions) >= self::TARGET_VARIANTS) {
                break;
            }
        }

        if (count($suggestions) < self::MIN_VARIANTS) {
            throw new RuntimeException('AI не смог сформировать достаточно вариантов. Уточните ответы онбординга.');
        }

        return ['suggestions' => $suggestions];
    }

    /**
     * @return list<array{name: string, color: string}>
     */
    private function normalizeStages(mixed $rawStages): array
    {
        if (! is_array($rawStages)) {
            return [];
        }

        $stages = [];
        $seenNames = [];

        foreach (array_values($rawStages) as $index => $stage) {
            if (! is_array($stage)) {
                continue;
            }

            $stageName = $this->sanitizeName($stage['name'] ?? null, '', self::MAX_STAGE_NAME_LEN);
            if ($stageName === '') {
                continue;
            }

            $key = mb_strtolower($stageName);
            if (isset($seenNames[$key])) {
                continue;
            }
            $seenNames[$key] = true;

            $fallbackColor = self::STAGE_PALETTE[$index % count(self::STAGE_PALETTE)];
            $stages[] = [
                'name' => $stageName,
                'color' => $this->normalizeHex($stage['color'] ?? null, $fallbackColor),
            ];

            if (count($stages) >= self::MAX_STAGES) {
                break;
            }
        }

        return $stages;
    }

    private function sanitizeName(mixed $value, string $fallback, int $maxLength): string
    {
        if (! is_string($value)) {
            return $fallback;
        }

        $clean = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($clean === '') {
            return $fallback;
        }

        return mb_substr($clean, 0, $maxLength);
    }

    private function normalizeHex(mixed $value, string $fallback): string
    {
        if (! is_string($value)) {
            return $fallback;
        }

        $hex = strtolower(trim($value));
        if (! str_starts_with($hex, '#')) {
            $hex = '#'.$hex;
        }

        if (preg_match('/^#[0-9a-f]{6}$/', $hex) !== 1) {
            return $fallback;
        }

        return $hex;
    }
}
