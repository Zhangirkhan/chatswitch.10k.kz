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
        // 1. Add 'system' value to direction enum on messages (MySQL/MariaDB only; SQLite uses varchar from schema builder)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE messages MODIFY direction ENUM('inbound','outbound','system') NOT NULL");
        }

        // 2. Change chats.whatsapp_session_id FK to SET NULL on delete
        Schema::table('chats', function (Blueprint $table): void {
            $table->dropForeign(['whatsapp_session_id']);
            $table->unsignedBigInteger('whatsapp_session_id')->nullable()->change();
            $table->foreign('whatsapp_session_id')
                ->references('id')->on('whatsapp_sessions')
                ->nullOnDelete();
        });

        // 3. Change messages.whatsapp_session_id FK to SET NULL on delete
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropForeign(['whatsapp_session_id']);
            $table->unsignedBigInteger('whatsapp_session_id')->nullable()->change();
            $table->foreign('whatsapp_session_id')
                ->references('id')->on('whatsapp_sessions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE messages MODIFY direction ENUM('inbound','outbound') NOT NULL");
        }

        Schema::table('chats', function (Blueprint $table): void {
            $table->dropForeign(['whatsapp_session_id']);
            $table->unsignedBigInteger('whatsapp_session_id')->nullable(false)->change();
            $table->foreign('whatsapp_session_id')
                ->references('id')->on('whatsapp_sessions')
                ->cascadeOnDelete();
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropForeign(['whatsapp_session_id']);
            $table->unsignedBigInteger('whatsapp_session_id')->nullable(false)->change();
            $table->foreign('whatsapp_session_id')
                ->references('id')->on('whatsapp_sessions')
                ->cascadeOnDelete();
        });
    }
};
