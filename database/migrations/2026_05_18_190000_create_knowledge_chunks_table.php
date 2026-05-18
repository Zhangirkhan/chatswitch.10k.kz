<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 16);
            $table->unsignedBigInteger('source_id');
            $table->text('content_text');
            $table->text('display_line');
            $table->char('content_hash', 64);
            $table->json('embedding')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id']);
            $table->index(['company_id', 'source_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
