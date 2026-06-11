<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\GenerateMessageMediaThumbnailJob;
use App\Models\MessageMedia;
use App\Services\MessageMediaThumbnailService;

final class MessageMediaObserver
{
    public function created(MessageMedia $messageMedia): void
    {
        if (! app(MessageMediaThumbnailService::class)->supportsThumbnail($messageMedia)) {
            return;
        }

        GenerateMessageMediaThumbnailJob::dispatch($messageMedia->id);
    }
}
