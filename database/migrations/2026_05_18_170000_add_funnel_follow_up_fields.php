<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funnel_stage_ai_rules', function (Blueprint $table): void {
            if (! Schema::hasColumn('funnel_stage_ai_rules', 'follow_up_enabled')) {
                $table->boolean('follow_up_enabled')->default(false)->after('require_manager_confirmation');
                $table->unsignedSmallInteger('follow_up_delay_hours')->default(24)->after('follow_up_enabled');
                $table->text('follow_up_message')->nullable()->after('follow_up_delay_hours');
                $table->unsignedSmallInteger('follow_up_cooldown_hours')->default(72)->after('follow_up_message');
                $table->unsignedTinyInteger('follow_up_max_count')->default(2)->after('follow_up_cooldown_hours');
            }
        });

        Schema::table('scheduled_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('scheduled_messages', 'funnel_stage_id')) {
                $table->foreignId('funnel_stage_id')
                    ->nullable()
                    ->after('purpose')
                    ->constrained('funnel_stages')
                    ->nullOnDelete();

                $table->index(['chat_id', 'purpose', 'funnel_stage_id', 'status'], 'scheduled_msgs_chat_purpose_stage_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_messages', function (Blueprint $table): void {
            if (Schema::hasColumn('scheduled_messages', 'funnel_stage_id')) {
                $table->dropIndex('scheduled_msgs_chat_purpose_stage_status');
                $table->dropForeign(['funnel_stage_id']);
                $table->dropColumn('funnel_stage_id');
            }
        });

        Schema::table('funnel_stage_ai_rules', function (Blueprint $table): void {
            if (Schema::hasColumn('funnel_stage_ai_rules', 'follow_up_enabled')) {
                $table->dropColumn([
                    'follow_up_enabled',
                    'follow_up_delay_hours',
                    'follow_up_message',
                    'follow_up_cooldown_hours',
                    'follow_up_max_count',
                ]);
            }
        });
    }
};
