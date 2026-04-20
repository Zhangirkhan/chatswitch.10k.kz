<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_session_id')->constrained()->cascadeOnDelete();
            $table->string('whatsapp_message_id')->nullable()->index();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->string('type')->default('chat');
            $table->text('body')->nullable();
            $table->string('sender_phone')->nullable();
            $table->string('sender_name')->nullable();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_forwarded')->default(false);
            $table->string('quoted_message_id')->nullable();
            $table->enum('ack', ['pending', 'sent', 'delivered', 'read'])->default('pending');
            $table->timestamp('message_timestamp')->nullable();
            $table->timestamps();

            $table->index(['chat_id', 'created_at']);
            $table->fullText('body');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
