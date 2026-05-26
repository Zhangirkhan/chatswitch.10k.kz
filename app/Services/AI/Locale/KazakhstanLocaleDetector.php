<?php

declare(strict_types=1);

namespace App\Services\AI\Locale;

final class KazakhstanLocaleDetector
{
    public function __construct(
        private readonly LocaleLexiconLoader $lexicons,
    ) {}

    public function detect(string $text, ?string $chatContext = null): KazakhstanLocaleProfile
    {
        $text = trim($text);
        if ($text === '') {
            return $this->neutralProfile();
        }

        $combined = $chatContext !== null && trim($chatContext) !== ''
            ? trim($text)."\n".trim($chatContext)
            : $text;

        $lower = mb_strtolower($text);
        $tokens = $this->tokenize($lower);
        $tokenCount = max(1, count($tokens));

        $ruHits = $this->countLexiconHits($lower, $tokens, $this->lexicons->words('ru_function_words'));
        $kkHits = $this->countLexiconHits($lower, $tokens, $this->lexicons->words('kk_function_words'));
        $slangHits = $this->countLexiconHits($lower, $tokens, $this->lexicons->words('slang_ru_kk', 'terms'));
        $formalHits = $this->countLexiconHits($lower, $tokens, $this->lexicons->words('formal_markers', 'formal'));
        $casualHits = $this->countLexiconHits($lower, $tokens, $this->lexicons->words('formal_markers', 'casual'));

        $kkLetters = $this->countKkCyrillicLetters($combined);
        $cyrillicRatio = $this->cyrillicRatio($combined);
        $latinRatio = $this->latinRatio($combined);

        $script = 'mixed';
        if ($cyrillicRatio >= 0.6) {
            $script = 'cyrillic';
        } elseif ($latinRatio >= 0.6) {
            $script = 'latin';
        }

        $translitKk = $this->countTransliterationHits($lower, 'translit_kk_words');
        $translitRu = $this->countTransliterationHits($lower, 'translit_ru_words');

        $ruScore = $ruHits + ($cyrillicRatio > 0.5 ? 0.5 : 0.0) + $translitRu * 0.5;
        $kkScore = $kkHits + ($kkLetters * 2) + $translitKk * 0.8;

        $total = max(0.01, $ruScore + $kkScore);
        $ruPct = min(1.0, $ruScore / $total);
        $kkPct = min(1.0, $kkScore / $total);

        $mixedThreshold = (float) config('locale_assistant.detection.mixed_threshold', 0.20);
        $dominantThreshold = (float) config('locale_assistant.detection.dominant_threshold', 0.55);

        $dominant = KazakhstanLocaleProfile::DOMINANT_UNKNOWN;
        if ($script === 'latin' && ($translitKk > 0 || $translitRu > 0 || $kkHits > 0)) {
            $dominant = KazakhstanLocaleProfile::DOMINANT_TRANSLIT_MIXED;
        } elseif ($ruPct >= $dominantThreshold && $kkPct < $mixedThreshold) {
            $dominant = KazakhstanLocaleProfile::DOMINANT_RU;
        } elseif ($kkPct >= $dominantThreshold && $ruPct < $mixedThreshold) {
            $dominant = KazakhstanLocaleProfile::DOMINANT_KK;
        } elseif ($ruPct >= $mixedThreshold && $kkPct >= $mixedThreshold) {
            $dominant = KazakhstanLocaleProfile::DOMINANT_MIXED;
        } elseif ($kkLetters >= 2) {
            $dominant = KazakhstanLocaleProfile::DOMINANT_KK;
        } elseif ($ruHits > 0 && $kkHits === 0 && $kkLetters === 0) {
            $dominant = KazakhstanLocaleProfile::DOMINANT_RU;
        } elseif ($kkHits > 0 && $ruHits === 0) {
            $dominant = KazakhstanLocaleProfile::DOMINANT_KK;
        } elseif ($ruHits > 0 || $kkHits > 0) {
            $dominant = KazakhstanLocaleProfile::DOMINANT_MIXED;
        }

        $slangScore = min(1.0, ($slangHits + $casualHits * 0.5) / $tokenCount);

        $formality = KazakhstanLocaleProfile::FORMALITY_NEUTRAL;
        if ($formalHits > $casualHits && $formalHits > 0) {
            $formality = KazakhstanLocaleProfile::FORMALITY_FORMAL;
        } elseif ($casualHits > $formalHits && ($casualHits > 0 || $slangScore >= 0.35)) {
            $formality = KazakhstanLocaleProfile::FORMALITY_CASUAL;
        }

        $shortLimit = (int) config('locale_assistant.detection.short_token_limit', 3);
        $confidence = count($tokens) <= $shortLimit
            ? KazakhstanLocaleProfile::CONFIDENCE_LOW
            : KazakhstanLocaleProfile::CONFIDENCE_HIGH;

        if ($confidence === KazakhstanLocaleProfile::CONFIDENCE_LOW && $formality === KazakhstanLocaleProfile::FORMALITY_CASUAL) {
            $formality = KazakhstanLocaleProfile::FORMALITY_NEUTRAL;
        }

        $allowMixed = in_array($dominant, [
            KazakhstanLocaleProfile::DOMINANT_MIXED,
            KazakhstanLocaleProfile::DOMINANT_TRANSLIT_MIXED,
        ], true) || ($ruPct >= $mixedThreshold && $kkPct >= $mixedThreshold);

        $preferKkCyrillic = $script === 'latin'
            && ($translitKk > 0 || $dominant === KazakhstanLocaleProfile::DOMINANT_KK);

        return new KazakhstanLocaleProfile(
            dominant: $dominant,
            ruPct: round($ruPct, 2),
            kkPct: round($kkPct, 2),
            script: $script,
            formality: $formality,
            slangScore: round($slangScore, 2),
            allowMixedReply: $allowMixed,
            preferKkCyrillic: $preferKkCyrillic,
            confidence: $confidence,
        );
    }

    private function neutralProfile(): KazakhstanLocaleProfile
    {
        return new KazakhstanLocaleProfile(
            dominant: KazakhstanLocaleProfile::DOMINANT_UNKNOWN,
            ruPct: 0.5,
            kkPct: 0.5,
            script: 'mixed',
            formality: KazakhstanLocaleProfile::FORMALITY_NEUTRAL,
            slangScore: 0.0,
            allowMixedReply: false,
            preferKkCyrillic: false,
            confidence: KazakhstanLocaleProfile::CONFIDENCE_LOW,
        );
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

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $lexicon
     */
    private function countLexiconHits(string $haystack, array $tokens, array $lexicon): int
    {
        $hits = 0;
        foreach ($lexicon as $word) {
            if ($word === '') {
                continue;
            }
            if (in_array($word, $tokens, true) || str_contains($haystack, $word)) {
                $hits++;
            }
        }

        return $hits;
    }

    private function countKkCyrillicLetters(string $text): int
    {
        $letters = $this->lexicons->load('kz_letters')['kk_cyrillic'] ?? [];
        $count = 0;
        foreach ($letters as $letter) {
            if (! is_string($letter) || $letter === '') {
                continue;
            }
            $count += mb_substr_count($text, $letter);
        }

        return $count;
    }

    private function cyrillicRatio(string $text): float
    {
        $letters = preg_match_all('/\p{Cyrillic}/u', $text) ?: 0;
        $latin = preg_match_all('/\p{Latin}/u', $text) ?: 0;
        $total = $letters + $latin;

        return $total > 0 ? $letters / $total : 0.0;
    }

    private function latinRatio(string $text): float
    {
        $letters = preg_match_all('/\p{Cyrillic}/u', $text) ?: 0;
        $latin = preg_match_all('/\p{Latin}/u', $text) ?: 0;
        $total = $letters + $latin;

        return $total > 0 ? $latin / $total : 0.0;
    }

    private function countTransliterationHits(string $haystack, string $key): int
    {
        $words = $this->lexicons->load('kz_letters')[$key] ?? [];
        if (! is_array($words)) {
            return 0;
        }

        $hits = 0;
        foreach ($words as $word) {
            if (! is_string($word) || $word === '') {
                continue;
            }
            if (str_contains($haystack, mb_strtolower($word))) {
                $hits++;
            }
        }

        return $hits;
    }
}
