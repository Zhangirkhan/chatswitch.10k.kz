<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Воронки продаж. Сама воронка — это «контейнер» для упорядоченных этапов
 * (см. funnel_stages), по которым перемещаются клиенты/диалоги. На этом этапе
 * привязок к чатам/контактам не делаем — только справочник.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funnels', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            // HEX-цвет для бейджа в списках. Без жёсткой валидации формата —
            // фронт сам ограничит выбор палитрой.
            $table->string('color', 16)->default('#25d366');
            $table->boolean('is_active')->default(true);
            // Позиция для упорядочивания в списке — заполняется на стороне приложения.
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index('position');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funnels');
    }
};
