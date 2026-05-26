<?php

declare(strict_types=1);

return [

    'enabled' => (bool) env('LOCALE_ASSISTANT_ENABLED', true),

    'lexicon_path' => resource_path('locale/lexicons'),

    'system_prompt_path' => resource_path('locale/prompts/kz_assistant_system.md'),

    'few_shot' => [
        'enabled' => (bool) env('LOCALE_FEW_SHOT_ENABLED', true),
        'count' => (int) env('LOCALE_FEW_SHOT_COUNT', 5),
        'seed_path' => resource_path('locale/examples/few_shot_seed.jsonl'),
        'min_similarity' => (float) env('LOCALE_FEW_SHOT_MIN_SIMILARITY', 0.25),
    ],

    'rag' => [
        'enabled' => (bool) env('LOCALE_RAG_ENABLED', false),
        'top_k' => (int) env('LOCALE_RAG_TOP_K', 6),
        'min_similarity' => (float) env('LOCALE_RAG_MIN_SIMILARITY', 0.28),
        'slang_score_threshold' => (float) env('LOCALE_RAG_SLANG_THRESHOLD', 0.35),
    ],

    'detection' => [
        'mixed_threshold' => 0.20,
        'dominant_threshold' => 0.55,
        'short_token_limit' => 3,
    ],

    'benchmark_path' => resource_path('locale/benchmarks/eval_cases.jsonl'),

];
