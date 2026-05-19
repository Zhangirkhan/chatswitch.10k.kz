<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_memories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_company_id');
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->longText('content')->default('');
            $table->char('content_hash', 64)->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_company_id', 'subject_type', 'subject_id'], 'entity_memories_subject_unique');
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('entity_memory_backups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entity_memory_id')->constrained('entity_memories')->cascadeOnDelete();
            $table->longText('content');
            $table->char('content_hash', 64)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_memory_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_memory_backups');
        Schema::dropIfExists('entity_memories');
    }
};
