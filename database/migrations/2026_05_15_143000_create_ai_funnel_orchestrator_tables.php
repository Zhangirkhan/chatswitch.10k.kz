<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('funnel_ai_scenarios')) {
            Schema::create('funnel_ai_scenarios', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->foreignId('funnel_id')->unique()->constrained('funnels')->cascadeOnDelete();
                $table->boolean('enabled')->default(false);
                $table->string('customer_identity', 32)->default('company');
                $table->unsignedSmallInteger('booking_horizon_days')->default(30);
                $table->foreignId('fallback_manager_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('fallback_department_id')->nullable()->constrained('departments')->nullOnDelete();
                $table->boolean('manager_confirmation_required')->default(false);
                $table->timestamps();

                $table->index(['company_id', 'enabled']);
            });
        }

        if (! Schema::hasTable('funnel_stage_ai_rules')) {
            Schema::create('funnel_stage_ai_rules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->foreignId('funnel_id')->constrained('funnels')->cascadeOnDelete();
                $table->foreignId('funnel_stage_id')->unique()->constrained('funnel_stages')->cascadeOnDelete();
                $table->text('goal')->nullable();
                $table->json('required_questions')->nullable();
                $table->text('transition_conditions')->nullable();
                $table->json('allowed_actions')->nullable();
                $table->json('assignee_user_ids')->nullable();
                $table->foreignId('assignee_department_id')->nullable()->constrained('departments')->nullOnDelete();
                $table->boolean('require_manager_confirmation')->default(false);
                $table->timestamps();

                $table->index(['company_id', 'funnel_id']);
                $table->index(['assignee_department_id']);
            });
        }

        if (! Schema::hasTable('ai_orchestrator_runs')) {
            Schema::create('ai_orchestrator_runs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
                $table->foreignId('trigger_message_id')->nullable()->constrained('messages')->nullOnDelete();
                $table->foreignId('funnel_id')->nullable()->constrained('funnels')->nullOnDelete();
                $table->foreignId('funnel_stage_id')->nullable()->constrained('funnel_stages')->nullOnDelete();
                $table->string('status', 32)->default('pending');
                $table->decimal('confidence', 5, 4)->nullable();
                $table->string('reason', 500)->nullable();
                $table->json('context')->nullable();
                $table->json('plan')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->unique(['chat_id', 'trigger_message_id'], 'ai_orch_runs_chat_trigger_unique');
                $table->index(['company_id', 'status']);
                $table->index(['chat_id', 'created_at']);
            });
        }

        if (! Schema::hasColumn('chats', 'ai_orchestrator_status')) {
            Schema::table('chats', function (Blueprint $table): void {
                $table->string('ai_orchestrator_status', 32)->nullable()->after('funnel_ai_last_reason');
                $table->foreignId('ai_orchestrator_last_run_id')->nullable()->after('ai_orchestrator_status')->constrained('ai_orchestrator_runs')->nullOnDelete();
                $table->timestamp('ai_orchestrator_last_action_at')->nullable()->after('ai_orchestrator_last_run_id');
                $table->string('ai_orchestrator_last_summary', 500)->nullable()->after('ai_orchestrator_last_action_at');
            });
        }

        if (! Schema::hasTable('ai_orchestrator_actions')) {
            Schema::create('ai_orchestrator_actions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('ai_orchestrator_run_id')->constrained('ai_orchestrator_runs')->cascadeOnDelete();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
                $table->string('type', 48);
                $table->string('status', 32)->default('pending');
                $table->json('payload')->nullable();
                $table->json('result')->nullable();
                $table->text('error')->nullable();
                $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
                $table->foreignId('calendar_event_id')->nullable()->constrained('calendar_events')->nullOnDelete();
                $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('team_message_id')->nullable()->constrained('team_messages')->nullOnDelete();
                $table->timestamps();

                $table->index(['chat_id', 'type']);
                $table->index(['company_id', 'type', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_orchestrator_actions');
        if (Schema::hasColumn('chats', 'ai_orchestrator_last_run_id')) {
            Schema::table('chats', function (Blueprint $table): void {
                $table->dropForeign(['ai_orchestrator_last_run_id']);
                $table->dropColumn([
                    'ai_orchestrator_status',
                    'ai_orchestrator_last_run_id',
                    'ai_orchestrator_last_action_at',
                    'ai_orchestrator_last_summary',
                ]);
            });
        }
        Schema::dropIfExists('ai_orchestrator_runs');
        Schema::dropIfExists('funnel_stage_ai_rules');
        Schema::dropIfExists('funnel_ai_scenarios');
    }
};
