<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Services\AI\Locale\KazakhstanLocaleProfile;
use App\Support\LocalizedClientGreeting;
use Tests\TestCase;

final class LocalizedClientGreetingTest extends TestCase
{
    public function test_default_first_reply_is_kazakh_for_kazakh_profile(): void
    {
        $profile = new KazakhstanLocaleProfile(
            dominant: KazakhstanLocaleProfile::DOMINANT_KK,
            ruPct: 0.1,
            kkPct: 0.9,
            script: 'cyrillic',
            formality: KazakhstanLocaleProfile::FORMALITY_NEUTRAL,
            slangScore: 0.0,
            allowMixedReply: false,
            preferKkCyrillic: true,
            confidence: KazakhstanLocaleProfile::CONFIDENCE_HIGH,
        );

        $reply = LocalizedClientGreeting::defaultFirstReply($profile);

        $this->assertStringContainsString('Сәлеметсіз бе', $reply);
        $this->assertStringNotContainsString('Здравствуйте', $reply);
    }

    public function test_prepends_kazakh_greeting_when_needed(): void
    {
        $profile = new KazakhstanLocaleProfile(
            dominant: KazakhstanLocaleProfile::DOMINANT_KK,
            ruPct: 0.05,
            kkPct: 0.95,
            script: 'cyrillic',
            formality: KazakhstanLocaleProfile::FORMALITY_NEUTRAL,
            slangScore: 0.0,
            allowMixedReply: false,
            preferKkCyrillic: true,
            confidence: KazakhstanLocaleProfile::CONFIDENCE_HIGH,
        );

        $reply = LocalizedClientGreeting::prependGreeting($profile, 'Қанша тұрады?');

        $this->assertStringStartsWith('Сәлеметсіз бе!', $reply);
    }
}
