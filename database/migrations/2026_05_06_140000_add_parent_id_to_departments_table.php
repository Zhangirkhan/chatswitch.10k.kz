<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Иерархия отделов: добавляем parent_id (self-FK), чтобы разрешить вложенные отделы
 * ("Отдел продаж" → "B2B" → "Регион Алматы"). При удалении родителя дочерние отделы
 * не каскадно удаляются — становятся корневыми (SET NULL), чтобы случайное удаление
 * вершины дерева не уничтожило всю ветку с пользователями.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('description')
                ->constrained('departments')
                ->nullOnDelete();

            $table->index(['parent_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropIndex(['parent_id', 'name']);
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
