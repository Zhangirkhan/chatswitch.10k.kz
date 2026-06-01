<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('messages', function (Blueprint $table): void {
            $table->unique(
                ['whatsapp_session_id', 'whatsapp_message_id'],
                'messages_session_whatsapp_message_unique',
            );
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropUnique('messages_session_whatsapp_message_unique');
        });
    }
};
