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
            $table->string('active_topic', 500)->nullable()->after('ai_orchestrator_last_summary');
            $table->timestamp('active_topic_updated_at')->nullable()->after('active_topic');
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table): void {
            $table->dropColumn(['active_topic', 'active_topic_updated_at']);
        });
    }
};
