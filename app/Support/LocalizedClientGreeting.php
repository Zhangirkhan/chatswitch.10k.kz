<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\AI\Locale\KazakhstanLocaleProfile;

final class LocalizedClientGreeting
{
    public static function defaultFirstReply(KazakhstanLocaleProfile $profile): string
    {
        return self::isKazakhPreferred($profile)
            ? 'Сәлеметсіз бе! Немен көмектесе аламыз?'
            : 'Здравствуйте! Подскажите, чем можем помочь?';
    }

    public static function prependGreeting(KazakhstanLocaleProfile $profile, string $reply): string
    {
        $reply = trim($reply);
        if ($reply === '' || ClientMessageHeuristics::usedGreeting($reply)) {
            return $reply;
        }

        $prefix = self::isKazakhPreferred($profile)
            ? 'Сәлеметсіз бе!'
            : 'Здравствуйте!';

        return $prefix.' '.$reply;
    }

    public static function isKazakhPreferred(KazakhstanLocaleProfile $profile): bool
    {
        return in_array($profile->dominant, [
            KazakhstanLocaleProfile::DOMINANT_KK,
            KazakhstanLocaleProfile::DOMINANT_MIXED,
            KazakhstanLocaleProfile::DOMINANT_TRANSLIT_MIXED,
        ], true) && $profile->kkPct >= $profile->ruPct;
    }
}
