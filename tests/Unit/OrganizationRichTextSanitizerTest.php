<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OrganizationRichTextSanitizer;
use PHPUnit\Framework\TestCase;

final class OrganizationRichTextSanitizerTest extends TestCase
{
    public function test_strips_script_tags(): void
    {
        $html = '<p>Hi</p><script>alert(1)</script><p>Bye</p>';
        $out = OrganizationRichTextSanitizer::sanitize($html);

        $this->assertNotNull($out);
        $this->assertStringNotContainsString('<script', strtolower((string) $out));
        $this->assertStringContainsString('Hi', (string) $out);
    }

    public function test_null_and_blank_return_null(): void
    {
        $this->assertNull(OrganizationRichTextSanitizer::sanitize(null));
        $this->assertNull(OrganizationRichTextSanitizer::sanitize('   '));
    }
}
