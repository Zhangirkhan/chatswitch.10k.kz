<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_feedback', function (Blueprint $table): void {
            $table->unsignedInteger('likes_count')->default(0)->after('status');
            $table->boolean('is_diagnostic')->default(false)->after('likes_count');

            $table->index('likes_count');
            $table->index(['is_diagnostic', 'likes_count']);
        });

        Schema::create('user_feedback_likes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_feedback_id')->constrained('user_feedback')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_feedback_id', 'user_id']);
            $table->index('user_feedback_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_feedback_likes');

        Schema::table('user_feedback', function (Blueprint $table): void {
            $table->dropIndex(['is_diagnostic', 'likes_count']);
            $table->dropIndex(['likes_count']);
            $table->dropColumn(['likes_count', 'is_diagnostic']);
        });
    }
};
