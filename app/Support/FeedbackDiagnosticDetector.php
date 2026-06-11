<?php

declare(strict_types=1);

namespace App\Support;

final class FeedbackDiagnosticDetector
{
    private const REPORT_MARKER = '=== Accel Mobile Diagnostic Report ===';

    public static function isDiagnostic(string $message): bool
    {
        $message = trim($message);
        if ($message === '') {
            return false;
        }

        if (str_starts_with($message, '[diagnostic]')) {
            return true;
        }

        return str_contains($message, self::REPORT_MARKER);
    }
}
