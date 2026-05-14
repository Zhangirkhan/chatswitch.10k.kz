<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->foreignId('chat_id')
                ->nullable()
                ->after('assignee_user_id')
                ->constrained('chats')
                ->nullOnDelete();
            $table->foreignId('contact_id')
                ->nullable()
                ->after('chat_id')
                ->constrained('contacts')
                ->nullOnDelete();
            $table->foreignId('trigger_message_id')
                ->nullable()
                ->after('contact_id')
                ->constrained('messages')
                ->nullOnDelete();
            $table->string('source', 40)->nullable()->after('trigger_message_id')->index();
            $table->json('metadata')->nullable()->after('source');

            $table->index(['chat_id', 'starts_at']);
            $table->index(['contact_id', 'starts_at']);
        });

        Schema::table('scheduled_messages', function (Blueprint $table): void {
            $table->foreignId('calendar_event_id')
                ->nullable()
                ->after('user_id')
                ->constrained('calendar_events')
                ->nullOnDelete();
            $table->string('purpose', 40)->nullable()->after('calendar_event_id')->index();

            $table->index(['calendar_event_id', 'status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_messages', function (Blueprint $table): void {
            $table->dropIndex(['calendar_event_id', 'status', 'scheduled_at']);
            $table->dropIndex(['purpose']);
            $table->dropForeign(['calendar_event_id']);
            $table->dropColumn(['calendar_event_id', 'purpose']);
        });

        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->dropIndex(['chat_id', 'starts_at']);
            $table->dropIndex(['contact_id', 'starts_at']);
            $table->dropIndex(['source']);
            $table->dropForeign(['chat_id']);
            $table->dropForeign(['contact_id']);
            $table->dropForeign(['trigger_message_id']);
            $table->dropColumn(['chat_id', 'contact_id', 'trigger_message_id', 'source', 'metadata']);
        });
    }
};
