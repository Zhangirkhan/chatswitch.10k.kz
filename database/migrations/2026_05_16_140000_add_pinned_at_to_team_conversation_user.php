<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_conversation_user', function (Blueprint $table): void {
            $table->timestamp('pinned_at')->nullable()->after('last_read_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('team_conversation_user', function (Blueprint $table): void {
            $table->dropColumn('pinned_at');
        });
    }
};
