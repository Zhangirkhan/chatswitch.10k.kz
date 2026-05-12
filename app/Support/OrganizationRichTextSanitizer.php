<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Серверная нейтрализация HTML для постов организации (stored XSS).
 * Используется при сохранении и при отдаче в API/Inertia.
 */
final class OrganizationRichTextSanitizer
{
    private static ?HtmlSanitizer $sanitizer = null;

    public static function sanitize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        if (trim($input) === '') {
            return null;
        }

        $out = self::singleton()->sanitize($input);
        $trimmed = trim($out);

        return $trimmed === '' ? null : $out;
    }

    private static function singleton(): HtmlSanitizer
    {
        if (self::$sanitizer instanceof HtmlSanitizer) {
            return self::$sanitizer;
        }

        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->withMaxInputLength(70_000)
            ->allowLinkSchemes(['http', 'https'])
            ->allowMediaSchemes(['http', 'https']);

        self::$sanitizer = new HtmlSanitizer($config);

        return self::$sanitizer;
    }
}
