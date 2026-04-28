<?php

declare(strict_types=1);

namespace App\Support;

final class VCard
{
    public static function build(string $name, string $phone, ?string $email, ?string $company): string
    {
        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'FN:'.self::escape($name),
            'N:'.self::escape($name).';;;;',
        ];

        $waId = preg_replace('/\D/', '', $phone) ?: '';
        $lines[] = 'TEL;type=CELL;type=VOICE;waid='.$waId.':+'.$waId;

        if ($email) {
            $lines[] = 'EMAIL:'.self::escape($email);
        }
        if ($company) {
            $lines[] = 'ORG:'.self::escape($company);
        }

        $lines[] = 'END:VCARD';

        return implode("\n", $lines);
    }

    private static function escape(string $value): string
    {
        return str_replace([',', ';', "\n"], ['\\,', '\\;', '\\n'], $value);
    }
}
