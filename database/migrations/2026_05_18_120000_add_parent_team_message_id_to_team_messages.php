<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_messages', function (Blueprint $table): void {
            $table->unsignedBigInteger('parent_team_message_id')->nullable()->after('team_conversation_id');
        });

        Schema::table('team_messages', function (Blueprint $table): void {
            $table->foreign('parent_team_message_id')->references('id')->on('team_messages')->nullOnDelete();
        });

        Schema::table('team_messages', function (Blueprint $table): void {
            $table->index(['team_conversation_id', 'parent_team_message_id']);
        });
    }

    public function down(): void
    {
        Schema::table('team_messages', function (Blueprint $table): void {
            $table->dropForeign(['parent_team_message_id']);
        });

        Schema::table('team_messages', function (Blueprint $table): void {
            $table->dropIndex(['team_conversation_id', 'parent_team_message_id']);
        });

        Schema::table('team_messages', function (Blueprint $table): void {
            $table->dropColumn('parent_team_message_id');
        });
    }
};
