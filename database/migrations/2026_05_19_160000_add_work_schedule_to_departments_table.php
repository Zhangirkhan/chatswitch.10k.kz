<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->boolean('work_schedule_enabled')->default(false)->after('is_active');
            $table->string('work_schedule_timezone', 64)->nullable()->after('work_schedule_enabled');
            $table->json('work_schedule')->nullable()->after('work_schedule_timezone');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn([
                'work_schedule_enabled',
                'work_schedule_timezone',
                'work_schedule',
            ]);
        });
    }
};
