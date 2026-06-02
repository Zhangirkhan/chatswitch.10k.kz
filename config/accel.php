<?php

declare(strict_types=1);

return [

    /**
     * Посты-задачи по отделам в разделе «Организация» (вкладка «Задачи», архив, задачи из чата).
     * Внутренний чат отделов (вкладка «Чат») управляется отдельно — модулем module_tasks.
     */
    'organization_department_tasks' => filter_var(
        env('ORGANIZATION_DEPARTMENT_TASKS', true),
        FILTER_VALIDATE_BOOLEAN,
    ),

    'system_user_email' => env('SYSTEM_USER_EMAIL', 'system@chatswitch.internal'),

    /** IP, с которых разрешены /api/whatsapp/* (Node-сервис). */
    'whatsapp_service_ips' => array_values(array_filter(array_map(
        static fn (string $ip): string => trim($ip),
        explode(',', (string) env('WHATSAPP_SERVICE_ALLOWED_IPS', '127.0.0.1,::1'))
    ))),

    /** Расшифровка входящих голосовых (Whisper) для оператора и AI. */
    'transcribe_audio' => filter_var(env('ACCEL_TRANSCRIBE_AUDIO', true), FILTER_VALIDATE_BOOLEAN),

    /** Автоответ AI на входящие голосовые (после расшифровки). */
    'ai_voice_replies' => filter_var(env('ACCEL_AI_VOICE_REPLIES', true), FILTER_VALIDATE_BOOLEAN),

    /**
     * Минуты простоя в чате перед автоответом AI на входящее (0 = сразу, как раньше).
     * Не отвечает на «спасибо» / короткие подтверждения; после ответа менеджера ждёт простоя или ручного включения AI.
     */
    'ai_idle_reply_minutes' => max(0, (int) env('ACCEL_AI_IDLE_REPLY_MINUTES', 10)),

    'whisper_model' => env('OPENAI_WHISPER_MODEL', 'whisper-1'),

    /** Язык Whisper по умолчанию: auto, ru или kk. auto не отправляет language и даёт Whisper определить речь. */
    'whisper_default_language' => env('ACCEL_WHISPER_DEFAULT_LANGUAGE', 'auto'),

    /** Если true — для явно русских/казахских чатов language подставится по истории, иначе используется default. */
    'whisper_auto_detect_language' => filter_var(env('ACCEL_WHISPER_AUTO_DETECT_LANGUAGE', true), FILTER_VALIDATE_BOOLEAN),

    /** Нейтральная подсказка Whisper для ru/kk/mixed без языкового перекоса. */
    'whisper_prompt_auto' => env('ACCEL_WHISPER_PROMPT_AUTO')
        ?: 'Транскрибируй речь дословно. Язык: русский, казахский или смешанный.',

    /** Для голосовых: auto — Whisper сам определяет речь; ru/kk — только если нет контекста в чате. */
    'whisper_voice_fallback_language' => env('ACCEL_WHISPER_VOICE_FALLBACK_LANGUAGE', 'auto'),

    'whisper_prompt_kk' => env('ACCEL_WHISPER_PROMPT_KK')
        ?: 'Қазақша сөйлеу. Тек айтылған сөздерді жаз.',

    'whisper_prompt_ru' => env('ACCEL_WHISPER_PROMPT_RU')
        ?: 'Русская речь. Транскрибируй дословно.',

    /** Не отправлять в Whisper короче N секунд (metadata.media.duration). */
    'transcribe_min_duration_seconds' => max(0, (int) env('ACCEL_TRANSCRIBE_MIN_DURATION_SECONDS', 1)),

    /** Не отправлять в Whisper длиннее N секунд. */
    'transcribe_max_duration_seconds' => max(1, (int) env('ACCEL_TRANSCRIBE_MAX_DURATION_SECONDS', 600)),

    /** MIME для POST /api/whatsapp/inbound-media */
    'inbound_media_mimetypes' => [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'video/mp4', 'video/webm', 'video/quicktime', 'video/3gpp',
        'audio/mpeg', 'audio/ogg', 'audio/mp4', 'audio/aac', 'audio/wav', 'audio/webm',
        'application/pdf', 'application/octet-stream',
    ],

    'queue_monitor' => [
        /** Warn when this many (or more) jobs failed within the lookback window. */
        'recent_max' => max(0, (int) env('ACCEL_QUEUE_MONITOR_RECENT_MAX', 3)),
        /** Substrings matched against failed job payload to always warn. */
        'critical_jobs' => [
            'RunAiFunnelOrchestratorJob',
            'GenerateAiReplyJob',
            'ProcessWhatsappInboundJob',
            'SendOutboundMessageJob',
        ],
    ],

];
