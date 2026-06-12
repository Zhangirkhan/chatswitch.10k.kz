<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\AI\Locale\LocaleLexiconLoader;

final class KazakhInformalTextDetector
{
    private const KAZAKH_LETTERS = '/[әғқңөұүһі]/u';

    /** @var list<string> */
    private const PLAIN_MARKERS = [
        'канша', 'турады', 'қанша', 'тұрады', 'неше', 'қандай', 'кайда', 'қайда', 'неге',
        'керек', 'барма', 'жоқ', 'жок', 'рахмет', 'кешір', 'кешир', 'кешіріңіз',
        'саламат', 'сәлемет', 'салемет', 'салеметсизбе', 'салем', 'ассалаума', 'assalaum', 'assalamu', 'assalauma',
        'магалайкум', 'magalaykum', 'мағалайкум', 'жақсы', 'жаксы', 'қазір', 'кайта',
        'келес', 'жібер', 'jiber', 'zhiber', 'qalay', 'qalaysyn', 'калай', 'калайсын',
        'turady', 'qansha', 'bagasy', 'бағасы', 'nege', 'salem', 'salam', 'rahmet',
        'ия', 'иа', 'емес',
    ];

    /** @var list<string> */
    private const HIGH_CONFIDENCE_FUNCTION_WORDS = [
        'жоқ', 'жок', 'керек', 'емес', 'барма', 'ия', 'иа', 'рахмет', 'сәлем', 'салам',
        'салемет', 'сәлемет', 'ассалаума', 'мағалайкум', 'магалайкум', 'қанша', 'канша',
        'тұрады', 'турады', 'қалай', 'калай', 'неше',
    ];

    /** @var list<string>|null */
    private static ?array $translitWords = null;

    public static function matches(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        if (preg_match(self::KAZAKH_LETTERS, $text) === 1) {
            return true;
        }

        $lower = mb_strtolower($text);

        foreach (self::PLAIN_MARKERS as $marker) {
            if ($marker !== '' && str_contains($lower, $marker)) {
                return true;
            }
        }

        foreach (self::HIGH_CONFIDENCE_FUNCTION_WORDS as $word) {
            if ($word !== '' && self::containsWord($lower, $word)) {
                return true;
            }
        }

        foreach (self::translitWords() as $word) {
            if ($word !== '' && self::containsWord($lower, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function translitWords(): array
    {
        if (self::$translitWords !== null) {
            return self::$translitWords;
        }

        try {
            $loader = app(LocaleLexiconLoader::class);
            self::$translitWords = $loader->words('kz_letters', 'translit_kk_words');
        } catch (\Throwable) {
            self::$translitWords = [];
        }

        return self::$translitWords;
    }

    private static function containsWord(string $haystack, string $word): bool
    {
        if ($word === '') {
            return false;
        }

        if (str_contains($haystack, $word)) {
            return true;
        }

        $pattern = '/(?<!\p{L})'.preg_quote($word, '/').'(?!\p{L})/u';

        return preg_match($pattern, $haystack) === 1;
    }
}
