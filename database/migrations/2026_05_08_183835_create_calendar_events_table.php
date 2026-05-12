<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Записи календаря.
 *
 * Повторение хранится как простое правило (`recurrence`) + дата окончания серии
 * (`recurrence_ends_at`). Конкретные экземпляры серии раскрываются в контроллере
 * на лету для запрошенного диапазона дат.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // HEX-цвет события для отображения в календаре
            $table->string('color', 16)->default('#25d366');

            // Временные рамки — всегда в UTC
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            // Флаг «весь день» — если true, время в starts_at/ends_at игнорируется
            $table->boolean('all_day')->default(false);

            // Правило повторения: null = без повторения
            $table->enum('recurrence', ['daily', 'weekly', 'monthly', 'yearly'])->nullable();

            // Когда серия заканчивается (null = бесконечно, но UI ограничивает 2 года)
            $table->date('recurrence_ends_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'starts_at']);
            $table->index('starts_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
