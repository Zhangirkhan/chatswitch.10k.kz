<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $priceCents = (int) config('billing.boxed_price_cents', 100_000_000);
        $currency = (string) config('billing.currency', 'KZT');
        $now = now();

        $existing = DB::table('plans')->where('code', 'boxed')->first();

        if ($existing === null) {
            DB::table('plans')->insert([
                'code' => 'boxed',
                'name' => 'Коробочная установка',
                'price_cents' => $priceCents,
                'currency' => $currency,
                'interval' => 'once',
                'trial_days' => 0,
                'features' => json_encode([
                    'platform' => 'unlimited',
                    'whatsapp' => 'unlimited',
                    'users' => 'unlimited',
                    'modules' => 'all',
                    'ai' => 'usage_billed',
                    'description' => '1 000 000 ₸ разово за установку платформы; AI-токены отдельно',
                ]),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('plans')->where('code', 'boxed')->update([
                'name' => 'Коробочная установка',
                'price_cents' => $priceCents,
                'currency' => $currency,
                'interval' => 'once',
                'trial_days' => 0,
                'features' => json_encode([
                    'platform' => 'unlimited',
                    'whatsapp' => 'unlimited',
                    'users' => 'unlimited',
                    'modules' => 'all',
                    'ai' => 'usage_billed',
                    'description' => '1 000 000 ₸ разово за установку платформы; AI-токены отдельно',
                ]),
                'is_active' => true,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('plans')->where('code', 'boxed')->delete();
    }
};
