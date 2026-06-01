<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_promotions')) {
            Schema::create('company_promotions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->string('name', 160);
                $table->string('discount_type', 16)->default('percent');
                $table->unsignedTinyInteger('percent')->nullable();
                $table->decimal('fixed_amount', 12, 2)->nullable();
                $table->date('valid_from')->nullable();
                $table->date('valid_until')->nullable();
                $table->text('conditions')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['company_id', 'is_active', 'sort_order']);
            });
        }

        Schema::table('funnel_stage_ai_rules', function (Blueprint $table): void {
            if (! Schema::hasColumn('funnel_stage_ai_rules', 'follow_up_promotion_ids')) {
                $table->json('follow_up_promotion_ids')->nullable()->after('follow_up_allowed_promos');
            }
        });
    }

    public function down(): void
    {
        Schema::table('funnel_stage_ai_rules', function (Blueprint $table): void {
            if (Schema::hasColumn('funnel_stage_ai_rules', 'follow_up_promotion_ids')) {
                $table->dropColumn('follow_up_promotion_ids');
            }
        });

        Schema::dropIfExists('company_promotions');
    }
};
