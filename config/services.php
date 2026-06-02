<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'whatsapp' => [
        'url' => env('WHATSAPP_SERVICE_URL', 'http://127.0.0.1:3050'),
        'token' => env('WHATSAPP_SERVICE_TOKEN', ''),
        // Общий bearer-токен, которым Node-сервис авторизуется у Laravel (совпадает с LARAVEL_API_TOKEN в whatsapp-service/.env)
        'service_token' => env('WHATSAPP_SERVICE_TOKEN', ''),
        'webhook_secret' => env('WHATSAPP_WEBHOOK_SECRET', ''),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-5.5'),
        /** Демо-тенант: доп. лимиты токенов и таймаут (модель — та же OPENAI_MODEL). */
        'demo_slug' => env('TENANCY_FALLBACK_SLUG', 'demo'),
        'default_max_tokens' => (int) env('OPENAI_DEFAULT_MAX_TOKENS', 900),
        'demo_max_tokens_multiplier' => (float) env('OPENAI_DEMO_MAX_TOKENS_MULTIPLIER', 2),
        'demo_max_tokens_cap' => (int) env('OPENAI_DEMO_MAX_TOKENS_CAP', 4096),
        'demo_timeout' => (int) env('OPENAI_DEMO_TIMEOUT', 90),
        'timeout' => (int) env('OPENAI_TIMEOUT', 90),
        'transcribe_timeout' => (int) env('OPENAI_TRANSCRIBE_TIMEOUT', 180),
        'whisper_language' => env('OPENAI_WHISPER_LANGUAGE'),
    ],

];
