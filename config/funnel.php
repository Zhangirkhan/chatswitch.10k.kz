<?php

declare(strict_types=1);

return [
    /**
     * Требовать прохождение payment-этапов воронки (предоплата, реквизиты).
     * false — AI пропускает оплату и переводит сделку в работу (пока нет интеграций).
     */
    'payment_stages_required' => (bool) env('FUNNEL_PAYMENT_STAGES_REQUIRED', false),

    /** Блокировать разделы настроек для админа, пока AI readiness ≠ ready. */
    'enforce_settings_readiness_gate' => (bool) env('FUNNEL_ENFORCE_SETTINGS_READINESS', true),

    'ai' => [
        'min_confidence' => (float) env('FUNNEL_AI_MIN_CONFIDENCE', 0.65),
        'rollback_min_confidence' => (float) env('FUNNEL_AI_ROLLBACK_MIN_CONFIDENCE', 0.85),
        'debounce_seconds' => (int) env('FUNNEL_AI_DEBOUNCE_SECONDS', 5),
        'history_limit' => (int) env('FUNNEL_AI_HISTORY_LIMIT', 16),
        'temperature' => (float) env('FUNNEL_AI_TEMPERATURE', 0.15),
        'max_tokens' => (int) env('FUNNEL_AI_MAX_TOKENS', 450),
    ],
    'department_routing' => [
        'enabled' => (bool) env('FUNNEL_DEPARTMENT_ROUTING_ENABLED', true),
        'min_confidence' => (float) env('FUNNEL_DEPARTMENT_ROUTING_MIN_CONFIDENCE', 0.55),
        'keyword_min_score' => (int) env('FUNNEL_DEPARTMENT_ROUTING_KEYWORD_MIN_SCORE', 6),
        'temperature' => (float) env('FUNNEL_DEPARTMENT_ROUTING_TEMPERATURE', 0.1),
        'max_tokens' => (int) env('FUNNEL_DEPARTMENT_ROUTING_MAX_TOKENS', 350),
    ],
    'orchestrator' => [
        'debounce_seconds' => (int) env('FUNNEL_ORCHESTRATOR_DEBOUNCE_SECONDS', 3),
        'min_confidence' => (float) env('FUNNEL_ORCHESTRATOR_MIN_CONFIDENCE', 0.7),
        /** Чаты с последним run ниже порога попадают в фильтр «Внимание» (AI uncertain). */
        'attention_confidence_max' => (float) env('FUNNEL_ORCHESTRATOR_ATTENTION_CONFIDENCE_MAX', 0.85),
        'attention_run_days' => (int) env('FUNNEL_ORCHESTRATOR_ATTENTION_RUN_DAYS', 14),
        'temperature' => (float) env('FUNNEL_ORCHESTRATOR_TEMPERATURE', 0.2),
        'max_tokens' => (int) env('FUNNEL_ORCHESTRATOR_MAX_TOKENS', 1200),
        'history_limit' => (int) env('FUNNEL_ORCHESTRATOR_HISTORY_LIMIT', 18),
    ],
];
