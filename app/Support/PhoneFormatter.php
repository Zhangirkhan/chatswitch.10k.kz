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
}
