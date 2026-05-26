<?php

declare(strict_types=1);

namespace App\Services\AI\Locale;

final class LocaleReplyGuard
{
    /**
     * @var list<string>
     */
    private const HEAVY_SLANG = [
        'братан', 'братанчик', 'че как', 'йоу', 'крч', 'короч', 'жесть брат',
    ];

    public function apply(string $reply, KazakhstanLocaleProfile $profile): string
    {
        if ($profile->formality !== KazakhstanLocaleProfile::FORMALITY_FORMAL) {
            return $reply;
        }

        $lower = mb_strtolower($reply);
        foreach (self::HEAVY_SLANG as $needle) {
            if (str_contains($lower, $needle)) {
                $reply = str_ireplace($needle, '', $reply);
            }
        }

        return trim(preg_replace('/\s{2,}/u', ' ', $reply) ?? $reply);
    }
}
