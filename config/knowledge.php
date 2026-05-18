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
    ],

];
