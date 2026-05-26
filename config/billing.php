<?php

declare(strict_types=1);

return [

    /** Код тарифа по умолчанию при создании компании. */
    'default_plan_code' => env('BILLING_DEFAULT_PLAN_CODE', 'standard'),

    /** Дней триала для новых компаний. */
    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),

    /**
     * Цена месячного тарифа в тиынах (1 ₸ = 100 тиын).
     * 40 000 ₸ = 4 000 000 тиын.
     */
    'standard_price_cents' => (int) env('BILLING_STANDARD_PRICE_CENTS', 4_000_000),

    'currency' => env('BILLING_CURRENCY', 'KZT'),

    /** Реквизиты для счетов и писем. */
    'seller' => [
        'name' => env('BILLING_SELLER_NAME', 'ТОО Accel'),
        'bin' => env('BILLING_SELLER_BIN', ''),
        'bank' => env('BILLING_SELLER_BANK', ''),
        'iban' => env('BILLING_SELLER_IBAN', ''),
        'email' => env('BILLING_SELLER_EMAIL', 'billing@accel.kz'),
    ],

    /** Лимит WhatsApp-номеров по умолчанию (если в настройках тенанта не задан). */
    'invoice_overdue_days' => (int) env('BILLING_INVOICE_OVERDUE_DAYS', 7),

    /**
     * Email-напоминания клиентам об оплате до окончания триала / периода подписки.
     */
    'payment_reminders' => [
        'enabled' => (bool) env('BILLING_PAYMENT_REMINDERS_ENABLED', true),
        /** За сколько дней до даты платежа отправлять письма (можно несколько). */
        'days_before' => array_values(array_filter(array_map(
            static fn (string $part): int => max(1, (int) trim($part)),
            explode(',', (string) env('BILLING_PAYMENT_REMINDER_DAYS', '7,3,1')),
        ))),
        'schedule_at' => env('BILLING_PAYMENT_REMINDER_TIME', '09:00'),
    ],

];
