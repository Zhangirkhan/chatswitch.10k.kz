<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Единый формат номера в системе — только цифры, включая код страны.
 *
 * Пример: 77476644108
 *
 * Правила нормализации:
 * - Убираются любые нецифровые символы (+, пробелы, дефисы, скобки, суффиксы WhatsApp вроде "@c.us").
 * - Если номер 11 цифр и начинается с "8" (казахстано-/россий ский формат) — заменяем на "7".
 * - Если номер 10 цифр (без кода страны) — добавляем "7" в начало.
 * - Короткие номера (меньше 7 цифр) возвращаются как есть — чтобы не ломать сервисные.
 */
final class PhoneFormatter
{
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        $length = strlen($digits);

        if ($length === 11 && $digits[0] === '8') {
            $digits = '7'.substr($digits, 1);
        } elseif ($length === 10) {
            $digits = '7'.$digits;
        }

        return $digits;
    }

    /**
     * Извлекает номер из WhatsApp-идентификатора (например, "77476644108@c.us" → "77476644108").
     */
    public static function fromWhatsappId(?string $whatsappId): ?string
    {
        if ($whatsappId === null) {
            return null;
        }

        $raw = str_contains($whatsappId, '@') ? strstr($whatsappId, '@', true) : $whatsappId;

        return self::normalize($raw ?: null);
    }

    /**
     * Реальный номер для отображения (E.164), не внутренний WhatsApp lead id (@lid).
     */
    public static function isPlausibleE164(?string $phone): bool
    {
        $digits = self::normalize($phone);
        if ($digits === null || ! ctype_digit($digits)) {
            return false;
        }

        $length = strlen($digits);
        if ($length < 10 || $length > 13) {
            return false;
        }

        if (str_starts_with($digits, '1')) {
            return $length === 11;
        }

        if (str_starts_with($digits, '7')) {
            return $length === 11;
        }

        return $length >= 10 && $length <= 12;
    }

    public static function formatInternational(?string $phone): ?string
    {
        $digits = self::normalize($phone);
        if ($digits === null || ! self::isPlausibleE164($digits)) {
            return null;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '7')) {
            return sprintf(
                '+%s %s %s %s',
                substr($digits, 0, 1),
                substr($digits, 1, 3),
                substr($digits, 4, 3),
                substr($digits, 7),
            );
        }

        return '+'.$digits;
    }

    /**
     * @param  iterable<\App\Models\Contact>  $contacts
     * @return array{phone_number: ?string, phone_display: ?string, lead_id: ?string}
     */
    public static function resolveContactIdentity(iterable $contacts): array
    {
        $phone = null;
        $leadId = null;

        foreach ($contacts as $contact) {
            $whatsappId = strtolower(trim((string) ($contact->whatsapp_id ?? '')));
            $fromStoredPhone = self::normalize($contact->phone_number);
            $fromWhatsapp = self::fromWhatsappId($contact->whatsapp_id);

            if (str_ends_with($whatsappId, '@c.us') && self::isPlausibleE164($fromWhatsapp)) {
                $phone = $fromWhatsapp;
            }

            if (self::isPlausibleE164($fromStoredPhone)) {
                $phone ??= $fromStoredPhone;
            }

            if (str_ends_with($whatsappId, '@lid')) {
                $lidDigits = self::normalize(str_contains($whatsappId, '@')
                    ? strstr((string) $contact->whatsapp_id, '@', true)
                    : (string) $contact->whatsapp_id);
                if ($lidDigits !== null && ! self::isPlausibleE164($lidDigits)) {
                    $leadId ??= $lidDigits;
                }
            }

            if ($fromStoredPhone !== null && ! self::isPlausibleE164($fromStoredPhone)) {
                $leadId ??= $fromStoredPhone;
            }
        }

        return [
            'phone_number' => $phone,
            'phone_display' => self::formatInternational($phone),
            'lead_id' => $leadId,
        ];
    }
}
