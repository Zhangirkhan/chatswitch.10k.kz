<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\KazakhInformalTextDetector;
use Tests\TestCase;

final class KazakhInformalTextDetectorTest extends TestCase
{
    public function test_matches_kazakh_special_letters(): void
    {
        $this->assertTrue(KazakhInformalTextDetector::matches('Сәлем, қанша тұрады?'));
    }

    public function test_matches_plain_cyrillic_transliteration(): void
    {
        $this->assertTrue(KazakhInformalTextDetector::matches('салеметсизбе'));
        $this->assertTrue(KazakhInformalTextDetector::matches('калайсын'));
        $this->assertTrue(KazakhInformalTextDetector::matches('рахмет'));
    }

    public function test_matches_latin_transliteration(): void
    {
        $this->assertTrue(KazakhInformalTextDetector::matches('salam qalaysyn'));
        $this->assertTrue(KazakhInformalTextDetector::matches('rahmet'));
    }

    public function test_does_not_match_plain_russian(): void
    {
        $this->assertFalse(KazakhInformalTextDetector::matches('Добрый день, сколько стоит?'));
    }
}
