<?php

declare(strict_types=1);

return [
    /** Кэш сжатия длинной истории / каталога в промпте (дни). */
    'compression_cache_ttl_days' => (int) env('AI_COMPRESSION_CACHE_TTL_DAYS', 7),

    /** Повторный индикатор «печатает…» в WhatsApp (сек.; лимит WA ~25 с). */
    'typing_refresh_seconds' => (int) env('AI_TYPING_REFRESH_SECONDS', 12),
];
