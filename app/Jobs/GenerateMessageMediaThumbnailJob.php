<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MessageMedia;
use App\Services\MessageMediaThumbnailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class GenerateMessageMediaThumbnailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    /** @var list<int> */
    public array $backoff = [5, 15, 30];

    public function __construct(
        public readonly int $messageMediaId,
    ) {}

    public function uniqueId(): string
    {
        return 'message-media-thumb:'.$this->messageMediaId;
    }

    public function handle(MessageMediaThumbnailService $thumbnailService): void
    {
        $media = MessageMedia::query()->find($this->messageMediaId);
        if ($media === null) {
            return;
        }

        $thumbnailService->generate($media);
    }
}
