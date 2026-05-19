<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Извлекает из русскоязычного текста, за сколько до визита клиент просит напоминание.
 */
final class ReminderLeadTimeParser
{
    private const MIN_MINUTES = 5;

    private const MAX_MINUTES = 10080;

    public function parseFromText(string $text): ?int
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return null;
        }

        if (! $this->mentionsReminder($text)) {
            return null;
        }

        if (preg_match('/(?:за|через)\s+полтора\s+час/u', $text)) {
            return $this->clamp(90);
        }

        if (preg_match('/(?:за|через)\s+полчаса/u', $text)) {
            return $this->clamp(30);
        }

        if (preg_match('/(?:за|через)\s+час(?:\s|$|[,.!?])/u', $text)
            && ! preg_match('/(?:за|через)\s+\d+\s*час/u', $text)) {
            return $this->clamp(60);
        }

        if (preg_match('/(?:за|через)\s+(\d+(?:[.,]\d+)?)\s*(?:час(?:а|ов)?|ч\.?)/u', $text, $matches)) {
            $hours = (float) str_replace(',', '.', $matches[1]);

            return $this->clamp((int) round($hours * 60));
        }

        if (preg_match('/(?:за|через)\s+(\d+)\s*(?:мин(?:ут(?:а|ы)?)?|минут\.?|м\.?)/u', $text, $matches)) {
            return $this->clamp((int) $matches[1]);
        }

        return null;
    }

    private function mentionsReminder(string $text): bool
    {
        $markers = [
            'предупред',
            'напомн',
            'напомин',
            'напишите за',
            'напиши за',
            'за час до',
            'за 2 час',
            'за два час',
            'за полчас',
            'за 30 мин',
            'за 45 мин',
        ];

        foreach ($markers as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return preg_match('/(?:за|через)\s+\d+\s*(?:час|мин)/u', $text) === 1;
    }

    private function clamp(int $minutes): int
    {
        return min(self::MAX_MINUTES, max(self::MIN_MINUTES, $minutes));
    }
}
