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
            $table->string('client_message_id', 64)->nullable()->after('body');
            $table->unique(['team_conversation_id', 'client_message_id'], 'team_messages_conv_client_unique');
        });
    }

    public function down(): void
    {
        Schema::table('team_messages', function (Blueprint $table): void {
            $table->dropUnique('team_messages_conv_client_unique');
            $table->dropColumn('client_message_id');
        });
    }
};
