<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Привязка отдела (или подотдела) к воронке продаж: «отдел использует эту воронку».
 * Конкретные этапы хранятся отдельно в `department_funnel_stage` — это позволяет
 * различать «воронка подключена, этапы пока не выбраны» и «воронка не подключена».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_funnel', function (Blueprint $table): void {
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('funnel_id')->constrained('funnels')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['department_id', 'funnel_id']);
            $table->index('funnel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_funnel');
    }
};
