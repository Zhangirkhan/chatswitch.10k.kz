<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Множественное членство в отделах. Раньше у пользователя был один `users.department_id`,
 * теперь — pivot `department_user` (м-к-м). Поле `users.department_id` ОСТАЁТСЯ как
 * денормализованный «основной отдел» — на нём строятся:
 *   • подпись оператора в шапке диалога (Без отдела / название);
 *   • дефолтный выбор в формах.
 * Фактическим источником «состоит ли в отделе X» теперь является pivot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_user', function (Blueprint $table): void {
            $table->foreignId('department_id')
                ->constrained('departments')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['department_id', 'user_id']);
            $table->index('user_id');
        });

        // Backfill: для каждого пользователя с не-null department_id создаём запись pivot.
        // Прогоняем через INSERT IGNORE-аналог на случай повторных миграций.
        DB::table('users')
            ->whereNotNull('department_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                $now = now();
                $payload = [];
                foreach ($rows as $u) {
                    $payload[] = [
                        'department_id' => (int) $u->department_id,
                        'user_id' => (int) $u->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($payload !== []) {
                    DB::table('department_user')->insertOrIgnore($payload);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_user');
    }
};
