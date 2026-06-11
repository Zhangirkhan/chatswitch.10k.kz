<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\EntityMemorySubjectType;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\ContactTag;
use App\Models\FunnelStage;
use App\Services\Memory\EntityMemoryService;
use App\Support\AiFeatureFlags;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Persists AI-extracted enrichment data back to CRM records.
 *
 * Activated only when the ai.crm_writeback feature flag is enabled for the tenant.
 *
 * Responsibilities:
 *  - CRM2: persist AI-generated tags (budget tier, interest, status keywords) on Contact
 *  - CRM3: sync the contact-level funnel stage when AI advances a chat
 *  - CRM4: mark contact as AI-enriched with a timestamp for visibility in the UI
 *  - CRM5: record agreements as a special tag/note visible in the contact panel
 */
final class AiCrmWritebackService
{
    public function __construct(
        private readonly EntityMemoryService $entityMemories,
    ) {}

    /**
     * Write AI-extracted facts into the contact CRM record.
     * Safe to call even when the flag is off (no-op).
     *
     * @param  array<string, mixed>  $facts
     */
    public function writeContactEnrichment(Chat $chat, array $facts): void
    {
        if (! AiFeatureFlags::enabled(AiFeatureFlags::CRM_WRITEBACK, $chat->company_id)) {
            return;
        }

        $contact = $chat->contact;
        if (! $contact instanceof Contact) {
            return;
        }

        try {
            $this->persistTags($contact, $facts);
            $this->reconcileWithEntityMemory($contact);
            $this->updateEnrichedTimestamp($contact);
        } catch (Throwable $e) {
            Log::warning('[ai-crm-writeback] failed to write contact enrichment', [
                'chat_id' => $chat->id,
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sync the contact-level funnel stage when a chat's stage changes via AI.
     *
     * Max-stage-aware: only advances contact.ai_funnel_stage_id when the new
     * stage's position is greater than the current contact stage position.
     * This prevents a new chat from rolling back a contact who already reached
     * a later stage in another chat.
     */
    public function syncContactFunnelStage(Chat $chat, int $newStageId): void
    {
        if (! AiFeatureFlags::enabled(AiFeatureFlags::CRM_WRITEBACK, $chat->company_id)) {
            return;
        }

        $contact = $chat->contact;
        if (! $contact instanceof Contact) {
            return;
        }

        try {
            $newStage = FunnelStage::query()->find($newStageId);
            if ($newStage === null) {
                return;
            }

            // Wrap in a transaction with a lock to prevent race conditions when
            // multiple chats for the same contact complete orchestration concurrently.
            DB::transaction(function () use ($contact, $newStageId, $newStage): void {
                // Re-fetch the contact with a row-level lock.
                $lockedContact = \App\Models\Contact::query()
                    ->lockForUpdate()
                    ->find($contact->id);

                if ($lockedContact === null) {
                    return;
                }

                // Determine the highest funnel stage position across all contact chats.
                $maxPositionAcrossChats = DB::table('chats')
                    ->join('funnel_stages', 'chats.funnel_stage_id', '=', 'funnel_stages.id')
                    ->where('chats.contact_id', $lockedContact->id)
                    ->max('funnel_stages.position');

                // Also respect the contact's own existing ai_funnel_stage_id.
                $currentContactStagePosition = null;
                if ($lockedContact->ai_funnel_stage_id !== null) {
                    $currentContactStagePosition = FunnelStage::query()
                        ->where('id', $lockedContact->ai_funnel_stage_id)
                        ->value('position');
                }

                $maxPosition = max(
                    (int) ($maxPositionAcrossChats ?? 0),
                    (int) ($currentContactStagePosition ?? 0),
                );

                if ((int) $newStage->position >= $maxPosition) {
                    $lockedContact->forceFill(['ai_funnel_stage_id' => $newStageId])->save();

                    Log::debug('[ai-crm-writeback] contact funnel stage synced (idempotent max-stage)', [
                        'contact_id'            => $lockedContact->id,
                        'ai_funnel_stage_id'    => $newStageId,
                        'new_stage_position'    => $newStage->position,
                        'max_existing_position' => $maxPosition,
                    ]);
                } else {
                    Log::debug('[ai-crm-writeback] contact funnel stage NOT updated (rollback prevented)', [
                        'contact_id'            => $lockedContact->id,
                        'new_stage_id'          => $newStageId,
                        'new_stage_position'    => $newStage->position,
                        'max_existing_position' => $maxPosition,
                    ]);
                }
            });
        } catch (Throwable $e) {
            Log::warning('[ai-crm-writeback] failed to sync contact funnel stage', [
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Persist tags on the contact from extracted facts.
     *
     * Single-value facts (budget, source) are treated as "replace" — stale
     * tags with the same prefix are deleted before the new one is inserted.
     * This prevents accumulating outdated "бюджет: …" tags over time.
     *
     * Multi-value facts (requirements) are upserted without replacement.
     *
     * @param  array<string, mixed>  $facts
     */
    private function persistTags(Contact $contact, array $facts): void
    {
        ['replace' => $replaceTags, 'append' => $appendTags] = $this->extractTagsFromFacts($facts);

        // --- Replace stale single-value tags (budget, source) ---
        foreach ($replaceTags as $prefix => $newValue) {
            ContactTag::query()
                ->where('company_id', $contact->company_id)
                ->where('contact_id', $contact->id)
                ->where('source', ContactTag::SOURCE_AI)
                ->where('name', 'like', $prefix.'%')
                ->delete();

            ContactTag::query()->firstOrCreate(
                [
                    'company_id' => $contact->company_id,
                    'contact_id' => $contact->id,
                    'name' => $newValue,
                ],
                ['source' => ContactTag::SOURCE_AI],
            );
        }

        // --- Upsert multi-value tags (requirements keywords) ---
        $maxTags = 20;
        $existingCount = $contact->tags()->where('source', ContactTag::SOURCE_AI)->count();

        foreach ($appendTags as $tag) {
            if ($existingCount >= $maxTags) {
                break;
            }

            $created = ContactTag::query()->firstOrCreate(
                [
                    'company_id' => $contact->company_id,
                    'contact_id' => $contact->id,
                    'name' => $tag,
                ],
                ['source' => ContactTag::SOURCE_AI],
            );

            if ($created->wasRecentlyCreated) {
                $existingCount++;
            }
        }
    }

    private function updateEnrichedTimestamp(Contact $contact): void
    {
        $contact->forceFill(['ai_enriched_at' => now()])->save();
    }

    /**
     * Derive tag sets from extracted facts, split by replacement strategy.
     *
     * Returns:
     *  'replace' => [prefix => full_tag_name]  (budget, source — single-value, replace stale)
     *  'append'  => [tag_name, …]              (requirements keywords — multi-value, upsert)
     *
     * @param  array<string, mixed>  $facts
     * @return array{replace: array<string, string>, append: list<string>}
     */
    private function extractTagsFromFacts(array $facts): array
    {
        $replace = [];
        $append  = [];

        // Budget — single-value; replace any existing "бюджет: …" tag.
        if (! empty($facts['budget'])) {
            $budget = mb_strtolower(trim((string) $facts['budget']));
            if ($budget !== '' && mb_strlen($budget) <= 50) {
                $replace['бюджет: '] = mb_substr('бюджет: '.$budget, 0, 64);
            }
        }

        // Source — single-value; replace any existing "источник: …" tag.
        if (! empty($facts['source'])) {
            $src = mb_strtolower(trim((string) $facts['source']));
            if ($src !== '' && mb_strlen($src) <= 50) {
                $replace['источник: '] = mb_substr('источник: '.$src, 0, 64);
            }
        }

        // Timeline — single-value; replace any existing "срок: …" tag.
        if (! empty($facts['timeline'])) {
            $timeline = mb_strtolower(trim((string) $facts['timeline']));
            if ($timeline !== '' && mb_strlen($timeline) <= 50) {
                $replace['срок: '] = mb_substr('срок: '.$timeline, 0, 64);
            }
        }

        // Decision maker — single-value; replace any existing "решает: …" tag.
        if (! empty($facts['decision_maker'])) {
            $dm = mb_strtolower(trim((string) $facts['decision_maker']));
            if ($dm !== '' && mb_strlen($dm) <= 50) {
                $replace['решает: '] = mb_substr('решает: '.$dm, 0, 64);
            }
        }

        // Requirements — multi-value keyword list; upsert without replacement.
        if (! empty($facts['requirements'])) {
            $req = mb_strtolower(trim((string) $facts['requirements']));
            $parts = preg_split('/[,;]+/', $req) ?: [];
            foreach (array_slice($parts, 0, 3) as $part) {
                $part = mb_substr(trim($part), 0, 64);
                if ($part !== '') {
                    $append[] = $part;
                }
            }
        }

        // Objections — multi-value; append unique objection tags for manager visibility.
        if (! empty($facts['objections'])) {
            $obj = mb_strtolower(trim((string) $facts['objections']));
            $parts = preg_split('/[,;]+/', $obj) ?: [];
            foreach (array_slice($parts, 0, 3) as $part) {
                $part = mb_substr(trim($part), 0, 64);
                if ($part !== '') {
                    $append[] = 'возражение: '.$part;
                }
            }
        }

        // Agreements — single-value; replace "договор: …" tag so manager sees latest.
        if (! empty($facts['agreements'])) {
            $agr = mb_strtolower(trim((string) $facts['agreements']));
            // Truncate longer agreements to 60 chars for the tag.
            $agr = mb_substr($agr, 0, 60);
            if ($agr !== '') {
                $replace['договор: '] = mb_substr('договор: '.$agr, 0, 64);
            }
        }

        return ['replace' => $replace, 'append' => array_unique($append)];
    }

    /**
     * Reconcile EntityMemory AI-facts with the contact record.
     * Called after tags are persisted to ensure the CRM reflects the full
     * accumulated memory picture (not just the most recent extraction batch).
     */
    private function reconcileWithEntityMemory(Contact $contact): void
    {
        try {
            $existing = $this->entityMemories->readAiFacts(
                EntityMemorySubjectType::Contact,
                $contact->id,
            );

            if ($existing === []) {
                return;
            }

            // Reconcile single-value tags from the full EntityMemory (authoritative source).
            $singleValueTags = [
                'budget'       => 'бюджет: ',
                'timeline'     => 'срок: ',
                'decision_maker' => 'решает: ',
                'agreements'   => 'договор: ',
            ];

            foreach ($singleValueTags as $factKey => $tagPrefix) {
                if (empty($existing[$factKey])) {
                    continue;
                }
                $val = mb_strtolower(mb_substr(trim((string) $existing[$factKey]), 0, 55));
                if ($val === '') {
                    continue;
                }

                ContactTag::query()
                    ->where('company_id', $contact->company_id)
                    ->where('contact_id', $contact->id)
                    ->where('source', ContactTag::SOURCE_AI)
                    ->where('name', 'like', $tagPrefix.'%')
                    ->delete();

                ContactTag::query()->firstOrCreate(
                    [
                        'company_id' => $contact->company_id,
                        'contact_id' => $contact->id,
                        'name' => mb_substr($tagPrefix.$val, 0, 64),
                    ],
                    ['source' => ContactTag::SOURCE_AI],
                );
            }
        } catch (Throwable $e) {
            Log::debug('[ai-crm-writeback] entity-memory reconcile skipped', [
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
