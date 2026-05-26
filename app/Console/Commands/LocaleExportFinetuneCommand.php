<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AI\Locale\KazakhstanLocaleDetector;
use Illuminate\Console\Command;
use Throwable;

final class LocaleExportFinetuneCommand extends Command
{
    protected $signature = 'locale:export-finetune
        {input : Source JSONL}
        {output : Output JSONL for OpenAI fine-tuning}
        {--min-quality=70 : Minimum quality_score if present}';

    protected $description = 'Export validated JSONL for OpenAI fine-tuning';

    public function handle(KazakhstanLocaleDetector $detector): int
    {
        $input = (string) $this->argument('input');
        $output = (string) $this->argument('output');
        $minQuality = (int) $this->option('min-quality');

        if (! is_readable($input)) {
            $this->error("Cannot read: {$input}");

            return self::FAILURE;
        }

        $systemPrompt = trim((string) @file_get_contents(
            (string) config('locale_assistant.system_prompt_path'),
        ));

        if ($systemPrompt === '') {
            $systemPrompt = 'You are a multilingual Kazakhstan assistant.';
        }

        $out = fopen($output, 'wb');
        if ($out === false) {
            $this->error("Cannot write: {$output}");

            return self::FAILURE;
        }

        $exported = 0;
        $skipped = 0;
        $handle = fopen($input, 'rb');

        while ($handle !== false && ($line = fgets($handle)) !== false) {
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

            $quality = (int) ($row['quality_score'] ?? 100);
            if ($quality < $minQuality) {
                $skipped++;

                continue;
            }

            $messages = $row['messages'] ?? null;
            if (! is_array($messages) || $messages === []) {
                $pair = $this->pairFromRow($row);
                if ($pair === null) {
                    $skipped++;

                    continue;
                }
                $messages = [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $pair['user']],
                    ['role' => 'assistant', 'content' => $pair['assistant']],
                ];
            }

            if (! $this->validateMessages($messages)) {
                $skipped++;

                continue;
            }

            $userText = $this->lastUserContent($messages);
            $assistantText = $this->lastAssistantContent($messages);
            if ($userText === '' || $assistantText === '' || $this->isToxic($assistantText)) {
                $skipped++;

                continue;
            }

            $userProfile = $detector->detect($userText);
            $replyProfile = $detector->detect($assistantText);
            if (
                $userProfile->formality === 'formal'
                && $replyProfile->formality === 'casual'
                && $replyProfile->slangScore > 0.5
            ) {
                $skipped++;

                continue;
            }

            fwrite($out, json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE)."\n");
            $exported++;
        }

        if ($handle !== false) {
            fclose($handle);
        }
        fclose($out);

        $this->info("Exported: {$exported}, skipped: {$skipped} → {$output}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{user: string, assistant: string}|null
     */
    private function pairFromRow(array $row): ?array
    {
        $user = trim((string) ($row['user'] ?? ''));
        $assistant = trim((string) ($row['assistant'] ?? ''));

        if ($user !== '' && $assistant !== '') {
            return ['user' => $user, 'assistant' => $assistant];
        }

        return null;
    }

    /**
     * @param  list<array{role?: string, content?: string}>  $messages
     */
    private function validateMessages(array $messages): bool
    {
        $hasUser = false;
        $hasAssistant = false;
        foreach ($messages as $message) {
            $role = $message['role'] ?? '';
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                return false;
            }
            if (! in_array($role, ['system', 'user', 'assistant'], true)) {
                return false;
            }
            if ($role === 'user') {
                $hasUser = true;
            }
            if ($role === 'assistant') {
                $hasAssistant = true;
            }
        }

        return $hasUser && $hasAssistant;
    }

    /**
     * @param  list<array{role?: string, content?: string}>  $messages
     */
    private function lastUserContent(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                return trim((string) ($messages[$i]['content'] ?? ''));
            }
        }

        return '';
    }

    /**
     * @param  list<array{role?: string, content?: string}>  $messages
     */
    private function lastAssistantContent(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'assistant') {
                return trim((string) ($messages[$i]['content'] ?? ''));
            }
        }

        return '';
    }

    private function isToxic(string $text): bool
    {
        $lower = mb_strtolower($text);
        $blocked = ['убей', 'сдохни', 'terror', 'nazi'];

        foreach ($blocked as $word) {
            if (str_contains($lower, $word)) {
                return true;
            }
        }

        return false;
    }
}
