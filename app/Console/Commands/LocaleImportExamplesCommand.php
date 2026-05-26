<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LocaleFewShotExample;
use App\Models\LocalePhraseChunk;
use App\Services\AI\Locale\KazakhstanLocaleDetector;
use Illuminate\Console\Command;
use Throwable;

final class LocaleImportExamplesCommand extends Command
{
    protected $signature = 'locale:import-examples
        {path : JSONL file path}
        {--company= : Company ID (optional)}
        {--phrases : Import as slang phrases instead of few-shot pairs}';

    protected $description = 'Import locale few-shot examples or slang phrases from JSONL';

    public function handle(KazakhstanLocaleDetector $detector): int
    {
        $path = (string) $this->argument('path');
        if (! is_readable($path)) {
            $this->error("File not readable: {$path}");

            return self::FAILURE;
        }

        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $asPhrases = (bool) $this->option('phrases');
        $imported = 0;
        $skipped = 0;

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->error('Cannot open file.');

            return self::FAILURE;
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            try {
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $skipped++;

                continue;
            }

            if (! is_array($row)) {
                $skipped++;

                continue;
            }

            if ($asPhrases) {
                if ($this->importPhrase($row, $companyId)) {
                    $imported++;
                } else {
                    $skipped++;
                }

                continue;
            }

            if ($this->importFewShot($row, $companyId, $detector)) {
                $imported++;
            } else {
                $skipped++;
            }
        }

        fclose($handle);

        $this->info("Imported: {$imported}, skipped: {$skipped}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function importFewShot(array $row, ?int $companyId, KazakhstanLocaleDetector $detector): bool
    {
        $pair = $this->extractPair($row['messages'] ?? []);
        if ($pair === null) {
            return false;
        }

        $profile = $detector->detect($pair['user']);

        LocaleFewShotExample::query()->create([
            'company_id' => $companyId,
            'user_text' => $pair['user'],
            'assistant_text' => $pair['assistant'],
            'language_profile' => $profile->toArray(),
            'formality' => $profile->formality,
            'tags' => is_array($row['tags'] ?? null) ? $row['tags'] : [],
            'source' => (string) ($row['source'] ?? 'import'),
            'quality_score' => (int) ($row['quality_score'] ?? 80),
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function importPhrase(array $row, ?int $companyId): bool
    {
        $phrase = trim((string) ($row['phrase'] ?? ''));
        if ($phrase === '') {
            return false;
        }

        $hash = hash('sha256', mb_strtolower($phrase).':'.($companyId ?? 'global'));

        LocalePhraseChunk::query()->updateOrCreate(
            ['company_id' => $companyId, 'content_hash' => $hash],
            [
                'phrase' => $phrase,
                'meaning_ru' => (string) ($row['meaning_ru'] ?? ''),
                'usage_hint' => (string) ($row['usage_hint'] ?? ''),
                'language_tags' => is_array($row['language_tags'] ?? null) ? $row['language_tags'] : [],
                'source' => (string) ($row['source'] ?? 'import'),
            ],
        );

        return true;
    }

    /**
     * @return array{user: string, assistant: string}|null
     */
    private function extractPair(mixed $messages): ?array
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
}
