<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('win_probability_models', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedInteger('version')->default(1);
            $table->string('algorithm', 32)->default('logistic_regression');
            $table->json('coefficients');
            $table->json('feature_schema');
            $table->unsignedInteger('training_samples')->default(0);
            $table->json('metrics')->nullable();
            $table->timestamp('trained_at')->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->timestamps();

            $table->unique(['company_id', 'version']);
        });

        Schema::table('win_probability_scores', function (Blueprint $table): void {
            $table->unsignedBigInteger('deal_outcome_id')->nullable()->after('chat_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('win_probability_scores', function (Blueprint $table): void {
            $table->dropColumn('deal_outcome_id');
        });

        Schema::dropIfExists('win_probability_models');
    }
};
