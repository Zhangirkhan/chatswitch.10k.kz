<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcast_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('whatsapp_session_id')->constrained('whatsapp_sessions')->cascadeOnDelete();
            $table->string('source', 32);
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('delay_seconds')->default(4);
            $table->string('filter_message', 2000)->nullable();
            $table->json('filters')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('ready_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('broadcast_campaign_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('broadcast_campaign_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number')->default(0);
            $table->string('phone_raw', 64);
            $table->string('phone_digits', 32)->nullable();
            $table->text('message_text');
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('chat_id')->nullable()->constrained('chats')->nullOnDelete();
            $table->string('status', 32)->default('pending');
            $table->string('skip_reason', 500)->nullable();
            $table->string('error', 500)->nullable();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['broadcast_campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_campaign_items');
        Schema::dropIfExists('broadcast_campaigns');
    }
};
