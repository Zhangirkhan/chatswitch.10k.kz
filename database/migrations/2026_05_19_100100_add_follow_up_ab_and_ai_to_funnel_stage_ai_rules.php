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
            $table->string('follow_up_mode', 16)->default('template')->after('follow_up_message');
            $table->text('follow_up_message_b')->nullable()->after('follow_up_mode');
            $table->unsignedTinyInteger('follow_up_ab_ratio')->default(50)->after('follow_up_message_b');
        });
    }

    public function down(): void
    {
        Schema::table('funnel_stage_ai_rules', function (Blueprint $table): void {
            $table->dropColumn(['follow_up_mode', 'follow_up_message_b', 'follow_up_ab_ratio']);
        });
    }
};
