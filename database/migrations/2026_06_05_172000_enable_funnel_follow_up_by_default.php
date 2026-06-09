<?php

declare(strict_types=1);

use App\Models\FunnelStageAiRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('funnel_stage_ai_rules')
            ->where('follow_up_strategy', '!=', FunnelStageAiRule::FOLLOW_UP_STRATEGY_MANAGER_PROPOSALS)
            ->update([
                'follow_up_enabled' => true,
                'follow_up_strategy' => FunnelStageAiRule::FOLLOW_UP_STRATEGY_AUTO_CRON,
            ]);

        Schema::table('funnel_stage_ai_rules', function ($table): void {
            $table->string('follow_up_strategy', 32)
                ->default(FunnelStageAiRule::FOLLOW_UP_STRATEGY_AUTO_CRON)
                ->change();
        });
    }

    public function down(): void
    {
        // Не откатываем массовое включение дожима.
    }
};
