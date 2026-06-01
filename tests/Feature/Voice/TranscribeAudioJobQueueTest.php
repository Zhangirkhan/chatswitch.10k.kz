<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Jobs\TranscribeAudioJob;
use Tests\TestCase;

final class TranscribeAudioJobQueueTest extends TestCase
{
    public function test_uses_transcription_queue(): void
    {
        $job = new TranscribeAudioJob(1);

        $this->assertSame('transcription', $job->viaQueue());
    }

    public function test_unique_id_is_stable_per_message(): void
    {
        $job = new TranscribeAudioJob(42);

        $this->assertSame('transcribe-audio-42', $job->uniqueId());
    }
}
