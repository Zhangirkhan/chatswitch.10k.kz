<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ответственные за задачу. Один пост может иметь нескольких ответственных,
 * один пользователь может быть ответственным за несколько постов.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_assignees', function (Blueprint $table): void {
            $table->foreignId('department_post_id')
                ->constrained('department_posts')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->primary(['department_post_id', 'user_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_assignees');
    }
};
