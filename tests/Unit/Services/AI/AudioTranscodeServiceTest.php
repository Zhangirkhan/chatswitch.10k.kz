<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Services\AI\AudioTranscodeService;
use Tests\TestCase;

final class AudioTranscodeServiceTest extends TestCase
{
    public function test_needs_transcode_for_safari_mime_types(): void
    {
        $service = new AudioTranscodeService;

        $this->assertTrue($service->needsTranscode('audio/mp4'));
        $this->assertTrue($service->needsTranscode('audio/x-m4a'));
        $this->assertTrue($service->needsTranscode('audio/x-caf'));
        $this->assertFalse($service->needsTranscode('audio/webm'));
        $this->assertFalse($service->needsTranscode('audio/ogg'));
    }

    public function test_transcode_returns_null_for_unreadable_input(): void
    {
        $service = new AudioTranscodeService;

        $this->assertNull($service->transcodeToWebm('/tmp/nonexistent-dictation-audio.mp4'));
    }
}
