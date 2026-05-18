<?php

declare(strict_types=1);

return [
    'ai' => [
        'min_confidence' => (float) env('FUNNEL_AI_MIN_CONFIDENCE', 0.65),
        'rollback_min_confidence' => (float) env('FUNNEL_AI_ROLLBACK_MIN_CONFIDENCE', 0.85),
        'debounce_seconds' => (int) env('FUNNEL_AI_DEBOUNCE_SECONDS', 5),
        'history_limit' => (int) env('FUNNEL_AI_HISTORY_LIMIT', 16),
        'temperature' => (float) env('FUNNEL_AI_TEMPERATURE', 0.15),
        'max_tokens' => (int) env('FUNNEL_AI_MAX_TOKENS', 450),
    ],
    'orchestrator' => [
        'debounce_seconds' => (int) env('FUNNEL_ORCHESTRATOR_DEBOUNCE_SECONDS', 3),
        'min_confidence' => (float) env('FUNNEL_ORCHESTRATOR_MIN_CONFIDENCE', 0.7),
        'temperature' => (float) env('FUNNEL_ORCHESTRATOR_TEMPERATURE', 0.2),
        'max_tokens' => (int) env('FUNNEL_ORCHESTRATOR_MAX_TOKENS', 1200),
        'history_limit' => (int) env('FUNNEL_ORCHESTRATOR_HISTORY_LIMIT', 18),
    ],
];
