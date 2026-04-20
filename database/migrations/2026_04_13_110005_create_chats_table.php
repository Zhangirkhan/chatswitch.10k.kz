<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->string('whatsapp_chat_id')->index();
            $table->foreignId('whatsapp_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('chat_name')->nullable();
            $table->boolean('is_group')->default(false);
            $table->text('last_message_text')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->boolean('is_archived')->default(false);
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();

            $table->unique(['whatsapp_chat_id', 'whatsapp_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chats');
    }
};
