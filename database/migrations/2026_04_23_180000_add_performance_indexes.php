<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Сайдбар чатов: закреплённые сверху + сортировка по last_message_at,
        // с фильтром по is_archived. Тройной индекс покрывает ORDER + WHERE.
        Schema::table('chats', function (Blueprint $table): void {
            $table->index(['is_archived', 'is_pinned', 'last_message_at'], 'chats_sidebar_idx');
        });

        // Таймлайн чата: (chat_id, message_timestamp DESC).
        // В основной миграции есть только (chat_id, created_at).
        Schema::table('messages', function (Blueprint $table): void {
            $table->index(['chat_id', 'message_timestamp'], 'messages_chat_ts_idx');
        });

        // chat_assignments уже имеет unique(chat_id, user_id), добавляем обратный порядок
        // для whereHas('assignments')->where('user_id', ?).
        Schema::table('chat_assignments', function (Blueprint $table): void {
            $table->index(['user_id', 'chat_id'], 'chat_assignments_user_chat_idx');
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table): void {
            $table->dropIndex('chats_sidebar_idx');
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('messages_chat_ts_idx');
        });

        Schema::table('chat_assignments', function (Blueprint $table): void {
            $table->dropIndex('chat_assignments_user_chat_idx');
        });
    }
};
