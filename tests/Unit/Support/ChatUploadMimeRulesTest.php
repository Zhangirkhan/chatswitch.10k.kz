<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ChatUploadMimeRules;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ChatUploadMimeRulesTest extends TestCase
{
    #[Test]
    public function it_accepts_ios_m4a_as_audio_mp4(): void
    {
        $file = UploadedFile::fake()->create('voice.m4a', 120, 'audio/x-m4a');

        $this->assertTrue(ChatUploadMimeRules::accepts($file, 'voice'));
        $this->assertSame('audio/mp4', ChatUploadMimeRules::resolveAcceptedMime($file, 'voice'));
    }

    #[Test]
    public function it_accepts_wav_voice_upload(): void
    {
        $file = UploadedFile::fake()->create('voice.wav', 80, 'audio/wav');

        $this->assertTrue(ChatUploadMimeRules::accepts($file, 'voice'));
        $this->assertSame('audio/wav', ChatUploadMimeRules::resolveAcceptedMime($file, 'voice'));
    }

    #[Test]
    public function it_accepts_octet_stream_when_filename_is_voice(): void
    {
        $file = UploadedFile::fake()->create('note.m4a', 80, 'application/octet-stream');

        $this->assertTrue(ChatUploadMimeRules::accepts($file, 'voice'));
        $this->assertSame('audio/mp4', ChatUploadMimeRules::resolveAcceptedMime($file, 'voice'));
    }

    #[Test]
    public function it_rejects_unknown_binary_for_voice(): void
    {
        $file = UploadedFile::fake()->create('payload.bin', 80, 'application/octet-stream');

        $this->assertFalse(ChatUploadMimeRules::accepts($file, 'voice'));
    }
}
