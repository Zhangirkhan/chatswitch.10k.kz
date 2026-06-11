<?php

declare(strict_types=1);

namespace App\Support;

final class Bin
{
    public static function digitsOnly(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits !== '' ? $digits : null;
    }

    public static function normalize(?string $value): ?string
    {
        $digits = self::digitsOnly($value);

        return $digits !== null && strlen($digits) === 12 ? $digits : null;
    }
}
