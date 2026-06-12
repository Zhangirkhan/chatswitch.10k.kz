<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_experiments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('slug', 64);
            $table->string('name');
            $table->string('target', 32)->default('ai_reply');
            $table->string('status', 16)->default('active');
            $table->unsignedTinyInteger('traffic_percent')->default(100);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'slug']);
        });

        Schema::create('ai_experiment_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('experiment_id')->constrained('ai_experiments')->cascadeOnDelete();
            $table->string('key', 8);
            $table->json('config')->nullable();
            $table->boolean('is_control')->default(false);
            $table->timestamps();

            $table->unique(['experiment_id', 'key']);
        });

        Schema::create('ai_experiment_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('experiment_id')->constrained('ai_experiments')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('ai_experiment_variants')->cascadeOnDelete();
            $table->unsignedBigInteger('chat_id')->index();
            $table->timestamp('assigned_at');
            $table->timestamps();

            $table->unique(['experiment_id', 'chat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_experiment_assignments');
        Schema::dropIfExists('ai_experiment_variants');
        Schema::dropIfExists('ai_experiments');
    }
};
