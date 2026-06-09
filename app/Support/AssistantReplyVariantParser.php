<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Разбирает ответ AI-ассистента с формулировками «Вариант 1: …» на intro + кнопки.
 */
final class AssistantReplyVariantParser
{
    private const VARIANT_HEADER_PATTERN = '/(?:^|[\n\r]+)\s*(?:[-•*]\s*)?(?:\*{1,2})?\s*(?:вариант|variant|option|нұсқа)\s*(\d+|[a-zа-я])\s*[:—.]?\s*(?:\*{1,2})?\s*/iu';

    /**
     * @return array{intro: string, variants: list<array{label: string, text: string}>}|null
     */
    public function parse(string $content): ?array
    {
        $normalized = preg_replace("/\r\n?/", "\n", trim($content)) ?? trim($content);
        if ($normalized === '') {
            return null;
        }

        if (! preg_match_all(self::VARIANT_HEADER_PATTERN, $normalized, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $labels = $matches[1];
        $headers = $matches[0];
        $count = count($headers);
        if ($count === 0) {
            return null;
        }

        $firstHeaderOffset = $headers[0][1];
        $intro = trim(substr($normalized, 0, $firstHeaderOffset));

        $variants = [];
        for ($i = 0; $i < $count; $i++) {
            $header = $headers[$i][0];
            $headerOffset = $headers[$i][1];
            $start = $headerOffset + strlen($header);
            $end = $i + 1 < $count ? $headers[$i + 1][1] : strlen($normalized);
            $raw = trim(substr($normalized, $start, $end - $start));
            $line = trim(strtok($raw, "\n") ?: $raw);
            $text = $this->extractVariantText($line);

            if ($text === '') {
                continue;
            }

            $variants[] = [
                'label' => (string) $labels[$i][0],
                'text' => $text,
            ];
        }

        if ($variants === []) {
            return null;
        }

        return [
            'intro' => $intro,
            'variants' => $variants,
        ];
    }

    private function extractVariantText(string $raw): string
    {
        $text = trim($raw);
        if ($text === '') {
            return '';
        }

        if (preg_match('/^«([^»]+)»/u', $text, $match) === 1) {
            return trim($match[1]);
        }

        if (preg_match('/^["\']([^"\']+)["\']/u', $text, $match) === 1) {
            return trim($match[1]);
        }

        return $this->stripOuterQuotes($text);
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
