<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\PhoneFormatter;
use PHPUnit\Framework\TestCase;

final class PhoneFormatterTest extends TestCase
{
    public function test_normalize_returns_null_for_empty(): void
    {
        $this->assertNull(PhoneFormatter::normalize(null));
        $this->assertNull(PhoneFormatter::normalize(''));
        $this->assertNull(PhoneFormatter::normalize('abc'));
    }

    public function test_normalize_strips_non_digits(): void
    {
        $this->assertSame('77476644108', PhoneFormatter::normalize('+7 (747) 664-41-08'));
        $this->assertSame('77476644108', PhoneFormatter::normalize('77476644108@c.us'));
    }

    public function test_normalize_replaces_leading_8_with_7(): void
    {
        $this->assertSame('77476644108', PhoneFormatter::normalize('87476644108'));
    }

    public function test_normalize_adds_country_code_for_10_digit(): void
    {
        $this->assertSame('77476644108', PhoneFormatter::normalize('7476644108'));
    }

    public function test_from_whatsapp_id(): void
    {
        $this->assertSame('77476644108', PhoneFormatter::fromWhatsappId('77476644108@c.us'));
        $this->assertNull(PhoneFormatter::fromWhatsappId(null));
    }
}
