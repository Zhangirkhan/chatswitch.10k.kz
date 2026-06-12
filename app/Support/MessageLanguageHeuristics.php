<?php

declare(strict_types=1);

namespace App\Support;

final class MessageLanguageHeuristics
{
    public const LANG_RU = 'ru';

    public const LANG_KK = 'kk';

    public const LANG_EN = 'en';

    public const LANG_ZH = 'zh';

    public const LANG_TR = 'tr';

    public const LANG_AR = 'ar';

    /** @var list<string> */
    public const SUPPORTED = [
        self::LANG_RU,
        self::LANG_KK,
        self::LANG_EN,
        self::LANG_ZH,
        self::LANG_TR,
        self::LANG_AR,
    ];

    /** @var array<string, string> */
    public const LABELS = [
        self::LANG_RU => 'русский',
        self::LANG_KK => 'казахский',
        self::LANG_EN => 'английский',
        self::LANG_ZH => 'китайский',
        self::LANG_TR => 'турецкий',
        self::LANG_AR => 'арабский',
    ];

    private const MIN_SAMPLE_LEN = 3;

    /**
     * @param  list<string|null>  $samples
     */
    public static function detectFromSamples(array $samples): ?string
    {
        /** @var array<string, int> $scores */
        $scores = array_fill_keys(self::SUPPORTED, 0);

        foreach ($samples as $raw) {
            $text = self::sampleText(is_string($raw) ? $raw : '');
            if (self::letterCount($text) < self::MIN_SAMPLE_LEN) {
                continue;
            }

            if (self::isLikelyKazakh($text)) {
                $scores[self::LANG_KK] += 3;
            } elseif (self::isLikelyRussian($text)) {
                $scores[self::LANG_RU] += 2;
            } elseif (self::isLikelyEnglish($text)) {
                $scores[self::LANG_EN] += 2;
            } elseif (self::isLikelyArabic($text)) {
                $scores[self::LANG_AR] += 2;
            } elseif (self::isLikelyChinese($text)) {
                $scores[self::LANG_ZH] += 2;
            } elseif (self::isLikelyTurkish($text)) {
                $scores[self::LANG_TR] += 2;
            }
        }

        arsort($scores);
        $bestLang = array_key_first($scores);
        $bestScore = $bestLang !== null ? ($scores[$bestLang] ?? 0) : 0;

        return $bestScore > 0 ? $bestLang : null;
    }

    public static function matches(string $lang, string $raw): bool
    {
        $text = self::sampleText($raw);
        if (self::letterCount($text) < self::MIN_SAMPLE_LEN) {
            return true;
        }

        return match ($lang) {
            self::LANG_RU => self::isLikelyRussian($text),
            self::LANG_KK => self::isLikelyKazakh($text),
            self::LANG_EN => self::isLikelyEnglish($text),
            self::LANG_TR => self::isLikelyTurkish($text),
            self::LANG_AR => self::isLikelyArabic($text),
            self::LANG_ZH => self::isLikelyChinese($text),
            default => false,
        };
    }

    public static function sampleText(string $raw): string
    {
        $text = preg_replace('/\*[^*\n]+\*\s*/u', '', $raw) ?? $raw;
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

        return $text;
    }

    private static function letterCount(string $text): int
    {
        $counts = self::countScripts($text);

        return $counts['cyrillic'] + $counts['latin'] + $counts['arabic'] + $counts['cjk'] + $counts['other'];
    }

    /**
     * @return array{cyrillic: int, latin: int, arabic: int, cjk: int, other: int}
     */
    private static function countScripts(string $text): array
    {
        $counts = [
            'cyrillic' => 0,
            'latin' => 0,
            'arabic' => 0,
            'cjk' => 0,
            'other' => 0,
        ];

        $length = mb_strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1);
            if (preg_match('/\s/u', $char) === 1 || preg_match('/\d/u', $char) === 1) {
                continue;
            }
            if (preg_match('/\p{L}/u', $char) !== 1) {
                continue;
            }
            if (preg_match('/\p{Script=Cyrillic}/u', $char) === 1) {
                $counts['cyrillic']++;
            } elseif (preg_match('/\p{Script=Latin}/u', $char) === 1) {
                $counts['latin']++;
            } elseif (preg_match('/\p{Script=Arabic}/u', $char) === 1) {
                $counts['arabic']++;
            } elseif (preg_match('/\p{Script=Han}|\p{Script=Hiragana}|\p{Script=Katakana}/u', $char) === 1) {
                $counts['cjk']++;
            } else {
                $counts['other']++;
            }
        }

        return $counts;
    }

    private static function isLikelyRussian(string $text): bool
    {
        if (KazakhInformalTextDetector::matches($text)) {
            return false;
        }

        $counts = self::countScripts($text);
        if ($counts['cyrillic'] < self::MIN_SAMPLE_LEN) {
            return false;
        }

        return $counts['cyrillic'] >= $counts['latin'];
    }

    private static function isLikelyKazakh(string $text): bool
    {
        return KazakhInformalTextDetector::matches($text);
    }

    private static function isLikelyEnglish(string $text): bool
    {
        $counts = self::countScripts($text);
        if ($counts['latin'] < self::MIN_SAMPLE_LEN) {
            return false;
        }

        return $counts['latin'] > $counts['cyrillic']
            && preg_match('/[ğşıöüç]/u', $text) !== 1;
    }

    private static function isLikelyTurkish(string $text): bool
    {
        return preg_match('/[ğşıöüç]/u', $text) === 1
            || preg_match('/[ıİ]/u', $text) === 1;
    }

    private static function isLikelyArabic(string $text): bool
    {
        $counts = self::countScripts($text);

        return $counts['arabic'] >= self::MIN_SAMPLE_LEN && $counts['arabic'] >= $counts['latin'];
    }

    private static function isLikelyChinese(string $text): bool
    {
        $counts = self::countScripts($text);

        return $counts['cjk'] >= self::MIN_SAMPLE_LEN;
    }
}
