<?php

declare(strict_types=1);

return [
    'ai' => [
        'min_confidence' => (float) env('FUNNEL_AI_MIN_CONFIDENCE', 0.65),
        'rollback_min_confidence' => (float) env('FUNNEL_AI_ROLLBACK_MIN_CONFIDENCE', 0.85),
        'debounce_seconds' => (int) env('FUNNEL_AI_DEBOUNCE_SECONDS', 45),
        'history_limit' => (int) env('FUNNEL_AI_HISTORY_LIMIT', 16),
        'temperature' => (float) env('FUNNEL_AI_TEMPERATURE', 0.15),
        'max_tokens' => (int) env('FUNNEL_AI_MAX_TOKENS', 450),
    ],
];
