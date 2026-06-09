<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Извлекает готовый текст для клиента из ответа AI-ассистента (без intro и пояснений).
 */
final class AssistantClientDraftExtractor
{
    private const MIN_GUILLEMET_LENGTH = 12;

    /**
     * @param  array{intro: string, variants: list<array{label: string, text: string}>}|null  $variantPayload
     */
    public function extract(string $content, ?array $variantPayload = null): string
    {
        $normalized = preg_replace("/\r\n?/", "\n", trim($content)) ?? trim($content);
        if ($normalized === '') {
            return '';
        }

        if ($variantPayload !== null && ($variantPayload['variants'] ?? []) !== []) {
            $first = trim((string) ($variantPayload['variants'][0]['text'] ?? ''));
            if ($first !== '') {
                return $first;
            }
        }

        $afterIntro = $this->extractAfterIntroHint($normalized);
        if ($afterIntro !== null) {
            return $afterIntro;
        }

        $longest = $this->extractLongestGuillemetBlock($normalized);
        if ($longest !== null) {
            return $longest;
        }

        return $this->stripOuterQuotes($normalized);
    }

    private function extractAfterIntroHint(string $content): ?string
    {
        $pattern = '/(?:лучше\s+так|готовый\s+(?:ответ|текст)|можно\s+(?:ответить|так)|ответ\s+клиенту)\s*:?\s*\n?\s*«([^»]+)»/iu';

        if (preg_match($pattern, $content, $match) !== 1) {
            return null;
        }

        $text = trim($match[1]);

        return $text !== '' ? $text : null;
    }

    private function extractLongestGuillemetBlock(string $content): ?string
    {
        if (preg_match_all('/«([^»]+)»/u', $content, $matches) !== false && $matches[1] !== []) {
            $best = '';
            foreach ($matches[1] as $candidate) {
                $text = trim($candidate);
                if (strlen($text) < self::MIN_GUILLEMET_LENGTH) {
                    continue;
                }
                if (strlen($text) > strlen($best)) {
                    $best = $text;
                }
            }

            if ($best !== '') {
                return $best;
            }
        }

        return null;
    }

    private function stripOuterQuotes(string $text): string
    {
        $result = trim($text);
        $pairs = [
            ['«', '»'],
            ['"', '"'],
            ["'", "'"],
            ['„', '"'],
        ];

        foreach ($pairs as [$open, $close]) {
            if (str_starts_with($result, $open) && str_ends_with($result, $close)) {
                $result = trim(substr($result, strlen($open), -strlen($close)));

                break;
            }
        }

        return trim($result, " \t\n\r\0\x0B«»\"'„");
    }
}
