<?php

declare(strict_types=1);

return [

    'rag' => [
        'enabled' => (bool) env('KNOWLEDGE_RAG_ENABLED', true),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'top_k' => (int) env('KNOWLEDGE_RAG_TOP_K', 12),
        'min_similarity' => (float) env('KNOWLEDGE_RAG_MIN_SIMILARITY', 0.30),
        'max_rules' => (int) env('KNOWLEDGE_RAG_MAX_RULES', 5),
        'min_query_length' => 3,
        'min_quality_score' => (float) env('KNOWLEDGE_RAG_MIN_QUALITY_SCORE', 0.30),
        'quality_penalty' => (float) env('KNOWLEDGE_RAG_QUALITY_PENALTY', 0.12),
    ],

    'quality' => [
        'retrieval_norm_cap' => (int) env('KNOWLEDGE_QUALITY_RETRIEVAL_NORM_CAP', 50),
        'low_score_threshold' => (float) env('KNOWLEDGE_QUALITY_LOW_THRESHOLD', 40),
    ],

];
