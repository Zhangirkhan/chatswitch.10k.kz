<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('chat_id')->nullable()->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->boolean('won');
            $table->string('reason', 128)->nullable();
            $table->string('industry', 128)->nullable();
            $table->unsignedTinyInteger('lead_score')->nullable();
            $table->string('lead_grade', 2)->nullable();
            $table->json('sales_state_snapshot')->nullable();
            $table->text('objections_at_close')->nullable();
            $table->unsignedBigInteger('funnel_stage_id')->nullable();
            $table->string('source', 32)->default('auto_stage');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'won']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_outcomes');
    }
};
