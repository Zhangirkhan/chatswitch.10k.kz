<?php

declare(strict_types=1);

return [

    'default_locale' => 'kk',

    'supported_locales' => ['kk', 'ru', 'en'],

    'cookie_name' => 'landing_locale',

    'cookie_minutes' => 525_600,

    'og_image_width' => 1200,

    'og_image_height' => 630,

    'og_locale_map' => [
        'kk' => 'kk_KZ',
        'ru' => 'ru_RU',
        'en' => 'en_US',
    ],

    'google_site_verification' => env('LANDING_GOOGLE_VERIFICATION'),

    'yandex_verification' => env('LANDING_YANDEX_VERIFICATION'),

    'yandex_metrika_id' => env('LANDING_YANDEX_METRIKA_ID'),

    'google_analytics_id' => env('LANDING_GOOGLE_ANALYTICS_ID'),

    'pages' => [
        'home' => [
            'path' => '/',
            'kk' => [
                'title' => 'Accel — командаға WhatsApp',
                'description' => 'Бүкіл командаға WhatsApp: чат, AI автопилот, воронкалар, тапсырмалар және аналитика — бір терезеде, телефон хаосысыз.',
                'og_image' => '/og/accel-landing-kk.svg',
            ],
            'ru' => [
                'title' => 'Accel — WhatsApp для команды',
                'description' => 'WhatsApp для всей команды: чаты, AI-автопилот, воронки, задачи и аналитика — в одном окне, без хаоса в телефонах.',
                'og_image' => '/og/accel-landing-ru.svg',
            ],
            'en' => [
                'title' => 'Accel — WhatsApp for teams',
                'description' => 'WhatsApp for your whole team: chats, AI autopilot, funnels, tasks, and analytics — one workspace, no phone chaos.',
                'og_image' => '/og/accel-landing-en.svg',
            ],
        ],
        'calculator' => [
            'path' => '/calculator',
            'kk' => [
                'title' => 'AI калькуляторы — Accel',
                'description' => 'OpenAI токендерінің айлық шығынын есептеңіз: AI автопилот, Whisper, аударма және сценарийлер Accel платформасында.',
                'og_image' => '/og/accel-landing-kk.svg',
            ],
            'ru' => [
                'title' => 'Калькулятор AI — Accel',
                'description' => 'Рассчитайте месячный расход на OpenAI-токены: AI-автопилот, Whisper, переводы и сценарии на платформе Accel.',
                'og_image' => '/og/accel-landing-ru.svg',
            ],
            'en' => [
                'title' => 'AI calculator — Accel',
                'description' => 'Estimate monthly OpenAI token spend: AI autopilot, Whisper, translations and scenarios on the Accel platform.',
                'og_image' => '/og/accel-landing-en.svg',
            ],
        ],
    ],

    /** @deprecated Use landing.pages.home.{locale} — kept for backward compatibility in tests */
    'meta' => [
        'kk' => [
            'title' => 'Accel — командаға WhatsApp',
            'description' => 'Бүкіл командаға WhatsApp: чат, AI автопилот, воронкалар, тапсырмалар және аналитика — бір терезеде, телефон хаосысыз.',
            'og_image' => '/og/accel-landing-kk.svg',
        ],
        'ru' => [
            'title' => 'Accel — WhatsApp для команды',
            'description' => 'WhatsApp для всей команды: чаты, AI-автопилот, воронки, задачи и аналитика — в одном окне, без хаоса в телефонах.',
            'og_image' => '/og/accel-landing-ru.svg',
        ],
        'en' => [
            'title' => 'Accel — WhatsApp for teams',
            'description' => 'WhatsApp for your whole team: chats, AI autopilot, funnels, tasks, and analytics — one workspace, no phone chaos.',
            'og_image' => '/og/accel-landing-en.svg',
        ],
    ],

    'structured' => [
        'faq' => [
            'kk' => [
                ['question' => 'Accel деген не?', 'answer' => 'Accel — командаңыздың WhatsApp перепискасын, AI автопилотты, воронкалар мен тапсырмаларды бір терезеде басқару платформасы.'],
                ['question' => 'AI қанша тұрады?', 'answer' => 'AI токендері пайдалану бойынша бөлек төленеді. Айлық шығынды AI калькуляторы арқылы бағалауға болады.'],
                ['question' => 'Қораптық орнату деген не?', 'answer' => 'Бұл платформаны 1 000 000 ₸ бір рет төлеп орнату. Ай сайынғы абоненттік төлем жоқ, бірақ AI токендері бөлек.'],
                ['question' => 'Триал бар ма?', 'answer' => 'Стандарт тарифте қосылғаннан кейін 14 күн тегін. Коробочная установка триалсыз — төлемнен кейін толық қолжетімділік.'],
                ['question' => 'Бірнеше WhatsApp нөмірін қосуға бола ма?', 'answer' => 'Иә, барлық нөмірлер мен чаттар Accel интерфейсінде бір терезеде.'],
                ['question' => 'Деректер қайда сақталады?', 'answer' => 'Стандарт тарифте деректер Қазақстан серверлерінде сақталады. Сондай-ақ қораптық орнату бар — платформа сіздің инфрақұрылымыңызда.'],
            ],
            'ru' => [
                ['question' => 'Что такое Accel?', 'answer' => 'Accel — платформа для командной работы в WhatsApp: чаты, AI-автопилот, воронки и задачи в одном окне.'],
                ['question' => 'Сколько стоит AI?', 'answer' => 'AI-токены оплачиваются отдельно по факту использования. Месячный расход можно оценить в AI-калькуляторе на сайте.'],
                ['question' => 'Что такое коробочная установка?', 'answer' => 'Разовая установка платформы за 1 000 000 ₸ без ежемесячной абонплаты. AI-токены оплачиваются отдельно.'],
                ['question' => 'Есть ли пробный период?', 'answer' => 'На тарифе Стандарт — 14 дней бесплатно после подключения. Коробочная установка без триала.'],
                ['question' => 'Можно подключить несколько WhatsApp-номеров?', 'answer' => 'Да, все номера и чаты команды работают в одном интерфейсе Accel.'],
                ['question' => 'Где хранятся данные?', 'answer' => 'На тарифе Стандарт данные хранятся на серверах в Казахстане. Также доступна коробочная установка — платформа на инфраструктуре вашей компании.'],
            ],
            'en' => [
                ['question' => 'What is Accel?', 'answer' => 'Accel is a platform for team WhatsApp: chats, AI autopilot, funnels and tasks in one workspace.'],
                ['question' => 'How much does AI cost?', 'answer' => 'AI tokens are billed separately by usage. Estimate monthly spend with the AI calculator on this site.'],
                ['question' => 'What is boxed installation?', 'answer' => 'A one-time 1,000,000 ₸ platform setup with no monthly subscription fee. AI tokens are billed separately.'],
                ['question' => 'Is there a free trial?', 'answer' => 'The Standard plan includes a 14-day trial after activation. Boxed installation has no trial.'],
                ['question' => 'Can we connect multiple WhatsApp numbers?', 'answer' => 'Yes, all numbers and team chats work in a single Accel interface.'],
                ['question' => 'Where is data stored?', 'answer' => 'On the Standard plan, data is stored on servers in Kazakhstan. Boxed installation is also available — the platform runs on your company infrastructure.'],
            ],
        ],
    ],

];
