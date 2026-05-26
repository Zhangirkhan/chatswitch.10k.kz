<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedSmallInteger('trial_days')->default(14)->after('interval');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('ended_at')->nullable()->after('trial_ends_at');
            $table->timestamp('canceled_at')->nullable()->after('ended_at');
            $table->string('event', 32)->nullable()->after('canceled_at');
        });

        $priceCents = (int) env('BILLING_STANDARD_PRICE_CENTS', 4_000_000);
        $trialDays = (int) env('BILLING_TRIAL_DAYS', 14);
        $currency = (string) env('BILLING_CURRENCY', 'KZT');
        $now = now();

        $existing = DB::table('plans')->where('code', 'standard')->first();

        if ($existing === null) {
            DB::table('plans')->insert([
                'code' => 'standard',
                'name' => 'Стандарт',
                'price_cents' => $priceCents,
                'currency' => $currency,
                'interval' => 'month',
                'trial_days' => $trialDays,
                'features' => json_encode([
                    'whatsapp' => true,
                    'users' => 'unlimited',
                    'description' => '40 000 ₸ в месяц, триал 14 дней',
                ]),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('plans')->where('code', 'standard')->update([
                'name' => 'Стандарт',
                'price_cents' => $priceCents,
                'trial_days' => $trialDays,
                'updated_at' => $now,
            ]);
        }

        DB::table('plans')->where('code', 'starter')->update([
            'price_cents' => $priceCents,
            'trial_days' => $trialDays,
            'name' => 'Стандарт (legacy starter)',
            'updated_at' => $now,
        ]);

        $standardId = DB::table('plans')->where('code', 'standard')->value('id')
            ?? DB::table('plans')->where('code', 'starter')->value('id');

        if ($standardId !== null) {
            DB::table('companies')
                ->whereNull('plan_id')
                ->update(['plan_id' => $standardId]);

            DB::table('companies')
                ->where('subscription_status', 'trial')
                ->whereNull('trial_ends_at')
                ->update([
                    'trial_ends_at' => $now->copy()->addDays($trialDays),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['ended_at', 'canceled_at', 'event']);
        });

        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('trial_days');
        });
    }
};
