<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_tags', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('contact_id')->index();
            $table->string('name', 128)->index();
            $table->string('source', 64)->default('manual')
                ->comment('manual | ai | import');
            $table->timestamps();

            $table->unique(['company_id', 'contact_id', 'name']);

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
        });

        // Add AI-specific writeback columns to contacts.
        Schema::table('contacts', static function (Blueprint $table): void {
            $table->unsignedBigInteger('ai_funnel_stage_id')->nullable()->index()
                ->comment('Last funnel stage AI placed this contact into (any chat)');
            $table->timestamp('ai_enriched_at')->nullable()
                ->comment('When AI last wrote enrichment data to this contact');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', static function (Blueprint $table): void {
            $table->dropColumn(['ai_funnel_stage_id', 'ai_enriched_at']);
        });

        Schema::dropIfExists('contact_tags');
    }
};
