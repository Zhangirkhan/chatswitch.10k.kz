<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_banners', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('message');
            $table->string('background_color', 7);
            $table->string('text_color', 7)->default('#fffbeb');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('targets', 16)->default('both');
            $table->integer('priority')->default(0);
            $table->boolean('is_published')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_published', 'starts_at', 'ends_at']);
            $table->index(['company_id', 'is_published']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_banners');
    }
};
