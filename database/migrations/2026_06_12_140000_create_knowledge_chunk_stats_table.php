<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_chunk_stats', function (Blueprint $table): void {
            $table->unsignedBigInteger('chunk_id')->primary();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedInteger('retrieval_count')->default(0);
            $table->unsignedInteger('reply_count')->default(0);
            $table->unsignedInteger('won_after_use')->default(0);
            $table->unsignedInteger('lost_after_use')->default(0);
            $table->unsignedInteger('manager_override_count')->default(0);
            $table->timestamp('last_retrieved_at')->nullable();
            $table->decimal('quality_score', 5, 2)->default(0);
            $table->timestamp('computed_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('knowledge_chunks', function (Blueprint $table): void {
            $table->decimal('quality_score', 5, 2)->nullable()->after('embedding');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_chunks', function (Blueprint $table): void {
            $table->dropColumn('quality_score');
        });

        Schema::dropIfExists('knowledge_chunk_stats');
    }
};
