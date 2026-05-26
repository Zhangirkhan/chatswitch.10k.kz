<?php

declare(strict_types=1);

return [

    /**
     * Посты-задачи по отделам в разделе «Организация» (вкладка «Задачи», архив, задачи из чата).
     * Внутренний чат отделов (вкладка «Чат») управляется отдельно — модулем module_tasks.
     */
    'organization_department_tasks' => filter_var(
        env('ORGANIZATION_DEPARTMENT_TASKS', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

    'system_user_email' => env('SYSTEM_USER_EMAIL', 'system@chatswitch.internal'),

    /** IP, с которых разрешены /api/whatsapp/* (Node-сервис). */
    'whatsapp_service_ips' => array_values(array_filter(array_map(
        static fn (string $ip): string => trim($ip),
        explode(',', (string) env('WHATSAPP_SERVICE_ALLOWED_IPS', '127.0.0.1,::1'))
    ))),

    /** MIME для POST /api/whatsapp/inbound-media */
    'inbound_media_mimetypes' => [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'video/mp4', 'video/webm', 'video/quicktime', 'video/3gpp',
        'audio/mpeg', 'audio/ogg', 'audio/mp4', 'audio/aac', 'audio/wav', 'audio/webm',
        'application/pdf', 'application/octet-stream',
    ],

];
