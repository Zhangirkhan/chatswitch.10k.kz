<?php

declare(strict_types=1);

namespace App\Services\AI\Locale;

use App\Models\LocaleFewShotExample;
use App\Services\AI\OpenAiEmbeddingService;
use App\Support\VectorCosine;
use Illuminate\Support\Facades\Log;
use Throwable;

final class LocaleFewShotRetriever
{
    public function __construct(
        private readonly OpenAiEmbeddingService $embeddings,
        private readonly KazakhstanLocaleDetector $detector,
    ) {}

    /**
     * @return list<array{user: string, assistant: string, score: float}>
     */
    public function retrieve(string $userText, KazakhstanLocaleProfile $profile, ?int $companyId = null): array
    {
        if (! config('locale_assistant.few_shot.enabled', true)) {
            return [];
        }

        $limit = max(3, min(8, (int) config('locale_assistant.few_shot.count', 5)));

        $dbExamples = $this->retrieveFromDatabase($userText, $profile, $companyId, $limit);
        if ($dbExamples !== []) {
            return $dbExamples;
        }

        return $this->retrieveFromSeedFile($userText, $profile, $limit);
    }

    public function formatExamplesBlock(array $examples): string
    {
        if ($examples === []) {
            return '';
        }

        $lines = ['Примеры удачных ответов в похожем стиле (ориентир, не копируй дословно):'];
        foreach ($examples as $index => $example) {
            $n = $index + 1;
            $lines[] = "Пример {$n}:";
            $lines[] = 'Клиент: '.($example['user'] ?? '');
            $lines[] = 'Ответ: '.($example['assistant'] ?? '');
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @return list<array{user: string, assistant: string, score: float}>
     */
    private function retrieveFromDatabase(
        string $userText,
        KazakhstanLocaleProfile $profile,
        ?int $companyId,
        int $limit,
    ): array {
        if ((string) config('services.openai.api_key') === '') {
            return [];
        }

        $query = LocaleFewShotExample::query()->whereNotNull('embedding');
        if ($companyId !== null) {
            $query->where(function ($q) use ($companyId): void {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            });
        }

        $candidates = $query->get();
        if ($candidates->isEmpty()) {
            return [];
        }

        try {
            $queryVector = $this->embeddings->embed($userText, new \App\Services\AI\AiUsageOptions('rag_embed', $companyId));
        } catch (Throwable $e) {
            Log::warning('[locale-few-shot] embedding failed', ['error' => $e->getMessage()]);

            return [];
        }

        if ($queryVector === []) {
            return [];
        }

        $minSimilarity = (float) config('locale_assistant.few_shot.min_similarity', 0.25);
        $scored = [];

        foreach ($candidates as $example) {
            $vector = $example->embedding;
            if (! is_array($vector) || $vector === []) {
                continue;
            }

            if (! $this->profileCompatible($profile, $example->language_profile, $example->formality)) {
                continue;
            }

            $score = VectorCosine::similarity(
                $queryVector,
                array_map(static fn ($v): float => (float) $v, $vector),
            );

            if ($score < $minSimilarity) {
                continue;
            }

            $scored[] = [
                'user' => (string) $example->user_text,
                'assistant' => (string) $example->assistant_text,
                'score' => $score,
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * @return list<array{user: string, assistant: string, score: float}>
     */
    private function retrieveFromSeedFile(string $userText, KazakhstanLocaleProfile $profile, int $limit): array
    {
        $path = (string) (config('locale_assistant.few_shot.seed_path') ?: resource_path('locale/examples/few_shot_seed.jsonl'));
        if (! is_readable($path)) {
            return [];
        }

        $scored = [];
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            try {
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }

            if (! is_array($row)) {
                continue;
            }

            $pair = $this->extractPairFromMessages($row['messages'] ?? []);
            if ($pair === null) {
                continue;
            }

            $exampleProfile = $this->detector->detect($pair['user']);
            if (! $this->profileCompatible($profile, $exampleProfile->toArray(), $exampleProfile->formality)) {
                continue;
            }

            $score = $this->lexicalSimilarity($userText, $pair['user']);
            $scored[] = [
                'user' => $pair['user'],
                'assistant' => $pair['assistant'],
                'score' => $score,
            ];
        }

        fclose($handle);

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * @param  list<array{role?: string, content?: string}>|mixed  $messages
     * @return array{user: string, assistant: string}|null
     */
    private function extractPairFromMessages(mixed $messages): ?array
    {
        if (! is_array($messages)) {
            return null;
        }

        $user = '';
        $assistant = '';
        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }
            $role = $message['role'] ?? '';
            $content = trim((string) ($message['content'] ?? ''));
            if ($role === 'user' && $content !== '') {
                $user = $content;
            }
            if ($role === 'assistant' && $content !== '') {
                $assistant = $content;
            }
        }

        if ($user === '' || $assistant === '') {
            return null;
        }

        return ['user' => $user, 'assistant' => $assistant];
    }

    /**
     * @param  array<string, mixed>|null  $storedProfile
     */
    private function profileCompatible(
        KazakhstanLocaleProfile $query,
        ?array $storedProfile,
        ?string $storedFormality,
    ): bool {
        $storedDominant = is_array($storedProfile) ? (string) ($storedProfile['dominant'] ?? '') : '';

        if ($storedDominant === '' || $storedDominant === $query->dominant) {
            return true;
        }

        $mixedGroup = [
            KazakhstanLocaleProfile::DOMINANT_MIXED,
            KazakhstanLocaleProfile::DOMINANT_TRANSLIT_MIXED,
        ];

        if (
            in_array($query->dominant, $mixedGroup, true)
            && in_array($storedDominant, $mixedGroup, true)
        ) {
            return true;
        }

        if ($query->confidence === KazakhstanLocaleProfile::CONFIDENCE_LOW) {
            return true;
        }

        if ($storedFormality !== null && $storedFormality === $query->formality) {
            return true;
        }

        return false;
    }

    private function lexicalSimilarity(string $a, string $b): float
    {
        $aTokens = array_flip($this->tokenize(mb_strtolower($a)));
        $bTokens = $this->tokenize(mb_strtolower($b));
        if ($bTokens === []) {
            return 0.0;
        }

        $overlap = 0;
        foreach ($bTokens as $token) {
            if (isset($aTokens[$token])) {
                $overlap++;
            }
        }

        return $overlap / count($bTokens);
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $parts = preg_split('/\s+/u', trim($normalized), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? array_values($parts) : [];
    }
}
