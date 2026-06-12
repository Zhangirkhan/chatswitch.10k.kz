<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SystemSetting;
use App\Tenancy\TenantContext;

/**
 * Per-tenant feature flags for the AI Sales Agent remediation rollout.
 *
 * All flags default to OFF so changes can be enabled company-by-company.
 *
 * Enable via SystemSetting::setValue('ai.memory_extraction', '1', $companyId)
 * or via Artisan tinker for bulk rollout.
 *
 * Keys:
 *   ai.memory_extraction       - Extract facts from conversations into EntityMemory after each turn
 *   ai.history_includes_ai_replies - Include AI-generated outbound messages in conversation history
 *   ai.history_contact_scoped  - Load history from all chats belonging to the contact, not just current chat
 *   ai.rolling_summary         - Store conversation summaries in entity_memories instead of volatile cache
 *   ai.funnel_sequence_guard   - Enforce forward-sequence and WIP limits on AI funnel transitions
 *   ai.crm_writeback           - Allow AI to write enriched fields and agreements back to Contact records
 */
final class AiFeatureFlags
{
    public const MEMORY_EXTRACTION = 'ai.memory_extraction';

    public const HISTORY_INCLUDES_AI_REPLIES = 'ai.history_includes_ai_replies';

    public const HISTORY_CONTACT_SCOPED = 'ai.history_contact_scoped';

    public const ROLLING_SUMMARY = 'ai.rolling_summary';

    public const FUNNEL_SEQUENCE_GUARD = 'ai.funnel_sequence_guard';

    public const CRM_WRITEBACK = 'ai.crm_writeback';

    /** Enrich RAG query with active topic + recent inbound context for follow-up messages. */
    public const RETRIEVAL_CONTEXT_AWARE = 'ai.retrieval_context_aware';

    /** Filter RAG chunks by detected knowledge domain (delivery/pricing/…). */
    public const RETRIEVAL_DOMAIN_FILTER = 'ai.retrieval_domain_filter';

    /** Persist and inject structured sales state (qualified, budget_known, next_action, …). */
    public const SALES_STATE = 'ai.sales_state';

    /** Deterministic lead score (0–100) and grade (A/B/C) in sales_state. */
    public const LEAD_SCORING = 'ai.lead_scoring';

    /** D+2/D+7/D+14 nurture sequence after client defers («подумаем»). */
    public const NURTURE_FOLLOW_UP = 'ai.nurture_follow_up';

    /** Record deal outcomes on close and inject tenant win/loss insights into prompts. */
    public const WIN_LOSS_LEARNING = 'ai.win_loss_learning';

    public const PROMPT_EXPERIMENTS = 'ai.prompt_experiments';

    public const ML_WIN_PROB = 'ai.ml_win_prob';

    public const STAKEHOLDERS = 'ai.stakeholders';

    /** All known flag keys — used for validation and documentation. */
    public const ALL_KEYS = [
        self::MEMORY_EXTRACTION,
        self::HISTORY_INCLUDES_AI_REPLIES,
        self::HISTORY_CONTACT_SCOPED,
        self::ROLLING_SUMMARY,
        self::FUNNEL_SEQUENCE_GUARD,
        self::CRM_WRITEBACK,
        self::RETRIEVAL_CONTEXT_AWARE,
        self::RETRIEVAL_DOMAIN_FILTER,
        self::SALES_STATE,
        self::LEAD_SCORING,
        self::NURTURE_FOLLOW_UP,
        self::WIN_LOSS_LEARNING,
        self::PROMPT_EXPERIMENTS,
        self::ML_WIN_PROB,
        self::STAKEHOLDERS,
    ];

    /**
     * Check whether the flag is enabled for a given company (or the current tenant).
     */
    public static function enabled(string $flag, ?int $companyId = null): bool
    {
        $companyId ??= app(TenantContext::class)->companyIdOrNull();
        if ($companyId === null) {
            return false;
        }

        // Default is '1' — all flags are ON unless explicitly disabled via SystemSetting.
        // To disable a flag for a specific company: AiFeatureFlags::disable($flag, $companyId)
        $value = SystemSetting::getValue($flag, '1', $companyId);

        return ! in_array($value, ['0', 'false', 'off', 'no'], true);
    }

    /**
     * Enable a flag for a company (utility for artisan / seeders / tests).
     */
    public static function enable(string $flag, int $companyId): void
    {
        SystemSetting::setValue($flag, '1', $companyId);
    }

    /**
     * Disable a flag for a company.
     */
    public static function disable(string $flag, int $companyId): void
    {
        SystemSetting::setValue($flag, '0', $companyId);
    }

    /**
     * Return a snapshot of all flag states for a company.
     *
     * @return array<string, bool>
     */
    public static function snapshot(int $companyId): array
    {
        $result = [];
        foreach (self::ALL_KEYS as $key) {
            $result[$key] = self::enabled($key, $companyId);
        }

        return $result;
    }
}
