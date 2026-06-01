<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Locale;

use App\Services\AI\Locale\KazakhstanLocaleDetector;
use App\Services\AI\Locale\KazakhstanLocaleProfile;
use App\Services\AI\Locale\LocaleLexiconLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class KazakhstanLocaleDetectorTest extends TestCase
{
    use RefreshDatabase;

    private KazakhstanLocaleDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new KazakhstanLocaleDetector(new LocaleLexiconLoader);
    }

    public function test_detects_formal_russian(): void
    {
        $profile = $this->detector->detect('Здравствуйте, можете помочь?');

        $this->assertSame(KazakhstanLocaleProfile::DOMINANT_RU, $profile->dominant);
        $this->assertSame(KazakhstanLocaleProfile::FORMALITY_FORMAL, $profile->formality);
        $this->assertFalse($profile->allowMixedReply);
    }

    public function test_detects_mixed_casual(): void
    {
        $profile = $this->detector->detect('скинь документти');

        $this->assertContains($profile->dominant, [
            KazakhstanLocaleProfile::DOMINANT_MIXED,
            KazakhstanLocaleProfile::DOMINANT_RU,
            KazakhstanLocaleProfile::DOMINANT_KK,
        ]);
        $this->assertTrue($profile->allowMixedReply || $profile->dominant === KazakhstanLocaleProfile::DOMINANT_MIXED);
    }

    public function test_detects_transliterated_casual(): void
    {
        $profile = $this->detector->detect('privet brat kalaysyn');

        $this->assertSame(KazakhstanLocaleProfile::DOMINANT_TRANSLIT_MIXED, $profile->dominant);
        $this->assertTrue($profile->preferKkCyrillic);
    }

    public function test_detects_kazakh_cyrillic(): void
    {
        $profile = $this->detector->detect('Сәлеметсіз бе, жазу керек');

        $this->assertSame(KazakhstanLocaleProfile::DOMINANT_KK, $profile->dominant);
    }

    public function test_detects_russian_after_kazakh_conversation_not_in_context(): void
    {
        $profile = $this->detector->detect('Сколько стоит доставка?');

        $this->assertSame(KazakhstanLocaleProfile::DOMINANT_RU, $profile->dominant);
        $this->assertGreaterThan($profile->kkPct, $profile->ruPct);
    }
}
