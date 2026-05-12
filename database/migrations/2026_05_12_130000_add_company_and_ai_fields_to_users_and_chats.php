<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('company_id')
                ->nullable()
                ->after('department_id')
                ->constrained()
                ->nullOnDelete();
        });

        Schema::table('chats', function (Blueprint $table): void {
            $table->foreignId('company_id')
                ->nullable()
                ->after('contact_id')
                ->constrained()
                ->nullOnDelete();
            $table->boolean('ai_enabled')->default(false)->after('is_favorite');
            $table->string('ai_mode', 20)->default('draft')->after('ai_enabled');
            $table->foreignId('ai_responder_user_id')
                ->nullable()
                ->after('ai_mode')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['company_id', 'ai_enabled']);
            $table->index(['ai_responder_user_id', 'ai_enabled']);
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'ai_enabled']);
            $table->dropIndex(['ai_responder_user_id', 'ai_enabled']);
            $table->dropConstrainedForeignId('ai_responder_user_id');
            $table->dropColumn(['ai_mode', 'ai_enabled']);
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
