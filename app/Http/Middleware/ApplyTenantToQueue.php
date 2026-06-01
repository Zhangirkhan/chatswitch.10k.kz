<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Chat;
use App\Models\Company;
use App\Models\Message;
use App\Tenancy\TenantContext;
use Illuminate\Queue\Events\JobProcessing;

final class ApplyTenantToQueue
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(JobProcessing $event): void
    {
        $this->tenantContext->clear();

        try {
            $payload = $event->job->payload();
            $serialized = $payload['data']['command'] ?? null;
            if (! is_string($serialized)) {
                return;
            }

            $command = unserialize($serialized, ['allowed_classes' => true]);
            if (! is_object($command)) {
                return;
            }

            $companyId = $this->resolveCompanyId($command);
            if ($companyId === null) {
                return;
            }

            $company = Company::query()->withoutGlobalScope('tenant')->find($companyId);
            if ($company !== null) {
                $this->tenantContext->setCompany($company);
            }
        } catch (\Throwable) {
            // Non-job payloads or legacy jobs without tenant context.
        }
    }

    private function resolveCompanyId(object $command): ?int
    {
        foreach (['tenantCompanyId', 'companyId'] as $property) {
            if (! property_exists($command, $property)) {
                continue;
            }

            $value = $command->{$property};
            if (is_int($value) && $value > 0) {
                return $value;
            }
        }

        if (property_exists($command, 'chatId')) {
            $chatId = $command->chatId;
            if (is_int($chatId) && $chatId > 0) {
                $companyId = Chat::query()
                    ->withoutGlobalScope('tenant')
                    ->whereKey($chatId)
                    ->value('company_id');

                if (is_int($companyId) && $companyId > 0) {
                    return $companyId;
                }
            }
        }

        if (property_exists($command, 'messageId')) {
            $messageId = $command->messageId;
            if (is_int($messageId) && $messageId > 0) {
                $companyId = $this->companyIdFromMessage($messageId);
                if ($companyId !== null) {
                    return $companyId;
                }
            }
        }

        if (property_exists($command, 'itemId')) {
            $itemId = $command->itemId;
            if (is_int($itemId) && $itemId > 0) {
                $companyId = $this->companyIdFromBroadcastItem($itemId);
                if ($companyId !== null) {
                    return $companyId;
                }
            }
        }

        if (property_exists($command, 'reactionId')) {
            $reactionId = $command->reactionId;
            if (is_int($reactionId) && $reactionId > 0) {
                $companyId = $this->companyIdFromReaction($reactionId);
                if ($companyId !== null) {
                    return $companyId;
                }
            }
        }

        return null;
    }

    private function companyIdFromMessage(int $messageId): ?int
    {
        $companyId = Message::query()
            ->whereKey($messageId)
            ->with(['chat' => fn ($query) => $query->withoutGlobalScope('tenant')])
            ->first()
            ?->chat
            ?->company_id;

        return is_int($companyId) && $companyId > 0 ? $companyId : null;
    }

    private function companyIdFromBroadcastItem(int $itemId): ?int
    {
        $chatId = \App\Models\BroadcastCampaignItem::query()
            ->whereKey($itemId)
            ->value('chat_id');

        if (! is_int($chatId) || $chatId <= 0) {
            return null;
        }

        $companyId = Chat::query()
            ->withoutGlobalScope('tenant')
            ->whereKey($chatId)
            ->value('company_id');

        return is_int($companyId) && $companyId > 0 ? $companyId : null;
    }

    private function companyIdFromReaction(int $reactionId): ?int
    {
        $messageId = \App\Models\MessageReaction::query()
            ->whereKey($reactionId)
            ->value('message_id');

        if (! is_int($messageId) || $messageId <= 0) {
            return null;
        }

        return $this->companyIdFromMessage($messageId);
    }
}
