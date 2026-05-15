<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('chats', 'funnel_id')) {
            Schema::table('chats', function (Blueprint $table): void {
                $table->foreignId('funnel_id')->nullable()->after('ai_responder_user_id')->constrained('funnels')->nullOnDelete();
                $table->foreignId('funnel_stage_id')->nullable()->after('funnel_id')->constrained('funnel_stages')->nullOnDelete();
                $table->boolean('funnel_tracking_enabled')->default(true)->after('funnel_stage_id');
                $table->boolean('funnel_stage_locked')->default(false)->after('funnel_tracking_enabled');
                $table->timestamp('funnel_ai_last_analyzed_at')->nullable()->after('funnel_stage_locked');
                $table->unsignedBigInteger('funnel_ai_last_message_id')->nullable()->after('funnel_ai_last_analyzed_at');
                $table->string('funnel_ai_last_reason', 500)->nullable()->after('funnel_ai_last_message_id');

                $table->index(['funnel_id', 'funnel_stage_id']);
            });
        }

        if (! Schema::hasTable('chat_funnel_transitions')) {
            Schema::create('chat_funnel_transitions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->foreignId('from_funnel_id')->nullable()->constrained('funnels')->nullOnDelete();
                $table->foreignId('from_stage_id')->nullable()->constrained('funnel_stages')->nullOnDelete();
                $table->foreignId('to_funnel_id')->nullable()->constrained('funnels')->nullOnDelete();
                $table->foreignId('to_stage_id')->nullable()->constrained('funnel_stages')->nullOnDelete();
                $table->string('source', 16);
                $table->decimal('confidence', 5, 4)->nullable();
                $table->string('reason', 500)->nullable();
                $table->foreignId('trigger_message_id')->nullable()->constrained('messages')->nullOnDelete();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['chat_id', 'created_at']);
                $table->index(['company_id', 'to_funnel_id', 'to_stage_id'], 'chat_funnel_trans_co_fu_st_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_funnel_transitions');

        if (Schema::hasColumn('chats', 'funnel_id')) {
            Schema::table('chats', function (Blueprint $table): void {
                $table->dropForeign(['funnel_id']);
                $table->dropForeign(['funnel_stage_id']);
                $table->dropColumn([
                    'funnel_id',
                    'funnel_stage_id',
                    'funnel_tracking_enabled',
                    'funnel_stage_locked',
                    'funnel_ai_last_analyzed_at',
                    'funnel_ai_last_message_id',
                    'funnel_ai_last_reason',
                ]);
            });
        }
    }
};
