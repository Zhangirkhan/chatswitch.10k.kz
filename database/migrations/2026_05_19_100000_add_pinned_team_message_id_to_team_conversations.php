<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_conversations', function (Blueprint $table): void {
            $table->foreignId('pinned_team_message_id')
                ->nullable()
                ->after('last_message_preview')
                ->constrained('team_messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('team_conversations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pinned_team_message_id');
        });
    }
};
