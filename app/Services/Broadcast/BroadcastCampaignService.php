<?php

declare(strict_types=1);

namespace App\Services\Broadcast;

use App\Jobs\SendBroadcastCampaignItemJob;
use App\Models\BroadcastCampaign;
use App\Models\BroadcastCampaignItem;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BroadcastCampaignService
{
    public function __construct(
        private readonly BroadcastSpreadsheetReader $spreadsheetReader,
        private readonly BroadcastRecipientResolver $recipientResolver,
        private readonly BroadcastSendRateLimiter $rateLimiter,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     summary: array{total: int, ready: int, skipped: int}
     * }
     */
    public function previewFromExcel(
        UploadedFile $file,
        User $actor,
        WhatsappSession $session,
    ): array {
        $parsed = $this->spreadsheetReader->read($file);

        return $this->previewRows($parsed, $actor, $session);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function previewFromFilters(
        array $filters,
        string $message,
        User $actor,
        WhatsappSession $session,
    ): array {
        $rows = $this->recipientResolver->rowsFromFilters($filters, $message, $actor, $session);
        $previewRows = [];
        foreach ($rows as $row) {
            $previewRows[] = [
                'row' => $row['row'],
                'phone' => $row['phone'],
                'message' => $row['message'],
                'status' => 'ready',
                'contact_id' => $row['contact_id'],
                'chat_id' => $row['chat_id'],
                'contact_name' => $row['contact_name'] ?? null,
                'skip_reason' => null,
            ];
        }

        return $this->withRateLimitMeta([
            'rows' => $previewRows,
            'summary' => [
                'total' => count($previewRows),
                'ready' => count($previewRows),
                'skipped' => 0,
            ],
        ], $session);
    }

    /**
     * @param  list<array{row: int, phone: string, message: string}>  $parsed
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     summary: array{total: int, ready: int, skipped: int}
     * }
     */
    private function previewRows(array $parsed, User $actor, WhatsappSession $session): array
    {
        $rows = [];
        $ready = 0;
        $skipped = 0;

        foreach ($parsed as $item) {
            $resolved = $this->recipientResolver->resolve($item['phone'], $actor, $session);
            if ($resolved['status'] === 'ready') {
                $ready++;
            } else {
                $skipped++;
            }
            $rows[] = [
                'row' => $item['row'],
                'phone' => $item['phone'],
                'message' => $item['message'],
                'status' => $resolved['status'],
                'contact_id' => $resolved['contact_id'],
                'chat_id' => $resolved['chat_id'],
                'contact_name' => $resolved['contact_name'],
                'skip_reason' => $resolved['skip_reason'],
            ];
        }

        return $this->withRateLimitMeta([
            'rows' => $rows,
            'summary' => [
                'total' => count($rows),
                'ready' => $ready,
                'skipped' => $skipped,
            ],
        ], $session);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withRateLimitMeta(array $payload, WhatsappSession $session): array
    {
        $payload['rate_limit'] = $this->rateLimiter->snapshot($session->id);

        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>|null  $previewRows
     * @param  array<string, mixed>  $filters
     */
    public function start(
        User $creator,
        User $sender,
        WhatsappSession $session,
        string $source,
        ?UploadedFile $file,
        ?array $filters,
        ?string $filterMessage,
        ?array $previewRows,
    ): BroadcastCampaign {
        if (! $creator->can('use', $session)) {
            throw ValidationException::withMessages([
                'whatsapp_session_id' => ['Этот WhatsApp-номер вам недоступен.'],
            ]);
        }

        if ($source === BroadcastCampaign::SOURCE_EXCEL) {
            if ($file === null) {
                throw ValidationException::withMessages(['file' => ['Загрузите файл Excel/CSV.']]);
            }
            $parsed = $this->spreadsheetReader->read($file);
            $built = $this->previewRows($parsed, $creator, $session);
            $previewRows = $built['rows'];
        } elseif ($source === BroadcastCampaign::SOURCE_FILTERS) {
            $built = $this->previewFromFilters($filters ?? [], (string) $filterMessage, $creator, $session);
            $previewRows = $built['rows'];
        } else {
            throw ValidationException::withMessages(['source' => ['Неизвестный источник рассылки.']]);
        }

        if ($previewRows === [] || $previewRows === null) {
            throw ValidationException::withMessages([
                'file' => ['Нет получателей для рассылки.'],
            ]);
        }

        $readyCount = collect($previewRows)->where('status', 'ready')->count();
        if ($readyCount === 0) {
            throw ValidationException::withMessages([
                'file' => ['Нет подходящих получателей (нужны закрытые чаты в системе).'],
            ]);
        }

        $this->rateLimiter->assertCanSchedule($session->id, $readyCount);
        $delaySeconds = $this->rateLimiter->delayBetweenMessages();

        return DB::transaction(function () use (
            $creator,
            $sender,
            $session,
            $source,
            $delaySeconds,
            $filters,
            $filterMessage,
            $previewRows,
            $readyCount,
        ): BroadcastCampaign {
            $scheduleAt = $this->rateLimiter->nextScheduleSlot($session->id);
            $campaign = BroadcastCampaign::query()->create([
                'created_by_user_id' => $creator->id,
                'sender_user_id' => $sender->id,
                'whatsapp_session_id' => $session->id,
                'source' => $source,
                'status' => BroadcastCampaign::STATUS_RUNNING,
                'delay_seconds' => $delaySeconds,
                'filter_message' => $filterMessage,
                'filters' => $filters,
                'total_rows' => count($previewRows),
                'ready_count' => $readyCount,
                'started_at' => now(),
            ]);

            foreach ($previewRows as $row) {
                $isReady = ($row['status'] ?? '') === 'ready';
                $scheduledAt = null;
                if ($isReady) {
                    $scheduledAt = $scheduleAt->copy();
                    $scheduleAt = $scheduleAt->addSeconds($delaySeconds);
                }

                $item = BroadcastCampaignItem::query()->create([
                    'broadcast_campaign_id' => $campaign->id,
                    'row_number' => (int) ($row['row'] ?? 0),
                    'phone_raw' => (string) ($row['phone'] ?? ''),
                    'phone_digits' => isset($row['phone']) ? preg_replace('/\D/', '', (string) $row['phone']) : null,
                    'message_text' => (string) ($row['message'] ?? ''),
                    'contact_id' => $row['contact_id'] ?? null,
                    'chat_id' => $row['chat_id'] ?? null,
                    'status' => $isReady ? BroadcastCampaignItem::STATUS_PENDING : BroadcastCampaignItem::STATUS_SKIPPED,
                    'skip_reason' => $isReady ? null : (string) ($row['skip_reason'] ?? 'Пропущено'),
                    'scheduled_at' => $scheduledAt,
                ]);

                if ($isReady && $scheduledAt !== null) {
                    SendBroadcastCampaignItemJob::dispatch($item->id)->delay($scheduledAt);
                } else {
                    $campaign->increment('skipped_count');
                }
            }

            return $campaign->fresh(['whatsappSession', 'sender', 'createdBy']);
        });
    }

    public function refreshCounters(BroadcastCampaign $campaign): void
    {
        $sent = $campaign->items()->where('status', BroadcastCampaignItem::STATUS_SENT)->count();
        $failed = $campaign->items()->where('status', BroadcastCampaignItem::STATUS_FAILED)->count();
        $skipped = $campaign->items()->where('status', BroadcastCampaignItem::STATUS_SKIPPED)->count();

        $campaign->forceFill([
            'sent_count' => $sent,
            'failed_count' => $failed,
            'skipped_count' => $skipped,
        ]);

        $pending = $campaign->items()->where('status', BroadcastCampaignItem::STATUS_PENDING)->count();
        if ($pending === 0 && $campaign->status === BroadcastCampaign::STATUS_RUNNING) {
            $campaign->status = BroadcastCampaign::STATUS_COMPLETED;
            $campaign->completed_at = now();
        }

        $campaign->save();
    }
}
