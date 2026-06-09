<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('chats', 'ai_enabled')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE chats MODIFY ai_enabled TINYINT(1) NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('chats', 'ai_enabled')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE chats MODIFY ai_enabled TINYINT(1) NOT NULL DEFAULT 1');
        }
    }
};
