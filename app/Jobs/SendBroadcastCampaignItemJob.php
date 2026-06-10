<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BroadcastCampaign;
use App\Models\BroadcastCampaignItem;
use App\Models\Chat;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Broadcast\BroadcastCampaignService;
use App\Services\Broadcast\BroadcastSendRateLimiter;
use App\Services\OutboundChatMessageDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SendBroadcastCampaignItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $itemId,
        public readonly ?int $tenantCompanyId = null,
    ) {}

    public function viaQueue(): string
    {
        return 'whatsapp';
    }

    public function handle(
        OutboundChatMessageDispatcher $dispatcher,
        BroadcastCampaignService $campaignService,
        BroadcastSendRateLimiter $rateLimiter,
    ): void {
        $claimed = BroadcastCampaignItem::query()
            ->whereKey($this->itemId)
            ->where('status', BroadcastCampaignItem::STATUS_PENDING)
            ->update(['status' => BroadcastCampaignItem::STATUS_PROCESSING]);

        if ($claimed !== 1) {
            return;
        }

        $item = BroadcastCampaignItem::query()
            ->with(['campaign.whatsappSession', 'campaign.sender'])
            ->find($this->itemId);

        if ($item === null) {
            return;
        }

        $campaign = $item->campaign;
        if ($campaign === null || $campaign->status === BroadcastCampaign::STATUS_CANCELLED) {
            return;
        }

        $companyId = (int) ($this->tenantCompanyId ?? $campaign->whatsappSession?->company_id ?? 0);
        if (
            $companyId > 0
            && SystemSetting::getValue('module_broadcasts', 'on', $companyId) !== 'on'
        ) {
            $this->markSkipped($item, $campaignService, 'Модуль «Рассылки» отключён администратором.');

            return;
        }

        $chat = Chat::query()->find($item->chat_id);
        $sender = User::query()->find($campaign->sender_user_id);
        if ($chat === null || $sender === null) {
            $this->markFailed($item, $campaignService, 'Чат или отправитель не найден.');

            return;
        }

        if (! $chat->is_archived) {
            $this->markSkipped($item, $campaignService, 'Чат больше не закрыт (не в архиве).');

            return;
        }

        $sessionId = (int) $campaign->whatsapp_session_id;
        if (! $rateLimiter->canSendNow($sessionId)) {
            $this->release($rateLimiter->randomDelayBetweenMessages());

            return;
        }

        try {
            $result = $dispatcher->sendTextMessage($sender, $chat, [
                'message' => $item->message_text,
            ]);
            $item->forceFill([
                'status' => BroadcastCampaignItem::STATUS_SENT,
                'message_id' => $result->message->id,
                'processed_at' => now(),
                'error' => null,
            ])->save();
            $campaign->increment('sent_count');
        } catch (Throwable $e) {
            Log::warning('[broadcast] send failed', [
                'item_id' => $item->id,
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);
            $this->markFailed($item, $campaignService, mb_substr($e->getMessage(), 0, 480));

            return;
        }

        $campaignService->refreshCounters($campaign->fresh() ?? $campaign);
    }

    private function markFailed(
        BroadcastCampaignItem $item,
        BroadcastCampaignService $campaignService,
        string $error,
    ): void {
        $item->forceFill([
            'status' => BroadcastCampaignItem::STATUS_FAILED,
            'error' => $error,
            'processed_at' => now(),
        ])->save();

        $campaign = $item->campaign;
        if ($campaign !== null) {
            $campaign->increment('failed_count');
            $campaignService->refreshCounters($campaign->fresh() ?? $campaign);
        }
    }

    private function markSkipped(
        BroadcastCampaignItem $item,
        BroadcastCampaignService $campaignService,
        string $reason,
    ): void {
        $item->forceFill([
            'status' => BroadcastCampaignItem::STATUS_SKIPPED,
            'skip_reason' => $reason,
            'processed_at' => now(),
        ])->save();

        $campaign = $item->campaign;
        if ($campaign !== null) {
            $campaign->increment('skipped_count');
            $campaignService->refreshCounters($campaign->fresh() ?? $campaign);
        }
    }
}
