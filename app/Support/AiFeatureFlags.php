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

    /** All known flag keys — used for validation and documentation. */
    public const ALL_KEYS = [
        self::MEMORY_EXTRACTION,
        self::HISTORY_INCLUDES_AI_REPLIES,
        self::HISTORY_CONTACT_SCOPED,
        self::ROLLING_SUMMARY,
        self::FUNNEL_SEQUENCE_GUARD,
        self::CRM_WRITEBACK,
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

        $value = SystemSetting::getValue($flag, '0', $companyId);

        return in_array($value, ['1', 'true', 'on', 'yes'], true);
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
