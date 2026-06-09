<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Push\MobilePushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SendMobilePushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  list<int>  $userIds
     * @param  array<string, string>  $data
     */
    public function __construct(
        public readonly array $userIds,
        public readonly array $data,
    ) {}

    public function handle(MobilePushService $pushService): void
    {
        $pushService->sendToUsersNow($this->userIds, $this->data);
    }
}
