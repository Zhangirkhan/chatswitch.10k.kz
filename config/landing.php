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

        'faq' => [
            'path' => '/faq',
            'kk' => [
                'title' => 'Жиі қойылатын сұрақтар — Accel',
                'description' => 'Accel платформасы туралы жауаптар: тарифтер, AI, деректерді Қазақстанда сақтау, қосылу және қолдау.',
                'og_image' => '/og/accel-landing-kk.svg',
            ],
            'ru' => [
                'title' => 'Частые вопросы — Accel',
                'description' => 'Ответы о платформе Accel: тарифы, AI, хранение данных в Казахстане, подключение и поддержка команды.',
                'og_image' => '/og/accel-landing-ru.svg',
            ],
            'en' => [
                'title' => 'FAQ — Accel',
                'description' => 'Answers about Accel: pricing, AI, data storage in Kazakhstan, onboarding and team support.',
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
                ['question' => 'Бірнеше WhatsApp нөмірін қосуға бола ма?', 'answer' => 'Иә, барлық нөмірлер мен чаттар Accel интерфейсінде бір терезеде.'],
                ['question' => 'Accel жеке WhatsApp-тан несімен ерекшеленеді?', 'answer' => 'Переписка, тарих пен тіркемелер компания жүйесінде сақталады, менеджер телефонында емес. Басшы кезек, SLA және жүктемені көреді, команда рөлдер бойынша жұмыс істейді.'],
                ['question' => 'Мобильді қосымша бар ма?', 'answer' => 'Иә, Android үшін Accel қосымшасы бар. Жүктеу сілтемесі — сайт тақтасында.'],
                ['question' => 'Ішкі чат пен тапсырмалар бар ма?', 'answer' => 'Иә, Accel-де команда ішкі чаты, клиент тапсырмалары және аналитика бар — сервистер арасында ауыспай.'],
                ['question' => 'Стандарт тарифі қанша тұрады?', 'answer' => 'Стандарт тарифінің ағымдағы бағасы басты беттегі «Тарифтер» блокында. AI токендері пайдалану бойынша бөлек төленеді.'],
                ['question' => 'Қораптык орнату деген не?', 'answer' => 'Бұл платформаны 1 000 000 ₸ бір рет төлеп орнату. Ай сайынғы абоненттік төлем жоқ, бірақ AI токендері бөлек.'],
                ['question' => 'Триал бар ма?', 'answer' => 'Стандарт тарифте қосылғаннан кейін 14 күн тегін. Қораптык орнату триалсыз.'],
                ['question' => 'Жазылымға не кіреді?', 'answer' => 'Командалық WhatsApp, AI құралдары, воронкалар, тапсырмалар, аналитика және платформа қолдауы. AI токендерінің шығыны — бөлек.'],
                ['question' => 'AI қанша тұрады?', 'answer' => 'AI токендері пайдалану бойынша бөлек төленеді. Айлық шығынды AI калькуляторы арқылы бағалауға болады.'],
                ['question' => 'AI автопилот қалай жұмыс істейді?', 'answer' => 'Автопилот сценарийлер мен білім базасы бойынша жауап береді, жауапты тағайындайды және менеджерсіз диалогты жүргізе алады.'],
                ['question' => 'AI-ды өшіруге бола ма?', 'answer' => 'Иә, AI функциялары рөлдер мен сценарийлер бойынша бапталады — тек кеңестер немесе автопилотты толығымен өшіру мүмкін.'],
                ['question' => 'AI шығынын қалай бағалауға болады?', 'answer' => 'Сайттағы AI калькуляторын ашыңыз, пресет таңдаңыз немесе диалог көлемін енгізіңіз — шамамен сома теңге-де көрсетіледі.'],
                ['question' => 'Деректер қайда сақталады?', 'answer' => 'Стандарт тарифте деректер Қазақстан серверлерінде сақталады. Сондай-ақ қoraптық орнату бар — платформа сіздің инфрақұрылымыңызда.'],
                ['question' => 'Accel шетел сервистерінен несімен ерекшеленеді?', 'answer' => 'Wazzup, wahelp, i2crm, umnico және ұқсас сервистер деректерді шетелде сақтайды. Accel — деректері ҚР дата-центрлерлерінде сақталатын қазақстандық продукт.'],
                ['question' => 'Accel ҚР персоналдық деректер заңына сәйкес келе ме?', 'answer' => 'Стандарт тарифте деректер локализация талаптарына сәйкес Қазақстанда сақталады.'],
                ['question' => 'Клиент перепискасын кім көре алады?', 'answer' => 'Қолжетімділік рөлдер бойынша: қызметкерлер өз диалогтарын, басшылар — команда мен аналитиканы көреді. Әрекеттер тарихы жүйеде сақталады.'],
                ['question' => 'Accel-ді қалай пайдалана бастауға болады?', 'answer' => 'Сайтта өтінім қалдырыңыз — біз хабарласамыз, субдоменді келісеміз және WhatsApp пен командаңызды қосуға көмектесеміз.'],
                ['question' => 'Қосылу қанша уақыт алады?', 'answer' => 'Негізгі қосылу әдетте бірнеше жұмыс күнін алады — нөмірлер мен баптауларгаға байланысты.'],
                ['question' => 'Өз IT командасы керек пе?', 'answer' => 'Стандарт үшін WhatsApp қосып, қызметкерлерді шақыру жеткілікті. Қораптык орнату сіздің әкімшілеріңізді қажет етеді.'],
                ['question' => 'Қолдау қандай тілдерде бар?', 'answer' => 'Қазақ және орыс тілдерінде қолдау.'],
            ],
            'ru' => [
                ['question' => 'Что такое Accel?', 'answer' => 'Accel — платформа для командной работы в WhatsApp: чаты, AI-автопилот, воронки и задачи в одном окне.'],
                ['question' => 'Можно подключить несколько WhatsApp-номеров?', 'answer' => 'Да, все номера и чаты команды работают в одном интерфейсе Accel.'],
                ['question' => 'Чем Accel отличается от личного WhatsApp?', 'answer' => 'Переписка, история и вложения хранятся в системе компании, а не на телефоне менеджера. Руководитель видит очередь, SLA и нагрузку, а команда работает по ролям.'],
                ['question' => 'Есть ли мобильное приложение?', 'answer' => 'Да, для Android доступно приложение Accel. Ссылка на скачивание — в шапке сайта.'],
                ['question' => 'Есть ли внутренний чат и задачи?', 'answer' => 'Да, в Accel есть внутренний чат команды, задачи по клиентам и аналитика — без переключения между сервисами.'],
                ['question' => 'Сколько стоит тариф Стандарт?', 'answer' => 'Актуальная цена тарифа Стандарт указана на главной странице в блоке «Тарифы». AI-токены оплачиваются отдельно по факту использования.'],
                ['question' => 'Что такое коробочная установка?', 'answer' => 'Разовая установка платформы за 1 000 000 ₸ без ежемесячной абонплаты. AI-токены оплачиваются отдельно.'],
                ['question' => 'Есть ли пробный период?', 'answer' => 'На тарифе Стандарт — 14 дней бесплатно после подключения. Коробочная установка без триала.'],
                ['question' => 'Что входит в подписку?', 'answer' => 'Командный WhatsApp, AI-инструменты, воронки, задачи, аналитика и поддержка платформы. Расход на AI-токены — отдельно.'],
                ['question' => 'Сколько стоит AI?', 'answer' => 'AI-токены оплачиваются отдельно по факту использования. Месячный расход можно оценить в AI-калькуляторе на сайте.'],
                ['question' => 'Как работает AI-автопилот?', 'answer' => 'Автопилот отвечает клиентам по сценариям и базе знаний, назначает ответственного и может вести диалог без участия менеджера.'],
                ['question' => 'Можно ли отключить AI?', 'answer' => 'Да, AI-функции настраиваются по ролям и сценариям — можно использовать только подсказки или полностью отключить автопилот.'],
                ['question' => 'Как оценить расход на AI?', 'answer' => 'Откройте AI-калькулятор на сайте, выберите пресет или задайте объём диалогов — калькулятор покажет ориентировочную сумму в тенге.'],
                ['question' => 'Где хранятся данные?', 'answer' => 'На тарифе Стандарт данные хранятся на серверах в Казахстане. Также доступна коробочная установка — платформа на инфраструктуре вашей компании.'],
                ['question' => 'Чем Accel отличается от зарубежных сервисов?', 'answer' => 'Wazzup, wahelp, i2crm, umnico и аналоги хранят данные за рубежом. Accel — казахстанский продукт с хранением в дата-центрах РК.'],
                ['question' => 'Соответствует ли Accel закону о персональных данных РК?', 'answer' => 'На тарифе Стандарт данные хранятся в Казахстане в соответствии с требованиями локализации персональных данных.'],
                ['question' => 'Кто видит переписку клиентов?', 'answer' => 'Доступ разграничен по ролям: сотрудники видят свои диалоги, руководители — команду и аналитику. История действий сохраняется в системе.'],
                ['question' => 'Как начать пользоваться Accel?', 'answer' => 'Оставьте заявку на сайте — мы свяжемся, согласуем субдомен и поможем с подключением WhatsApp и командой.'],
                ['question' => 'Сколько времени занимает подключение?', 'answer' => 'Базовое подключение обычно занимает от одного до нескольких рабочих дней — в зависимости от числа номеров и настроек.'],
                ['question' => 'Нужна ли своя IT-команда?', 'answer' => 'Для тарифа Стандарт достаточно подключить WhatsApp и пригласить сотрудников. Коробочная установка требует участия ваших администраторов.'],
                ['question' => 'На каких языках доступна поддержка?', 'answer' => 'Поддержка на казахском и русском языках.'],
            ],
            'en' => [
                ['question' => 'What is Accel?', 'answer' => 'Accel is a platform for team WhatsApp: chats, AI autopilot, funnels and tasks in one workspace.'],
                ['question' => 'Can we connect multiple WhatsApp numbers?', 'answer' => 'Yes, all numbers and team chats work in a single Accel interface.'],
                ['question' => 'How is Accel different from personal WhatsApp?', 'answer' => 'Chats, history and attachments live in your company system, not on a manager\'s phone. Leaders see queue, SLA and workload; the team works with roles.'],
                ['question' => 'Is there a mobile app?', 'answer' => 'Yes, Accel is available for Android. Download link is in the site header.'],
                ['question' => 'Is there internal chat and tasks?', 'answer' => 'Yes — team chat, client tasks and analytics without switching between tools.'],
                ['question' => 'How much does the Standard plan cost?', 'answer' => 'The current Standard price is on the home page in the Pricing section. AI tokens are billed separately by usage.'],
                ['question' => 'What is boxed installation?', 'answer' => 'A one-time 1,000,000 ₸ platform setup with no monthly subscription fee. AI tokens are billed separately.'],
                ['question' => 'Is there a free trial?', 'answer' => 'The Standard plan includes a 14-day trial after activation. Boxed installation has no trial.'],
                ['question' => 'What is included in the subscription?', 'answer' => 'Team WhatsApp, AI tools, funnels, tasks, analytics and platform support. AI token spend is separate.'],
                ['question' => 'How much does AI cost?', 'answer' => 'AI tokens are billed separately by usage. Estimate monthly spend with the AI calculator on this site.'],
                ['question' => 'How does AI autopilot work?', 'answer' => 'Autopilot replies using scenarios and knowledge base, assigns owners and can handle chats without a manager.'],
                ['question' => 'Can AI be turned off?', 'answer' => 'Yes — AI features are configurable by role and scenario. Use suggestions only or disable autopilot entirely.'],
                ['question' => 'How do I estimate AI spend?', 'answer' => 'Open the AI calculator, pick a preset or set dialog volume — it shows an approximate amount in tenge.'],
                ['question' => 'Where is data stored?', 'answer' => 'On the Standard plan, data is stored on servers in Kazakhstan. Boxed installation is also available — the platform runs on your company infrastructure.'],
                ['question' => 'How is Accel different from foreign services?', 'answer' => 'Wazzup, wahelp, i2crm, umnico and similar tools store data abroad. Accel is a Kazakhstan product with data in KZ data centers.'],
                ['question' => 'Does Accel comply with Kazakhstan personal data law?', 'answer' => 'On the Standard plan, data is stored in Kazakhstan in line with localization requirements.'],
                ['question' => 'Who can see client chats?', 'answer' => 'Access is role-based: staff see their dialogs, managers see the team and analytics. Action history is kept in the system.'],
                ['question' => 'How do I start using Accel?', 'answer' => 'Submit a request on the site — we will contact you, agree on a subdomain and help connect WhatsApp and your team.'],
                ['question' => 'How long does onboarding take?', 'answer' => 'Basic setup usually takes one to several business days depending on numbers and configuration.'],
                ['question' => 'Do we need our own IT team?', 'answer' => 'For Standard, connect WhatsApp and invite staff. Boxed installation requires your administrators.'],
                ['question' => 'What languages is support available in?', 'answer' => 'Support in Kazakh and Russian.'],
            ],
        ],
    ],

];
