<?php

declare(strict_types=1);

return [
    /** Cache TTL for lossy conversation/knowledge compression (days). */
    'compression_cache_ttl_days' => (int) env('AI_COMPRESSION_CACHE_TTL_DAYS', 7),

    /** WhatsApp typing-indicator refresh interval (seconds; WA cap ~25 s). */
    'typing_refresh_seconds' => (int) env('AI_TYPING_REFRESH_SECONDS', 12),

    // -------------------------------------------------------------------------
    // History & context
    // -------------------------------------------------------------------------

    /**
     * Maximum characters per individual message body before truncation.
     * Raise to 1200+ for richer context; keep ≤1500 to avoid prompt bloat.
     */
    'body_limit_chars' => (int) env('AI_BODY_LIMIT_CHARS', 700),

    /**
     * Character budget for full conversation history before summarisation kicks in.
     * Raising this reduces lossy summarisation at the cost of more prompt tokens.
     */
    'history_char_budget' => (int) env('AI_HISTORY_CHAR_BUDGET', 24000),

    /**
     * Number of the most-recent AI-generated replies kept in the continuity block
     * when ai.history_includes_ai_replies flag is OFF (legacy mode).
     */
    'ai_continuity_limit' => (int) env('AI_CONTINUITY_LIMIT', 5),

    // -------------------------------------------------------------------------
    // Memory extraction (flag: ai.memory_extraction)
    // -------------------------------------------------------------------------

    /**
     * Delay in seconds before ExtractConversationMemoryJob runs after a message.
     * Debounce prevents redundant LLM calls on rapid message bursts.
     */
    'memory_extraction_debounce_seconds' => (int) env('AI_MEMORY_EXTRACTION_DEBOUNCE_SECONDS', 30),

    /**
     * Maximum characters the AI-managed facts section in memory.md may occupy.
     */
    'memory_extraction_max_chars' => (int) env('AI_MEMORY_EXTRACTION_MAX_CHARS', 8000),

    /**
     * Max tokens for the memory-extraction LLM call.
     */
    'memory_extraction_max_tokens' => (int) env('AI_MEMORY_EXTRACTION_MAX_TOKENS', 800),

    /**
     * Number of recent messages fed to the extraction call (inbound + outbound).
     * Keeping this small reduces cost; the extractor sees the delta, not the full history.
     */
    'memory_extraction_history_messages' => (int) env('AI_MEMORY_EXTRACTION_HISTORY_MESSAGES', 20),

    // -------------------------------------------------------------------------
    // Rolling summary (flag: ai.rolling_summary)
    // -------------------------------------------------------------------------

    /**
     * Max tokens for the rolling-summary LLM call.
     */
    'rolling_summary_max_tokens' => (int) env('AI_ROLLING_SUMMARY_MAX_TOKENS', 900),

    /**
     * Number of recent messages kept verbatim when history exceeds the char budget
     * (rolling-summary fallback — replaces the destructive first/last-line fallback).
     */
    'rolling_summary_fallback_keep_messages' => (int) env('AI_ROLLING_SUMMARY_FALLBACK_KEEP_MESSAGES', 15),

    // -------------------------------------------------------------------------
    // Orchestrator reliability
    // -------------------------------------------------------------------------

    /**
     * Minutes after which a RUNNING orchestrator run is considered a dead lease
     * and may be reclaimed by a retry.  Must be longer than the worst-case LLM
     * latency + action execution time.
     */
    'orchestrator_lease_timeout_minutes' => (int) env('AI_ORCHESTRATOR_LEASE_TIMEOUT_MINUTES', 5),

    // -------------------------------------------------------------------------
    // LLM retry policy
    // -------------------------------------------------------------------------

    /**
     * HTTP status codes that should trigger a retry in OpenAiChatService.
     * Comma-separated string parsed at runtime.
     */
    'retry_on_http_statuses' => env('AI_RETRY_HTTP_STATUSES', '429,500,502,503,504'),

    /**
     * Base backoff in milliseconds between retries (doubles each attempt).
     */
    'retry_base_backoff_ms' => (int) env('AI_RETRY_BASE_BACKOFF_MS', 1000),

    // -------------------------------------------------------------------------
    // First-contact acknowledgment (new inbound dialog, no prior outbound)
    // -------------------------------------------------------------------------

    'first_contact_ack' => [
        'enabled' => filter_var(env('AI_FIRST_CONTACT_ACK_ENABLED', true), FILTER_VALIDATE_BOOL),
        'debounce_seconds' => (int) env('AI_FIRST_CONTACT_ACK_DEBOUNCE_SECONDS', 1),
        'temperature' => (float) env('AI_FIRST_CONTACT_ACK_TEMPERATURE', 0.7),
        'max_tokens' => (int) env('AI_FIRST_CONTACT_ACK_MAX_TOKENS', 200),
    ],

    'win_prob' => [
        'min_training_samples' => (int) env('AI_WIN_PROB_MIN_TRAINING_SAMPLES', 200),
    ],
];
