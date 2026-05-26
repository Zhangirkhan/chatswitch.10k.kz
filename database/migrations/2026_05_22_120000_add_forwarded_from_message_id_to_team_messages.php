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
            $table->unsignedBigInteger('forwarded_from_message_id')->nullable()->after('forwarded_from_team_message_id');

            $table->foreign('forwarded_from_message_id')
                ->references('id')
                ->on('messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('team_messages', function (Blueprint $table): void {
            $table->dropForeign(['forwarded_from_message_id']);
            $table->dropColumn('forwarded_from_message_id');
        });
    }
};
