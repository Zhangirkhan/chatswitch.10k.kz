<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_field_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('label', 120);
            $table->string('type', 32);
            $table->string('section', 32)->default('contacts');
            $table->string('group', 32)->default('about');
            $table->boolean('is_system')->default(false);
            $table->boolean('is_visible')->default(true);
            $table->json('options')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'section', 'sort_order']);
        });

        Schema::create('contact_field_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_definition_id')->constrained('contact_field_definitions')->cascadeOnDelete();
            $table->text('value_text')->nullable();
            $table->json('value_json')->nullable();
            $table->timestamps();

            $table->unique(['contact_id', 'field_definition_id']);
            $table->index(['company_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_field_values');
        Schema::dropIfExists('contact_field_definitions');
    }
};
