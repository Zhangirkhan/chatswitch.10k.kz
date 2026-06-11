<?php

declare(strict_types=1);

return [

    'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),

    'pricing' => [
        'gpt-4o-mini' => [
            'input_per_1m' => (float) env('AI_CALC_GPT_INPUT_PER_1M', 0.15),
            'output_per_1m' => (float) env('AI_CALC_GPT_OUTPUT_PER_1M', 0.60),
        ],
        'text-embedding-3-small' => [
            'per_1m' => (float) env('AI_CALC_EMBED_PER_1M', 0.02),
        ],
        'whisper' => [
            'per_minute' => (float) env('AI_CALC_WHISPER_PER_MIN', 0.006),
        ],
        'usd_to_kzt' => (float) env('AI_CALC_USD_KZT', 510),
    ],

    'benchmark' => [
        'days' => (int) env('AI_CALC_BENCHMARK_DAYS', 30),
        'min_samples' => (int) env('AI_CALC_BENCHMARK_MIN_SAMPLES', 30),
    ],

    /**
     * Доли срабатывания сценариев: numerator/denominator из замеров ai_usage_events.
     * fallback — если замеров ещё мало.
     */
    'volume_triggers' => [
        'dept_routing' => ['numerator' => 'dept_routing', 'denominator' => 'inbound', 'fallback' => 0.30],
        'appointment_intent' => ['numerator' => 'appointment_intent', 'denominator' => 'ai_reply', 'fallback' => 0.15],
        'history_compress' => ['numerator' => 'history_compress', 'denominator' => 'ai_reply', 'fallback' => 0.10],
        'rag_embed' => ['numerator' => 'rag_embed', 'denominator' => 'ai_reply', 'fallback' => 2.0],
        'funnel_classify' => ['numerator' => 'funnel_classify', 'denominator' => 'inbound', 'fallback' => 1.0],
        'auto_follow_up' => ['numerator' => 'auto_follow_up', 'denominator' => 'follow_up_proposal', 'fallback' => 0.5],
    ],

    'subscription_kzt' => (int) (config('billing.standard_price_cents', 4_000_000) / 100),

    'background_monthly_usd' => (float) env('AI_CALC_BACKGROUND_USD', 3.0),

    'defaults' => [
        'leads_per_day' => 30,
        'inbound_msgs_per_lead' => 8,
        'ai_reply_rate' => 70,
        'funnel_enabled' => true,
        'orchestrator_rate' => 20,
        'voice_msg_rate' => 10,
        'avg_voice_duration_sec' => 25,
        'silent_leads_per_day' => 5,
        'operators' => 3,
        'operator_ai_uses_per_day' => 5,
        'translations_per_day' => 2,
        'workspace_queries_per_day' => 1,
        'work_days_per_month' => 22,
    ],

    'presets' => [
        'start' => [
            'label' => 'Небольшой бизнес',
            'hint' => '~10 обращений в день, AI помогает частично',
            'leads_per_day' => 10,
            'inbound_msgs_per_lead' => 6,
            'ai_reply_rate' => 50,
            'funnel_enabled' => false,
            'orchestrator_rate' => 0,
            'voice_msg_rate' => 5,
            'silent_leads_per_day' => 2,
            'operators' => 2,
            'operator_ai_uses_per_day' => 3,
        ],
        'growth' => [
            'label' => 'Активные продажи',
            'hint' => '~30 обращений, AI отвечает в большинстве чатов',
            'leads_per_day' => 30,
            'inbound_msgs_per_lead' => 8,
            'ai_reply_rate' => 70,
            'funnel_enabled' => true,
            'orchestrator_rate' => 15,
            'voice_msg_rate' => 10,
            'silent_leads_per_day' => 5,
            'operators' => 3,
            'operator_ai_uses_per_day' => 5,
        ],
        'active' => [
            'label' => 'Много клиентов',
            'hint' => '~80 обращений, воронка и дожим включены',
            'leads_per_day' => 80,
            'inbound_msgs_per_lead' => 10,
            'ai_reply_rate' => 80,
            'funnel_enabled' => true,
            'orchestrator_rate' => 25,
            'voice_msg_rate' => 15,
            'silent_leads_per_day' => 12,
            'operators' => 6,
            'operator_ai_uses_per_day' => 8,
        ],
        'callcenter' => [
            'label' => 'Контакт-центр',
            'hint' => '200+ обращений, большая команда',
            'leads_per_day' => 200,
            'inbound_msgs_per_lead' => 12,
            'ai_reply_rate' => 85,
            'funnel_enabled' => true,
            'orchestrator_rate' => 30,
            'voice_msg_rate' => 20,
            'silent_leads_per_day' => 30,
            'operators' => 15,
            'operator_ai_uses_per_day' => 12,
            'translations_per_day' => 10,
            'workspace_queries_per_day' => 3,
        ],
    ],

    /**
     * Сценарии OpenAI: оценки токенов по средним промптам в коде.
     * volume_key — ключ объёма вызовов в месяц из AiTokenCalculatorService::volumes().
     */
    'scenarios' => [
        [
            'id' => 'ai_reply',
            'label' => 'Ответы клиентам',
            'description' => 'AI сам пишет ответ в WhatsApp',
            'volume_key' => 'ai_reply',
            'input_tokens' => 5500,
            'output_tokens' => 280,
            'type' => 'chat',
        ],
        [
            'id' => 'dept_routing',
            'label' => 'Направление в отдел',
            'description' => 'AI определяет, кому передать чат',
            'volume_key' => 'dept_routing',
            'input_tokens' => 900,
            'output_tokens' => 80,
            'type' => 'chat',
        ],
        [
            'id' => 'appointment_intent',
            'label' => 'Запись на услугу',
            'description' => 'AI понимает, что клиент хочет записаться',
            'volume_key' => 'appointment_intent',
            'input_tokens' => 2200,
            'output_tokens' => 150,
            'type' => 'chat',
        ],
        [
            'id' => 'history_compress',
            'label' => 'Длинная переписка',
            'description' => 'Сжатие истории, чтобы не терять контекст',
            'volume_key' => 'history_compress',
            'input_tokens' => 3500,
            'output_tokens' => 450,
            'type' => 'chat',
        ],
        [
            'id' => 'rag_embed',
            'label' => 'Поиск в базе знаний',
            'description' => 'AI находит цены и условия в ваших материалах',
            'volume_key' => 'rag_embed',
            'input_tokens' => 400,
            'output_tokens' => 0,
            'type' => 'embedding',
        ],
        [
            'id' => 'funnel_classify',
            'label' => 'Этап сделки',
            'description' => 'AI понимает, на каком шаге клиент в воронке',
            'volume_key' => 'funnel_classify',
            'input_tokens' => 1800,
            'output_tokens' => 90,
            'type' => 'chat',
        ],
        [
            'id' => 'funnel_orchestrator',
            'label' => 'Умный сценарий продаж',
            'description' => 'Сложные ответы: запись, этапы, предложения',
            'volume_key' => 'funnel_orchestrator',
            'input_tokens' => 7500,
            'output_tokens' => 380,
            'type' => 'chat',
        ],
        [
            'id' => 'follow_up_proposal',
            'label' => 'Напоминание «замолчавшим»',
            'description' => 'AI готовит варианты сообщения для менеджера',
            'volume_key' => 'follow_up_proposal',
            'input_tokens' => 3200,
            'output_tokens' => 750,
            'type' => 'chat',
        ],
        [
            'id' => 'auto_follow_up',
            'label' => 'Автонапоминание',
            'description' => 'Система сама отправляет мягкое напоминание',
            'volume_key' => 'auto_follow_up',
            'input_tokens' => 1200,
            'output_tokens' => 80,
            'type' => 'chat',
        ],
        [
            'id' => 'operator_assistant',
            'label' => 'Помощь менеджеру',
            'description' => 'Менеджер просит AI подсказать или написать ответ',
            'volume_key' => 'operator_assistant',
            'input_tokens' => 6500,
            'output_tokens' => 350,
            'type' => 'chat',
        ],
        [
            'id' => 'translation',
            'label' => 'Перевод сообщения',
            'description' => 'Перевод текста для клиента на другой язык',
            'volume_key' => 'translation',
            'input_tokens' => 600,
            'output_tokens' => 250,
            'type' => 'chat',
        ],
        [
            'id' => 'workspace_query',
            'label' => 'Поиск по клиентам',
            'description' => 'Вопросы к базе: «кто не ответил», «сколько сделок»',
            'volume_key' => 'workspace_query',
            'input_tokens' => 4000,
            'output_tokens' => 600,
            'type' => 'chat',
        ],
        [
            'id' => 'whisper',
            'label' => 'Голосовые в текст',
            'description' => 'Расшифровка голосовых сообщений от клиентов',
            'volume_key' => 'whisper_minutes',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'type' => 'whisper',
        ],
        [
            'id' => 'operator_dictation',
            'label' => 'Диктовка оператора',
            'description' => 'Распознавание речи в AI-ассистенте и полях ввода',
            'volume_key' => 'operator_dictation_minutes',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'type' => 'whisper',
        ],
        [
            'id' => 'background',
            'label' => 'Фоновая настройка',
            'description' => 'Обучение стилю общения и обновление базы знаний',
            'volume_key' => 'background',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'type' => 'fixed_usd',
        ],
    ],

];
