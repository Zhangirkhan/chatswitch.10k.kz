<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locale_few_shot_examples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->text('user_text');
            $table->text('assistant_text');
            $table->json('language_profile')->nullable();
            $table->string('formality', 16)->nullable();
            $table->json('tags')->nullable();
            $table->json('embedding')->nullable();
            $table->string('source', 64)->default('import');
            $table->unsignedTinyInteger('quality_score')->default(80);
            $table->timestamps();

            $table->index(['company_id', 'formality']);
        });

        Schema::create('locale_phrase_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phrase', 500);
            $table->text('meaning_ru')->nullable();
            $table->text('usage_hint')->nullable();
            $table->json('language_tags')->nullable();
            $table->string('source', 64)->default('import');
            $table->json('embedding')->nullable();
            $table->char('content_hash', 64);
            $table->timestamps();

            $table->unique(['company_id', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locale_phrase_chunks');
        Schema::dropIfExists('locale_few_shot_examples');
    }
};
