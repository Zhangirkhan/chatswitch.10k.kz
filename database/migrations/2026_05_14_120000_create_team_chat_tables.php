<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 32); // direct | department
            $table->foreignId('department_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_low_id')->nullable();
            $table->unsignedBigInteger('user_high_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 512)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'type']);
            $table->index(['user_low_id', 'user_high_id']);

            $table->foreign('user_low_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('user_high_id')->references('id')->on('users')->cascadeOnDelete();

            $table->unique(['company_id', 'user_low_id', 'user_high_id', 'type'], 'team_conv_direct_unique');
        });

        Schema::create('team_conversation_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_conversation_id')->constrained('team_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('can_leave')->default(true);
            $table->timestamp('last_read_at')->nullable();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamps();

            $table->unique(['team_conversation_id', 'user_id']);
        });

        Schema::create('team_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_conversation_id')->constrained('team_conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['team_conversation_id', 'id']);
        });

        Schema::table('team_conversation_user', function (Blueprint $table): void {
            $table->foreign('last_read_message_id')->references('id')->on('team_messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('team_conversation_user', function (Blueprint $table): void {
            $table->dropForeign(['last_read_message_id']);
        });
        Schema::dropIfExists('team_messages');
        Schema::dropIfExists('team_conversation_user');
        Schema::dropIfExists('team_conversations');
    }
};
