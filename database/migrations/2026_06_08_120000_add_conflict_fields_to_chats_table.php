<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table): void {
            $table->string('conflict_state', 32)->default('none')->after('ai_orchestrator_last_summary');
            $table->string('conflict_situation', 64)->nullable()->after('conflict_state');
            $table->unsignedTinyInteger('conflict_deescalation_count')->default(0)->after('conflict_situation');
            $table->timestamp('ai_paused_at')->nullable()->after('conflict_deescalation_count');
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table): void {
            $table->dropColumn([
                'conflict_state',
                'conflict_situation',
                'conflict_deescalation_count',
                'ai_paused_at',
            ]);
        });
    }
};
