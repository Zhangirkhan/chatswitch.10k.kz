<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\FeedbackDiagnosticDetector;
use PHPUnit\Framework\TestCase;

final class FeedbackDiagnosticDetectorTest extends TestCase
{
    public function test_detects_diagnostic_prefix(): void
    {
        $this->assertTrue(FeedbackDiagnosticDetector::isDiagnostic('[diagnostic] App crash on login'));
    }

    public function test_detects_diagnostic_report_marker(): void
    {
        $message = "Header\n=== Accel Mobile Diagnostic Report ===\nDetails";
        $this->assertTrue(FeedbackDiagnosticDetector::isDiagnostic($message));
    }

    public function test_regular_feedback_is_not_diagnostic(): void
    {
        $this->assertFalse(FeedbackDiagnosticDetector::isDiagnostic('Нужна диктовка в AI-чате'));
    }
}
