<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\VCard;
use PHPUnit\Framework\TestCase;

final class VCardTest extends TestCase
{
    public function test_build_emits_valid_structure(): void
    {
        $vcard = VCard::build('Alice Doe', '+7 (747) 123-45-67', 'alice@example.com', 'Acme');

        $this->assertStringContainsString('BEGIN:VCARD', $vcard);
        $this->assertStringContainsString('END:VCARD', $vcard);
        $this->assertStringContainsString('FN:Alice Doe', $vcard);
        $this->assertStringContainsString('waid=77471234567', $vcard);
        $this->assertStringContainsString('TEL;type=CELL;type=VOICE;waid=77471234567:+77471234567', $vcard);
        $this->assertStringContainsString('EMAIL:alice@example.com', $vcard);
        $this->assertStringContainsString('ORG:Acme', $vcard);
    }

    public function test_build_escapes_special_characters(): void
    {
        $vcard = VCard::build('John; Doe, Jr.', '77471234567', null, null);

        $this->assertStringContainsString('FN:John\\; Doe\\, Jr.', $vcard);
        $this->assertStringNotContainsString('EMAIL:', $vcard);
        $this->assertStringNotContainsString('ORG:', $vcard);
    }
}
