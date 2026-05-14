<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\CompanyToneProfile;
use App\Models\EmployeeToneProfile;
use App\Models\Message;
use App\Models\User;
use App\Support\OperatorSignature;
use Illuminate\Support\Str;

final class ToneProfileAnalyzer
{
    private const SAMPLE_CHUNK_CHARS = 18000;

    public function __construct(
        private readonly OpenAiChatService $openAi,
    ) {}

    public function analyze(User $user, int $companyId, ?Chat $chat = null): EmployeeToneProfile
    {
        $samples = $this->samples($user, $companyId, $chat);

        if ($samples === []) {
            return EmployeeToneProfile::updateOrCreate(
                ['company_id' => $companyId, 'user_id' => $user->id],
                [
                    'summary' => 'Недостаточно исходящих сообщений. Использовать нейтральный, вежливый и краткий стиль.',
                    'phrases' => [],
                    'metadata' => ['samples_count' => 0, 'source' => 'fallback', 'chat_id' => $chat?->id],
                    'analyzed_at' => now(),
                ],
            );
        }

        $parsed = $this->analyzeSamples($samples);

        return EmployeeToneProfile::updateOrCreate(
            ['company_id' => $companyId, 'user_id' => $user->id],
            [
                'summary' => $parsed['summary'],
                'phrases' => $parsed['phrases'],
                'metadata' => ['samples_count' => count($samples), 'source' => 'openai', 'chat_id' => $chat?->id],
                'analyzed_at' => now(),
            ],
        );
    }

    public function analyzeCompany(int $companyId): CompanyToneProfile
    {
        $samples = $this->companySamples($companyId);

        if ($samples === []) {
            return CompanyToneProfile::updateOrCreate(
                ['company_id' => $companyId],
                [
                    'summary' => 'Недостаточно ручных исходящих сообщений компании. Использовать нейтральный, вежливый и краткий стиль.',
                    'phrases' => [],
                    'metadata' => ['samples_count' => 0, 'source' => 'fallback'],
                    'analyzed_at' => now(),
                ],
            );
        }

        $parsed = $this->analyzeCompanySamples($samples);

        return CompanyToneProfile::updateOrCreate(
            ['company_id' => $companyId],
            [
                'summary' => $parsed['summary'],
                'phrases' => $parsed['phrases'],
                'metadata' => ['samples_count' => count($samples), 'source' => 'openai'],
                'analyzed_at' => now(),
            ],
        );
    }

    /** @return list<string> */
    private function samples(User $user, int $companyId, ?Chat $chat): array
    {
        $query = Message::query()
            ->where('sent_by_user_id', $user->id)
            ->where('direction', 'outbound')
            ->whereNotNull('body')
            ->where(function ($query): void {
                $query->whereNull('metadata->ai->generated')
                    ->orWhere('metadata->ai->generated', false);
            })
            ->whereHas('chat', fn ($query) => $query->where('company_id', $companyId));

        if ($chat !== null) {
            $query->where('chat_id', $chat->id);
        }

        return $query
            ->orderBy('message_timestamp')
            ->orderBy('id')
            ->pluck('body')
            ->map(fn ($body) => trim(OperatorSignature::strip((string) $body)))
            ->filter(fn (string $body) => $body !== '')
            ->map(fn (string $body) => Str::limit($body, 500, '...'))
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function companySamples(int $companyId): array
    {
        return Message::query()
            ->where('direction', 'outbound')
            ->whereNotNull('sent_by_user_id')
            ->whereNotNull('body')
            ->where(function ($query): void {
                $query->whereNull('metadata->ai->generated')
                    ->orWhere('metadata->ai->generated', false);
            })
            ->whereHas('chat', fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('message_timestamp')
            ->orderBy('id')
            ->pluck('body')
            ->map(fn ($body) => trim(OperatorSignature::strip((string) $body)))
            ->filter(fn (string $body) => $body !== '')
            ->map(fn (string $body) => Str::limit($body, 500, '...'))
            ->values()
            ->all();
    }

    /** @return array{summary: string, phrases: list<string>} */
    private function parseProfileJson(string $raw): array
    {
        $json = trim($raw);
        if (preg_match('/```json\s*(.*?)```/is', $json, $match)) {
            $json = trim($match[1]);
        }

        $data = json_decode($json, true);
        if (! is_array($data)) {
            return [
                'summary' => Str::limit($raw, 1500, '...'),
                'phrases' => [],
            ];
        }

        $phrases = [];
        foreach (($data['phrases'] ?? []) as $phrase) {
            if (is_string($phrase) && trim($phrase) !== '') {
                $phrases[] = Str::limit(trim($phrase), 180, '...');
            }
        }

        return [
            'summary' => Str::limit(trim((string) ($data['summary'] ?? 'Нейтральный стиль поддержки.')), 1500, '...'),
            'phrases' => array_slice($phrases, 0, 12),
        ];
    }

    /**
     * @param  list<string>  $samples
     * @return array{summary: string, phrases: list<string>}
     */
    private function analyzeSamples(array $samples): array
    {
        $chunks = $this->chunkSamples($samples);
        if (count($chunks) === 1) {
            return $this->analyzeChunk($chunks[0]);
        }

        $partials = [];
        foreach ($chunks as $chunk) {
            $partials[] = $this->analyzeChunk($chunk);
        }

        $summaryInput = collect($partials)
            ->map(fn (array $partial) => $partial['summary'].' Типичные фразы: '.implode('; ', $partial['phrases']))
            ->implode("\n");

        return $this->parseProfileJson($this->openAi->chat([
            ['role' => 'system', 'content' => 'Ты объединяешь частичные профили тона сотрудника поддержки. Верни только валидный JSON с summary и phrases.'],
            ['role' => 'user', 'content' => $summaryInput],
        ], 0.2, 700));
    }

    /**
     * @param  list<string>  $samples
     * @return array{summary: string, phrases: list<string>}
     */
    private function analyzeChunk(array $samples): array
    {
        $prompt = "Проанализируй стиль сотрудника по всем примерам сообщений ниже. Не сглаживай живой стиль до корпоративного шаблона: если сотрудник пишет коротко, сленгом, с ошибками или разговорными словами, прямо отрази это в summary и phrases. Верни JSON с полями summary (строка) и phrases (массив до 12 коротких дословных типичных формулировок). Не включай персональные данные.\n\nПримеры:\n- ".implode("\n- ", $samples);
        $raw = $this->openAi->chat([
            ['role' => 'system', 'content' => 'Ты анализируешь тон сообщений поддержки и возвращаешь только валидный JSON.'],
            ['role' => 'user', 'content' => $prompt],
        ], 0.2, 700);

        return $this->parseProfileJson($raw);
    }

    /**
     * @param  list<string>  $samples
     * @return array{summary: string, phrases: list<string>}
     */
    private function analyzeCompanySamples(array $samples): array
    {
        $chunks = $this->chunkSamples($samples);
        if (count($chunks) === 1) {
            return $this->analyzeCompanyChunk($chunks[0]);
        }

        $partials = [];
        foreach ($chunks as $chunk) {
            $partials[] = $this->analyzeCompanyChunk($chunk);
        }

        $summaryInput = collect($partials)
            ->map(fn (array $partial) => $partial['summary'].' Типичные фразы: '.implode('; ', $partial['phrases']))
            ->implode("\n");

        return $this->parseProfileJson($this->openAi->chat([
            ['role' => 'system', 'content' => 'Ты объединяешь частичные профили общего стиля компании. Верни только валидный JSON с summary и phrases.'],
            ['role' => 'user', 'content' => $summaryInput],
        ], 0.2, 700));
    }

    /**
     * @param  list<string>  $samples
     * @return array{summary: string, phrases: list<string>}
     */
    private function analyzeCompanyChunk(array $samples): array
    {
        $prompt = "Проанализируй общий стиль компании по ручным сообщениям разных сотрудников ниже. Найди повторяющиеся формулировки, уровень формальности, длину ответов, приветствия, обращение к клиенту и типичный тон. Не копируй персональные особенности одного сотрудника, выдели общий корпоративный стиль. Верни JSON с полями summary (строка) и phrases (массив до 12 коротких типичных формулировок). Не включай персональные данные.\n\nПримеры:\n- ".implode("\n- ", $samples);
        $raw = $this->openAi->chat([
            ['role' => 'system', 'content' => 'Ты анализируешь общий стиль поддержки компании и возвращаешь только валидный JSON.'],
            ['role' => 'user', 'content' => $prompt],
        ], 0.2, 700);

        return $this->parseProfileJson($raw);
    }

    /**
     * @param  list<string>  $samples
     * @return list<list<string>>
     */
    private function chunkSamples(array $samples): array
    {
        $chunks = [];
        $current = [];
        $length = 0;

        foreach ($samples as $sample) {
            $sampleLength = mb_strlen($sample) + 3;
            if ($current !== [] && $length + $sampleLength > self::SAMPLE_CHUNK_CHARS) {
                $chunks[] = $current;
                $current = [];
                $length = 0;
            }

            $current[] = $sample;
            $length += $sampleLength;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
