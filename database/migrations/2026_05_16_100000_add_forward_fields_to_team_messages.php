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
            $table->unsignedBigInteger('forwarded_from_team_message_id')->nullable()->after('mentioned_user_ids');
            $table->string('forward_source_title', 255)->nullable()->after('forwarded_from_team_message_id');
            $table->string('forward_quote_sender_name', 255)->nullable()->after('forward_source_title');
            $table->string('forward_quote_body', 512)->nullable()->after('forward_quote_sender_name');

            $table->foreign('forwarded_from_team_message_id')
                ->references('id')
                ->on('team_messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('team_messages', function (Blueprint $table): void {
            $table->dropForeign(['forwarded_from_team_message_id']);
            $table->dropColumn([
                'forwarded_from_team_message_id',
                'forward_source_title',
                'forward_quote_sender_name',
                'forward_quote_body',
            ]);
        });
    }
};
