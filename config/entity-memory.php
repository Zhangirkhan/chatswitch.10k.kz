<?php

declare(strict_types=1);

return [
    'max_content_chars' => (int) env('ENTITY_MEMORY_MAX_CHARS', 50000),
    'max_backups_kept' => (int) env('ENTITY_MEMORY_MAX_BACKUPS', 50),
    'sync_files' => (bool) env('ENTITY_MEMORY_SYNC_FILES', true),
    'disk' => env('ENTITY_MEMORY_DISK', 'local'),
    'base_path' => 'entity-memory',
];
