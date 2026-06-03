<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\MessageLanguageHeuristics;
use Tests\TestCase;

final class MessageLanguageHeuristicsTest extends TestCase
{
    public function test_detects_kazakh_from_samples(): void
    {
        $lang = MessageLanguageHeuristics::detectFromSamples([
            'Сәлем, қанша тұрады?',
            'Жеткізу бар ма?',
        ]);

        $this->assertSame(MessageLanguageHeuristics::LANG_KK, $lang);
    }

    public function test_detects_russian_from_samples(): void
    {
        $lang = MessageLanguageHeuristics::detectFromSamples([
            'Добрый день, сколько стоит?',
            'Можно доставку завтра?',
        ]);

        $this->assertSame(MessageLanguageHeuristics::LANG_RU, $lang);
    }
}
