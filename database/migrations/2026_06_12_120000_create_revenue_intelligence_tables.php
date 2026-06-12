<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_milestones', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('chat_id')->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->string('milestone', 40)->index();
            $table->string('source', 32);
            $table->unsignedBigInteger('trigger_message_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['company_id', 'milestone', 'occurred_at']);
            $table->index(['chat_id', 'occurred_at']);
        });

        Schema::create('follow_up_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scheduled_message_id')->index();
            $table->unsignedBigInteger('chat_id')->index();
            $table->timestamp('responded_at')->nullable();
            $table->boolean('recovered_to_qualified')->default(false);
            $table->unsignedBigInteger('deal_outcome_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('win_probability_scores', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('chat_id')->index();
            $table->unsignedTinyInteger('probability');
            $table->json('risk_factors')->nullable();
            $table->string('recommended_action', 64)->nullable();
            $table->json('inputs_snapshot')->nullable();
            $table->timestamp('computed_at')->index();
            $table->timestamps();

            $table->index(['chat_id', 'computed_at']);
        });

        Schema::create('knowledge_retrieval_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('ai_response_log_id')->nullable()->index();
            $table->unsignedBigInteger('chunk_id')->index();
            $table->decimal('similarity', 6, 4)->nullable();
            $table->string('domain', 32)->nullable();
            $table->timestamps();

            $table->index(['chunk_id', 'created_at']);
        });

        Schema::create('conversation_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('chat_id')->index();
            $table->unsignedBigInteger('trigger_message_id')->nullable();
            $table->unsignedTinyInteger('sales_score')->nullable();
            $table->string('conversation_quality', 16)->nullable();
            $table->json('missed_questions')->nullable();
            $table->json('missed_opportunities')->nullable();
            $table->string('qualification_quality', 16)->nullable();
            $table->string('risk_level', 16)->nullable();
            $table->json('raw_response')->nullable();
            $table->string('model', 64)->nullable();
            $table->timestamps();

            $table->index(['chat_id', 'created_at']);
        });

        Schema::create('sales_playbooks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('slug', 64);
            $table->string('name', 128);
            $table->json('industry_tags')->nullable();
            $table->json('qualification_fields')->nullable();
            $table->json('stage_strategies')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'slug']);
        });

        Schema::create('sales_playbook_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_playbook_id')->constrained('sales_playbooks')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('step_key', 64);
            $table->text('prompt_hint')->nullable();
            $table->json('required_before_next')->nullable();
            $table->timestamps();

            $table->index(['sales_playbook_id', 'position']);
        });

        Schema::create('objection_clusters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('label', 64);
            $table->unsignedInteger('frequency')->default(0);
            $table->decimal('win_rate_after_handling', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'label']);
        });

        Schema::create('objection_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('objection_cluster_id')->constrained('objection_clusters')->cascadeOnDelete();
            $table->text('response_text');
            $table->unsignedInteger('usage_count')->default(0);
            $table->unsignedInteger('win_count')->default(0);
            $table->unsignedInteger('loss_count')->default(0);
            $table->string('source', 32)->default('ai');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('objection_responses');
        Schema::dropIfExists('objection_clusters');
        Schema::dropIfExists('sales_playbook_steps');
        Schema::dropIfExists('sales_playbooks');
        Schema::dropIfExists('conversation_audits');
        Schema::dropIfExists('knowledge_retrieval_logs');
        Schema::dropIfExists('win_probability_scores');
        Schema::dropIfExists('follow_up_outcomes');
        Schema::dropIfExists('sales_milestones');
    }
};
