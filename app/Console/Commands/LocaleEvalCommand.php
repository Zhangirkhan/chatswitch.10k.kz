<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AI\Locale\KazakhstanLocaleDetector;
use Illuminate\Console\Command;
use Throwable;

final class LocaleEvalCommand extends Command
{
    protected $signature = 'locale:eval {--path= : Benchmark JSONL path}';

    protected $description = 'Evaluate locale detector against benchmark cases';

    public function handle(KazakhstanLocaleDetector $detector): int
    {
        $path = (string) ($this->option('path') ?: config('locale_assistant.benchmark_path'));
        if (! is_readable($path)) {
            $this->error("Benchmark not found: {$path}");

            return self::FAILURE;
        }

        $total = 0;
        $dominantHits = 0;
        $formalityHits = 0;
        $failures = [];

        $handle = fopen($path, 'rb');
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            try {
                $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }

            if (! is_array($case)) {
                continue;
            }

            $input = (string) ($case['input'] ?? '');
            $expectedDominant = (string) ($case['expected_dominant'] ?? '');
            $expectedFormality = (string) ($case['expected_formality'] ?? '');

            if ($input === '' || $expectedDominant === '') {
                continue;
            }

            $total++;
            $profile = $detector->detect($input);

            if ($this->dominantMatches($expectedDominant, $profile->dominant)) {
                $dominantHits++;
            } else {
                $failures[] = [
                    'id' => $case['id'] ?? $input,
                    'expected' => $expectedDominant,
                    'got' => $profile->dominant,
                ];
            }

            if ($expectedFormality === '' || $profile->formality === $expectedFormality) {
                $formalityHits++;
            }
        }

        fclose($handle);

        $dominantPct = $total > 0 ? round(100 * $dominantHits / $total, 1) : 0;
        $formalityPct = $total > 0 ? round(100 * $formalityHits / $total, 1) : 0;

        $this->info("Cases: {$total}");
        $this->info("Dominant language accuracy: {$dominantPct}% ({$dominantHits}/{$total})");
        $this->info("Formality accuracy: {$formalityPct}% ({$formalityHits}/{$total})");

        if ($failures !== []) {
            $this->warn('Mismatches:');
            foreach (array_slice($failures, 0, 10) as $failure) {
                $this->line("  [{$failure['id']}] expected={$failure['expected']} got={$failure['got']}");
            }
        }

        return $dominantPct >= 70 ? self::SUCCESS : self::FAILURE;
    }

    private function dominantMatches(string $expected, string $actual): bool
    {
        if ($expected === $actual) {
            return true;
        }

        $mixedGroup = ['mixed', 'kk', 'translit_mixed'];
        if ($expected === 'kk' && in_array($actual, $mixedGroup, true)) {
            return true;
        }

        if ($expected === 'mixed' && in_array($actual, ['mixed', 'kk', 'ru', 'translit_mixed'], true)) {
            return true;
        }

        if ($expected === 'translit_mixed' && $actual === 'translit_mixed') {
            return true;
        }

        if ($expected === 'unknown' && $actual === 'unknown') {
            return true;
        }

        return false;
    }
}
