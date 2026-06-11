<?php

declare(strict_types=1);

return [

    'default_locale' => 'kk',

    'supported_locales' => ['kk', 'ru', 'en'],

    'cookie_name' => 'landing_locale',

    'cookie_minutes' => 525_600,

    'meta' => [
        'kk' => [
            'title' => 'Accel — командаға WhatsApp',
            'description' => 'Бүкіл командаға WhatsApp: чат, AI автопилот, воронкалар, тапсырмалар және аналитика — бір терезеде, телефон хаосысыз.',
            'og_image' => '/icons/icon-512.png',
        ],
        'ru' => [
            'title' => 'Accel — WhatsApp для команды',
            'description' => 'WhatsApp для всей команды: чаты, AI-автопилот, воронки, задачи и аналитика — в одном окне, без хаоса в телефонах.',
            'og_image' => '/icons/icon-512.png',
        ],
        'en' => [
            'title' => 'Accel — WhatsApp for teams',
            'description' => 'WhatsApp for your whole team: chats, AI autopilot, funnels, tasks, and analytics — one workspace, no phone chaos.',
            'og_image' => '/icons/icon-512.png',
        ],
    ],

];
