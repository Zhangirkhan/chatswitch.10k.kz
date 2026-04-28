<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\MediaType;
use PHPUnit\Framework\TestCase;

final class MediaTypeTest extends TestCase
{
    public function test_detect_respects_hint(): void
    {
        $this->assertSame('voice', MediaType::detect('audio/ogg', 'voice'));
    }

    public function test_detect_maps_mime_types(): void
    {
        $this->assertSame('gif', MediaType::detect('image/gif'));
        $this->assertSame('sticker', MediaType::detect('image/webp'));
        $this->assertSame('image', MediaType::detect('image/png'));
        $this->assertSame('video', MediaType::detect('video/mp4'));
        $this->assertSame('voice', MediaType::detect('audio/ogg'));
        $this->assertSame('audio', MediaType::detect('audio/mpeg'));
        $this->assertSame('document', MediaType::detect('application/pdf'));
    }

    public function test_preview_text_prefers_caption(): void
    {
        $this->assertSame('Caption here', MediaType::previewText('image', 'Caption here'));
    }

    public function test_preview_text_fallback_by_type(): void
    {
        $this->assertStringContainsString('Фото', MediaType::previewText('image'));
        $this->assertStringContainsString('Документ', MediaType::previewText('document'));
        $this->assertStringContainsString('Голосовое', MediaType::previewText('ptt'));
    }
}
