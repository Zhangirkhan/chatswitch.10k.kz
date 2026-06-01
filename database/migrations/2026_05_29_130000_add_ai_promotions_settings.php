<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            if (! Schema::hasColumn('companies', 'ai_promotions_enabled')) {
                $table->boolean('ai_promotions_enabled')->default(true)->after('is_active');
            }
        });

        Schema::table('funnel_stage_ai_rules', function (Blueprint $table): void {
            if (! Schema::hasColumn('funnel_stage_ai_rules', 'follow_up_use_promotions')) {
                $table->boolean('follow_up_use_promotions')->default(true)->after('follow_up_promotion_ids');
            }
        });
    }

    public function down(): void
    {
        Schema::table('funnel_stage_ai_rules', function (Blueprint $table): void {
            if (Schema::hasColumn('funnel_stage_ai_rules', 'follow_up_use_promotions')) {
                $table->dropColumn('follow_up_use_promotions');
            }
        });

        Schema::table('companies', function (Blueprint $table): void {
            if (Schema::hasColumn('companies', 'ai_promotions_enabled')) {
                $table->dropColumn('ai_promotions_enabled');
            }
        });
    }
};
