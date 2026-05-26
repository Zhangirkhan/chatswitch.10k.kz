<?php

declare(strict_types=1);

return [

    'enabled' => filter_var(env('RECAPTCHA_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'site_key' => env('RECAPTCHA_SITE_KEY'),

    'secret_key' => env('RECAPTCHA_SECRET_KEY'),

    /** v2 — виджет «Я не робот»; v3 — невидимая проверка со score */
    'version' => env('RECAPTCHA_VERSION', 'v3'),

    /** Минимальный score для v3 (0.0–1.0) */
    'min_score' => (float) env('RECAPTCHA_MIN_SCORE', 0.5),

    'verify_url' => 'https://www.google.com/recaptcha/api/siteverify',

];
