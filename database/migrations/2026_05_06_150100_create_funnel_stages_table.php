<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Этапы воронки. Создаются вручную; порядок задаёт `position` (целое, asc).
 * При удалении воронки этапы удаляются каскадно. Денормализованного «колонки»/
 * количества карточек не храним — это пока чистый справочник.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funnel_stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('funnel_id')
                ->constrained('funnels')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 16)->default('#9ca3af');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['funnel_id', 'position']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funnel_stages');
    }
};
