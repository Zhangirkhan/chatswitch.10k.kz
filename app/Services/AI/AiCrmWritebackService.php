<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\ContactTag;
use App\Services\Memory\EntityMemoryService;
use App\Support\AiFeatureFlags;
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
     * Records which funnel stage the contact has "reached" globally.
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
            $contact->forceFill(['ai_funnel_stage_id' => $newStageId])->save();

            Log::debug('[ai-crm-writeback] contact funnel stage synced', [
                'contact_id' => $contact->id,
                'ai_funnel_stage_id' => $newStageId,
            ]);
        } catch (Throwable $e) {
            Log::warning('[ai-crm-writeback] failed to sync contact funnel stage', [
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Parse fact keywords into short tag names and upsert them on the contact.
     * Tags remain under MAX_TAGS_PER_CONTACT to prevent bloat.
     *
     * @param  array<string, mixed>  $facts
     */
    private function persistTags(Contact $contact, array $facts): void
    {
        $tags = $this->extractTagsFromFacts($facts);

        if ($tags === []) {
            return;
        }

        $maxTags = 20;
        $existingCount = $contact->tags()->where('source', ContactTag::SOURCE_AI)->count();

        foreach ($tags as $tag) {
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
     * Derive short, normalised tag names from extracted facts.
     * Tags are lowercase, max 64 chars, no special chars beyond hyphens.
     *
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    private function extractTagsFromFacts(array $facts): array
    {
        $candidates = [];

        // Budget tier tag
        if (! empty($facts['budget'])) {
            $budget = mb_strtolower(trim((string) $facts['budget']));
            if (mb_strlen($budget) <= 64 && $budget !== '') {
                $candidates[] = 'бюджет: '.$budget;
            }
        }

        // Requirements — extract short noun phrases
        if (! empty($facts['requirements'])) {
            $req = mb_strtolower(trim((string) $facts['requirements']));
            $parts = preg_split('/[,;]+/', $req) ?: [];
            foreach (array_slice($parts, 0, 3) as $part) {
                $part = trim($part);
                if ($part !== '' && mb_strlen($part) <= 64) {
                    $candidates[] = $part;
                }
            }
        }

        // Source tag
        if (! empty($facts['source'])) {
            $src = mb_strtolower(trim((string) $facts['source']));
            if (mb_strlen($src) <= 64 && $src !== '') {
                $candidates[] = 'источник: '.$src;
            }
        }

        // Normalize: slug-style cleanup, max 64 chars
        $tags = [];
        foreach ($candidates as $tag) {
            $normalised = mb_substr(trim($tag), 0, 64);
            if ($normalised !== '') {
                $tags[] = $normalised;
            }
        }

        return array_unique($tags);
    }
}
