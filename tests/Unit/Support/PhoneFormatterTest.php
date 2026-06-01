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

    public function test_is_plausible_e164_rejects_whatsapp_lead_id(): void
    {
        $this->assertFalse(PhoneFormatter::isPlausibleE164('276634632478839'));
        $this->assertTrue(PhoneFormatter::isPlausibleE164('77781071376'));
    }

    public function test_format_international_for_kz_number(): void
    {
        $this->assertSame('+7 778 107 1376', PhoneFormatter::formatInternational('77781071376'));
    }

    public function test_resolve_contact_identity_splits_phone_and_lead_id(): void
    {
        $lidContact = new \App\Models\Contact([
            'phone_number' => '276634632478839',
            'whatsapp_id' => '276634632478839@lid',
        ]);
        $phoneContact = new \App\Models\Contact([
            'phone_number' => '77781071376',
            'whatsapp_id' => '77781071376@c.us',
        ]);

        $resolved = PhoneFormatter::resolveContactIdentity([$lidContact, $phoneContact]);

        $this->assertSame('77781071376', $resolved['phone_number']);
        $this->assertSame('+7 778 107 1376', $resolved['phone_display']);
        $this->assertSame('276634632478839', $resolved['lead_id']);
    }
}
