<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Подмножество этапов воронки, доступных конкретному отделу (или подотделу).
 * Должно быть согласовано с `department_funnel`: контроллер запрещает добавлять
 * этап, чья воронка не активирована для отдела.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_funnel_stage', function (Blueprint $table): void {
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('funnel_stage_id')->constrained('funnel_stages')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['department_id', 'funnel_stage_id']);
            $table->index('funnel_stage_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_funnel_stage');
    }
};
