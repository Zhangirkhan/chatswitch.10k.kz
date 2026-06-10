<?php

declare(strict_types=1);

namespace App\Services\AI\Locale;

final class KazakhstanLocaleDetector
{
    public function __construct(
        private readonly LocaleLexiconLoader $lexicons,
    ) {}

    public function detect(string $text): KazakhstanLocaleProfile
    {
        $text = trim($text);
        if ($text === '') {
            return $this->neutralProfile();
        }

        return $this->profileFromScores($text, $this->scoreText($text));
    }

    /**
     * Язык по нескольким последним входящим (новые сообщения важнее).
     *
     * @param  list<string>  $samples  от нового к старому
     */
    public function detectFromSamples(array $samples): KazakhstanLocaleProfile
    {
        $samples = array_values(array_filter(
            array_map(static fn (mixed $sample): string => trim(is_string($sample) ? $sample : ''), $samples),
            static fn (string $sample): bool => $sample !== '',
        ));

        if ($samples === []) {
            return $this->neutralProfile();
        }

        if (count($samples) === 1) {
            return $this->detect($samples[0]);
        }

        /** @var list<float> $weights */
        $weights = [1.0, 0.85, 0.7];
        $ruWeighted = 0.0;
        $kkWeighted = 0.0;
        $totalWeight = 0.0;
        /** @var list<KazakhstanLocaleProfile> $profiles */
        $profiles = [];

        foreach ($samples as $index => $sample) {
            $weight = $weights[$index] ?? 0.6;
            $profile = $this->detect($sample);
            $profiles[] = $profile;
            $ruWeighted += $profile->ruPct * $weight;
            $kkWeighted += $profile->kkPct * $weight;
            $totalWeight += $weight;
        }

        $ruPct = $totalWeight > 0 ? $ruWeighted / $totalWeight : 0.5;
        $kkPct = $totalWeight > 0 ? $kkWeighted / $totalWeight : 0.5;
        $sum = max(0.01, $ruPct + $kkPct);
        $ruPct = min(1.0, $ruPct / $sum);
        $kkPct = min(1.0, $kkPct / $sum);

        $latest = $profiles[0];
        $dominant = $this->resolveDominant(
            $ruPct,
            $kkPct,
            $latest->script,
            $this->scoreText($samples[0]),
        );

        $confidence = abs($ruPct - $kkPct) >= 0.2
            ? KazakhstanLocaleProfile::CONFIDENCE_HIGH
            : KazakhstanLocaleProfile::CONFIDENCE_LOW;

        return new KazakhstanLocaleProfile(
            dominant: $dominant,
            ruPct: round($ruPct, 2),
            kkPct: round($kkPct, 2),
            script: $latest->script,
            formality: $this->mergeFormality($profiles),
            slangScore: round(collect($profiles)->avg(fn (KazakhstanLocaleProfile $profile): float => $profile->slangScore) ?? 0.0, 2),
            allowMixedReply: $dominant === KazakhstanLocaleProfile::DOMINANT_MIXED
                || ($ruPct >= 0.20 && $kkPct >= 0.20 && abs($ruPct - $kkPct) < 0.35),
            preferKkCyrillic: $latest->preferKkCyrillic || $kkPct > $ruPct,
            confidence: $confidence,
        );
    }

    /**
     * @return array{
     *     ruHits: int,
     *     kkHits: int,
     *     kkLetters: int,
     *     kkPlain: int,
     *     cyrillicRatio: float,
     *     latinRatio: float,
     *     script: string,
     *     translitKk: int,
     *     translitRu: int,
     *     ruScore: float,
     *     kkScore: float,
     *     ruPct: float,
     *     kkPct: float,
     *     tokenCount: int,
     *     slangHits: int,
     *     formalHits: int,
     *     casualHits: int,
     * }
     */
    private function scoreText(string $text): array
    {
        $lower = mb_strtolower($text);
        $tokens = $this->tokenize($lower);
        $tokenCount = max(1, count($tokens));

        $ruHits = $this->countLexiconHits($lower, $tokens, $this->lexicons->words('ru_function_words'));
        $kkHits = $this->countLexiconHits($lower, $tokens, $this->lexicons->words('kk_function_words'));
        $slangHits = $this->countLexiconHits($lower, $tokens, $this->lexicons->words('slang_ru_kk', 'terms'));
        $formalHits = $this->countLexiconHits($lower, $tokens, $this->lexicons->words('formal_markers', 'formal'));
        $casualHits = $this->countLexiconHits($lower, $tokens, $this->lexicons->words('formal_markers', 'casual'));

        $kkLetters = $this->countKkCyrillicLetters($text);
        $kkPlain = $this->countKkPlainMarkers($lower);
        $cyrillicRatio = $this->cyrillicRatio($text);
        $latinRatio = $this->latinRatio($text);

        $script = 'mixed';
        if ($cyrillicRatio >= 0.6) {
            $script = 'cyrillic';
        } elseif ($latinRatio >= 0.6) {
            $script = 'latin';
        }

        $translitKk = $this->countTransliterationHits($lower, 'translit_kk_words');
        $translitRu = $this->countTransliterationHits($lower, 'translit_ru_words');

        $ruScore = $ruHits + ($cyrillicRatio > 0.5 ? 0.5 : 0.0) + $translitRu * 0.5;
        $kkScore = $kkHits + ($kkLetters * 2) + ($kkPlain * 1.5) + $translitKk * 0.8;

        $total = max(0.01, $ruScore + $kkScore);
        $ruPct = min(1.0, $ruScore / $total);
        $kkPct = min(1.0, $kkScore / $total);

        return [
            'ruHits' => $ruHits,
            'kkHits' => $kkHits,
            'kkLetters' => $kkLetters,
            'kkPlain' => $kkPlain,
            'cyrillicRatio' => $cyrillicRatio,
            'latinRatio' => $latinRatio,
            'script' => $script,
            'translitKk' => $translitKk,
            'translitRu' => $translitRu,
            'ruScore' => $ruScore,
            'kkScore' => $kkScore,
            'ruPct' => $ruPct,
            'kkPct' => $kkPct,
            'tokenCount' => $tokenCount,
            'slangHits' => $slangHits,
            'formalHits' => $formalHits,
            'casualHits' => $casualHits,
        ];
    }

    /**
     * @param  array<string, mixed>  $scores
     */
    private function profileFromScores(string $text, array $scores): KazakhstanLocaleProfile
    {
        $mixedThreshold = (float) config('locale_assistant.detection.mixed_threshold', 0.20);
        $dominantThreshold = (float) config('locale_assistant.detection.dominant_threshold', 0.55);
        $ruPct = (float) $scores['ruPct'];
        $kkPct = (float) $scores['kkPct'];
        $script = (string) $scores['script'];

        $dominant = $this->resolveDominant($ruPct, $kkPct, $script, $scores);

        $tokenCount = (int) $scores['tokenCount'];
        $slangHits = (int) $scores['slangHits'];
        $formalHits = (int) $scores['formalHits'];
        $casualHits = (int) $scores['casualHits'];
        $kkLetters = (int) $scores['kkLetters'];
        $kkPlain = (int) $scores['kkPlain'];
        $translitKk = (int) $scores['translitKk'];

        $slangScore = min(1.0, ($slangHits + $casualHits * 0.5) / max(1, $tokenCount));

        $formality = KazakhstanLocaleProfile::FORMALITY_NEUTRAL;
        if ($formalHits > $casualHits && $formalHits > 0) {
            $formality = KazakhstanLocaleProfile::FORMALITY_FORMAL;
        } elseif ($casualHits > $formalHits && ($casualHits > 0 || $slangScore >= 0.35)) {
            $formality = KazakhstanLocaleProfile::FORMALITY_CASUAL;
        }

        $shortLimit = (int) config('locale_assistant.detection.short_token_limit', 3);
        $confidence = $tokenCount <= $shortLimit && $kkPlain === 0 && $kkLetters === 0
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

    /**
     * @param  array<string, mixed>  $scores
     */
    private function resolveDominant(float $ruPct, float $kkPct, string $script, array $scores): string
    {
        $mixedThreshold = (float) config('locale_assistant.detection.mixed_threshold', 0.20);
        $dominantThreshold = (float) config('locale_assistant.detection.dominant_threshold', 0.55);
        $ruHits = (int) ($scores['ruHits'] ?? 0);
        $kkHits = (int) ($scores['kkHits'] ?? 0);
        $kkLetters = (int) ($scores['kkLetters'] ?? 0);
        $kkPlain = (int) ($scores['kkPlain'] ?? 0);
        $translitKk = (int) ($scores['translitKk'] ?? 0);
        $translitRu = (int) ($scores['translitRu'] ?? 0);

        if ($script === 'latin' && ($translitKk > 0 || $translitRu > 0 || $kkHits > 0 || $kkPlain > 0)) {
            return KazakhstanLocaleProfile::DOMINANT_TRANSLIT_MIXED;
        }

        if ($ruPct >= $dominantThreshold && $kkPct < $mixedThreshold) {
            return KazakhstanLocaleProfile::DOMINANT_RU;
        }

        if ($kkPct >= $dominantThreshold && $ruPct < $mixedThreshold) {
            return KazakhstanLocaleProfile::DOMINANT_KK;
        }

        if ($ruPct >= $dominantThreshold && $ruPct > $kkPct) {
            return KazakhstanLocaleProfile::DOMINANT_RU;
        }

        if ($kkPct >= $dominantThreshold && $kkPct > $ruPct) {
            return KazakhstanLocaleProfile::DOMINANT_KK;
        }

        if ($ruPct >= $mixedThreshold && $kkPct >= $mixedThreshold) {
            return KazakhstanLocaleProfile::DOMINANT_MIXED;
        }

        if ($kkLetters >= 2 || $kkPlain >= 2) {
            return KazakhstanLocaleProfile::DOMINANT_KK;
        }

        if ($ruHits > 0 && $kkHits === 0 && $kkLetters === 0 && $kkPlain === 0) {
            return KazakhstanLocaleProfile::DOMINANT_RU;
        }

        if ($kkHits > 0 || $kkPlain > 0) {
            return $kkPct >= $ruPct
                ? KazakhstanLocaleProfile::DOMINANT_KK
                : KazakhstanLocaleProfile::DOMINANT_MIXED;
        }

        if ($ruHits > 0) {
            return KazakhstanLocaleProfile::DOMINANT_RU;
        }

        return KazakhstanLocaleProfile::DOMINANT_UNKNOWN;
    }

    /**
     * @param  list<KazakhstanLocaleProfile>  $profiles
     */
    private function mergeFormality(array $profiles): string
    {
        $formal = 0;
        $casual = 0;

        foreach ($profiles as $profile) {
            if ($profile->formality === KazakhstanLocaleProfile::FORMALITY_FORMAL) {
                $formal++;
            } elseif ($profile->formality === KazakhstanLocaleProfile::FORMALITY_CASUAL) {
                $casual++;
            }
        }

        if ($formal > $casual && $formal > 0) {
            return KazakhstanLocaleProfile::FORMALITY_FORMAL;
        }

        if ($casual > $formal && $casual > 0) {
            return KazakhstanLocaleProfile::FORMALITY_CASUAL;
        }

        return KazakhstanLocaleProfile::FORMALITY_NEUTRAL;
    }

    private function countKkPlainMarkers(string $lower): int
    {
        /** @var list<string> $markers */
        $markers = [
            'канша', 'турады', 'қанша', 'тұрады', 'неше', 'қандай', 'кайда', 'қайда', 'неге',
            'керек', 'барма', 'жоқ', 'жок', 'рахмет', 'кешір', 'кешир', 'кешіріңіз',
            'саламат', 'сәлемет', 'салемет', 'ассалаума', 'assalaum', 'assalamu', 'assalauma',
            'магалайкум', 'magalaykum', 'мағалайкум', 'жақсы', 'жаксы', 'қазір', 'кайта',
            'келес', 'жібер', 'jiber', 'zhiber', 'qalay', 'qalaysyn', 'turady', 'qansha',
            'bagasy', 'бағасы', 'bagasy', 'nege', 'qalay', 'salem', 'salam',
        ];

        $hits = 0;
        foreach ($markers as $marker) {
            if ($marker !== '' && str_contains($lower, $marker)) {
                $hits++;
            }
        }

        return $hits;
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
