<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->text('display_body')->nullable();
            $table->timestamp('scheduled_at')->index();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('sent_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['chat_id', 'status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_messages');
    }
};

