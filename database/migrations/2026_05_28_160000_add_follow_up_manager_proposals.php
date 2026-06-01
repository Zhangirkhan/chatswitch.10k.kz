<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funnel_stage_ai_rules', function (Blueprint $table): void {
            if (! Schema::hasColumn('funnel_stage_ai_rules', 'follow_up_strategy')) {
                $table->string('follow_up_strategy', 32)->default('off')->after('follow_up_max_count');
            }
            if (! Schema::hasColumn('funnel_stage_ai_rules', 'follow_up_silence_after')) {
                $table->string('follow_up_silence_after', 16)->default('outbound')->after('follow_up_strategy');
            }
            if (! Schema::hasColumn('funnel_stage_ai_rules', 'follow_up_allowed_promos')) {
                $table->json('follow_up_allowed_promos')->nullable()->after('follow_up_silence_after');
            }
        });

        if (! Schema::hasTable('ai_follow_up_proposals')) {
            Schema::create('ai_follow_up_proposals', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
                $table->foreignId('funnel_id')->nullable()->constrained('funnels')->nullOnDelete();
                $table->foreignId('funnel_stage_id')->nullable()->constrained('funnel_stages')->nullOnDelete();
                $table->foreignId('trigger_message_id')->nullable()->constrained('messages')->nullOnDelete();
                $table->string('status', 32)->default('pending');
                $table->json('proposals')->nullable();
                $table->string('recommended_id', 32)->nullable();
                $table->text('manager_note')->nullable();
                $table->text('context_summary')->nullable();
                $table->string('selected_variant_id', 32)->nullable();
                $table->foreignId('sent_message_id')->nullable()->constrained('messages')->nullOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('error')->nullable();
                $table->timestamp('dismissed_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->index(['chat_id', 'status']);
                $table->index(['company_id', 'status', 'created_at']);
                $table->index(['funnel_stage_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_follow_up_proposals');

        Schema::table('funnel_stage_ai_rules', function (Blueprint $table): void {
            foreach (['follow_up_allowed_promos', 'follow_up_silence_after', 'follow_up_strategy'] as $column) {
                if (Schema::hasColumn('funnel_stage_ai_rules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
