<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_post_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['department_post_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_post_comments');
    }
};
